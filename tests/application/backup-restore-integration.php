<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Backup\DatabaseBackupService;
use MuchoCore\Backup\DatabaseBackupVerifier;

function backupCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

$host = getenv('TEST_DB_HOST');
$port = getenv('TEST_DB_PORT');
$password = getenv('TEST_DB_ROOT_PASSWORD');

if ($host !== '127.0.0.1' || $port !== '3306' || !is_string($password) || $password === '') {
    throw new RuntimeException('Backup restore test requires the isolated CI MariaDB.');
}

$suffix = bin2hex(random_bytes(5));
$sourceName = 'mc_backup_src_' . $suffix;
$targetName = 'mc_backup_dst_' . $suffix;
$dir = sys_get_temp_dir() . '/mc-restore-' . $suffix;
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Failed to create isolated backup test directory.');
}

$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
        ($db === null ? '' : ';dbname=' . $db) . ';charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$server = $connect(null);

try {
    $server->exec('CREATE DATABASE ' . $sourceName . ' CHARACTER SET utf8mb4');
    $server->exec('CREATE DATABASE ' . $targetName . ' CHARACTER SET utf8mb4');
    $source = $connect($sourceName);

    $source->exec(<<<'SQL'
CREATE TABLE restore_items (
    id INT NOT NULL PRIMARY KEY,
    user_text VARCHAR(255) NOT NULL,
    payload BLOB NOT NULL,
    optional_value VARCHAR(128) NULL,
    number_value INT NOT NULL,
    doubled INT GENERATED ALWAYS AS (number_value * 2) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    $source->exec(<<<'SQL'
CREATE TABLE restore_links (
    id INT NOT NULL PRIMARY KEY,
    item_id INT NOT NULL,
    CONSTRAINT fk_restore_item FOREIGN KEY (item_id)
        REFERENCES restore_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    $source->exec(
        'CREATE VIEW restore_items_view AS ' .
        'SELECT id, number_value, doubled FROM restore_items'
    );

    $text = 'Привет, Geometry Dash 🤖!';
    $binary = "\0\x01\xfe\xff; ' \\ backup bytes \0";
    $stmt = $source->prepare(
        'INSERT INTO restore_items ' .
        '(id,user_text,payload,optional_value,number_value) ' .
        'VALUES (1,:text,:payload,NULL,21)'
    );
    $stmt->bindValue(':text', $text);
    $stmt->bindValue(':payload', $binary, PDO::PARAM_LOB);
    $stmt->execute();
    $source->exec('INSERT INTO restore_links (id,item_id) VALUES (10,1)');

    $backup = (new DatabaseBackupService($source, $dir))
        ->create('roundtrip');

    $verified = DatabaseBackupVerifier::verify($backup['file']);
    backupCheck(
        hash_equals($backup['sha256'], $verified['sha256']) &&
        $verified['sql_bytes'] > 100,
        'SQL archive and SHA-256 sidecar pass full-stream verification'
    );

    $tampered = $dir . '/tampered.sql.gz';
    copy($backup['file'], $tampered);
    file_put_contents($tampered, 'x', FILE_APPEND);
    file_put_contents(
        $tampered . '.sha256',
        $backup['sha256'] . '  ' . basename($tampered) . PHP_EOL
    );
    $rejected = false;
    try {
        DatabaseBackupVerifier::verify($tampered);
    } catch (RuntimeException) {
        $rejected = true;
    }
    backupCheck($rejected, 'modified backup fails checksum verification');

    $invalid = $dir . '/invalid.sql.gz';
    file_put_contents($invalid, str_repeat("\0", 256));
    file_put_contents(
        $invalid . '.sha256',
        hash_file('sha256', $invalid) . '  ' . basename($invalid) . PHP_EOL
    );
    $rejected = false;
    try {
        DatabaseBackupVerifier::verify($invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    backupCheck($rejected, 'non-gzip content rejected despite a matching hash');

    $cnfPath = $dir . '/mysql.cnf';
    $quotedPassword = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $password) . '"';
    file_put_contents($cnfPath, "[client]\nuser=root\npassword={$quotedPassword}\n");
    chmod($cnfPath, 0600);

    // Restoration deliberately targets only this run's freshly created DB.
    $proc = proc_open(
        [
            'mariadb',
            '--defaults-extra-file=' . $cnfPath,
            '--host=' . $host,
            '--port=' . $port,
            '--default-character-set=utf8mb4',
            $targetName,
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($proc)) {
        throw new RuntimeException('Cannot run isolated MariaDB restore client.');
    }

    $gzip = gzopen($backup['file'], 'rb');
    if ($gzip === false) {
        throw new RuntimeException('Verified backup could not be reopened.');
    }

    try {
        while (!gzeof($gzip)) {
            $chunk = gzread($gzip, 65536);
            if (!is_string($chunk)) {
                throw new RuntimeException('Backup stream unexpectedly failed.');
            }
            while ($chunk !== '') {
                $written = fwrite($pipes[0], $chunk);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('MariaDB restore stdin closed early.');
                }
                $chunk = substr($chunk, $written);
            }
        }
    } finally {
        gzclose($gzip);
        fclose($pipes[0]);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    backupCheck(
        $exitCode === 0,
        'MariaDB restore command succeeded: ' .
            trim((string)$stdout . ' ' . (string)$stderr)
    );

    $target = $connect($targetName);
    $row = $target->query(
        'SELECT user_text,payload,optional_value,number_value,doubled ' .
        'FROM restore_items WHERE id=1'
    )->fetch();

    backupCheck(
        is_array($row) &&
        $row['user_text'] === $text &&
        $row['payload'] === $binary &&
        $row['optional_value'] === null &&
        (int)$row['number_value'] === 21 &&
        (int)$row['doubled'] === 42,
        'UTF-8, binary, NULL and generated columns survive restore'
    );
    backupCheck(
        (int)$target->query('SELECT COUNT(*) FROM restore_links WHERE item_id=1')
            ->fetchColumn() === 1,
        'foreign-key data survives restore'
    );
    // A valid disaster-recovery restore must not silently reference tables
    // in its original database. Remove the source before validating the view.
    $server->exec('DROP DATABASE ' . $sourceName);
    backupCheck(
        (int)$target->query(
            'SELECT doubled FROM restore_items_view WHERE id=1'
        )->fetchColumn() === 42,
        'restored view is independent of the destroyed source database'
    );

    echo "MUCHOCORE_BACKUP_RESTORE_INTEGRATION_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $targetName);
    $server->exec('DROP DATABASE IF EXISTS ' . $sourceName);
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}
