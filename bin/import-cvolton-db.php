<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Migration\CvoltonDatabaseImporter;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', [
    'source-host:',
    'source-port::',
    'source-db:',
    'source-user:',
    'source-pass::',
    'apply',
    'confirm:',
    'json',
    'help',
]);

if (isset($options['help']) || !isset(
    $options['source-host'],
    $options['source-db'],
    $options['source-user']
)) {
    fwrite(STDOUT, <<<TXT
MuchoCore Cvolton Database Migration Wizard

Required:
  --source-host=HOST
  --source-db=DATABASE
  --source-user=USER

Optional:
  --source-port=3306
  --source-pass=PASSWORD
  Set CVOLTON_SOURCE_PASS instead of passing a password on the shell.

Dry-run is the default:
  php bin/import-cvolton-db.php --source-host=127.0.0.1 --source-db=geometrydash --source-user=root

Apply:
  php bin/import-cvolton-db.php     --source-host=127.0.0.1     --source-db=geometrydash     --source-user=root     --apply     --confirm=COVOLTON

TXT);
    exit(isset($options['help']) ? 0 : 1);
}

$host = (string)$options['source-host'];
$port = (string)($options['source-port'] ?? '3306');
$dbName = (string)$options['source-db'];
$user = (string)$options['source-user'];

if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $dbName)) {
    fwrite(STDERR, "ERROR: invalid source database name. Use letters, numbers, _ or - only.\n");
    exit(2);
}

if ($user === '' || strlen($user) > 128) {
    fwrite(STDERR, "ERROR: invalid source database user.\n");
    exit(2);
}

$pass = array_key_exists('source-pass', $options)
    ? (string)$options['source-pass']
    : (string)(getenv('CVOLTON_SOURCE_PASS') ?: '');

if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
    fwrite(STDERR, "ERROR: invalid source host.
");
    exit(2);
}

if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
    fwrite(STDERR, "ERROR: invalid source port.
");
    exit(2);
}

try {
    $source = new PDO(
        'mysql:host=' . $host .
        ';port=' . (int)$port .
        ';dbname=' . $dbName .
        ';charset=utf8mb4',
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                'SET SESSION TRANSACTION READ ONLY',
        ]
    );

    $target = (new Database())->connection();
    $importer = new CvoltonDatabaseImporter($target);
    $preflight = $importer->preflight($source);

    if (isset($options['json'])) {
        echo json_encode(
            ['mode' => 'dry-run', 'preflight' => $preflight],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    } else {
        echo "MuchoCore Cvolton Database Migration Wizard
";
        echo "============================================
";
        foreach ($preflight as $table => $count) {
            echo sprintf("  %-12s %10d rows
", $table, $count);
        }
    }

    if (!isset($options['apply'])) {
        echo isset($options['json'])
            ? ''
            : "
DRY-RUN complete. No destination data was modified.
";
        exit(0);
    }

    if (($options['confirm'] ?? '') !== 'COVOLTON') {
        fwrite(
            STDERR,
            "ERROR: apply mode requires --confirm=COVOLTON.
"
        );
        exit(4);
    }

    $target->beginTransaction();

    try {
        $stats = $importer->apply($source);
        $target->commit();
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $e;
    }

    if (isset($options['json'])) {
        echo json_encode(
            ['mode' => 'apply', 'stats' => $stats],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    } else {
        echo "
IMPORT COMPLETE
";
        foreach ($stats as $name => $value) {
            echo sprintf("  %-30s %d
", $name, $value);
        }
        echo "
Source database was used read-only.
";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "
");
    exit(10);
}
