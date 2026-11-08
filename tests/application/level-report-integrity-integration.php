<?php

declare(strict_types=1);

/** Real MariaDB integration for public level report persistence. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Moderation\ModerationRepository;

if (getenv('TEST_DB_HOST') !== '127.0.0.1' ||
    !is_string(getenv('TEST_DB_ROOT_PASSWORD')) ||
    getenv('TEST_DB_ROOT_PASSWORD') === '') {
    throw new RuntimeException('Use isolated CI MariaDB only.');
}

$name = 'mc_reports_it_' . bin2hex(random_bytes(5));
$port = getenv('TEST_DB_PORT') ?: '3306';
$password = (string)getenv('TEST_DB_ROOT_PASSWORD');
$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$server = new PDO(
    'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
    'root', $password, $opts
);
$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('Level report: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = new PDO(
        'mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
        'root', $password, $opts
    );

    foreach (['001_auth.php', '003_levels.php', '20261108_002_level_reports.php']
        as $migration) {
        foreach (require dirname(__DIR__, 2) . '/database/migrations/' . $migration as $sql) {
            $db->exec($sql);
        }
    }

    $db->exec("INSERT INTO accounts
        (username,email,password_hash,role_id)
        VALUES ('Reporter','reporter@example.test','not-a-real-password',1)");
    $db->exec("INSERT INTO levels(account_id,name,description,level_data)
        VALUES (1,'A','', 'kS1,1,2;'), (1,'B','', 'kS1,1,3;')");

    $repository = new ModerationRepository($db);
    $first = $repository->reportLevel(1, hash('sha256', 'reporter-1'));
    $check($first > 0, 'anonymous player can submit valid level report');
    $check($repository->reportLevel(1, hash('sha256', 'reporter-1')) === 0,
        'duplicate report is rejected without another row');
    $second = $repository->reportLevel(1, hash('sha256', 'reporter-2'));
    $check($second > $first, 'another reporter can report the same level');
    $third = $repository->reportLevel(2, hash('sha256', 'reporter-1'));
    $check($third > $second, 'one reporter can report different levels');
    $check((int)$db->query('SELECT COUNT(*) FROM mucho_level_reports')->fetchColumn() === 3,
        'three unique level+reporter combinations are stored');
    $check($repository->reportLevel(999999, hash('sha256', 'reporter-3')) === 0,
        'missing level cannot be reported');

    $db->exec('UPDATE levels SET is_deleted=1 WHERE level_id=1');
    $check($repository->reportLevel(1, hash('sha256', 'reporter-3')) === 0,
        'deleted level cannot be reported');
    $db->exec('DELETE FROM levels WHERE level_id=1');
    $check((int)$db->query('SELECT COUNT(*) FROM mucho_level_reports')->fetchColumn() === 1,
        'hard deletion cascades stale level reports');

    echo "MUCHOCORE_LEVEL_REPORT_INTEGRITY_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
