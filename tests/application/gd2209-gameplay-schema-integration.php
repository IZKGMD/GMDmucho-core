<?php

declare(strict_types=1);

/**
 * Regression: a clean MariaDB MuchoCore install must have DB backing for
 * real GD 2.209 Daily/Weekly/Event, Quests and Rewards routes.
 * Only operates on a disposable isolated 127.0.0.1 CI database.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Compatibility\DiscoveryController;
use MuchoCore\Http\Request;
use MuchoCore\Interaction\RewardsRepository;
use MuchoCore\Interaction\RewardsService;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '' ||
    !ctype_digit((string)$port)) {
    throw new RuntimeException('Requires disposable local CI MariaDB.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=127.0.0.1;port=' . $port .
        ($db === null ? '' : ';dbname=' . $db) . ';charset=utf8mb4',
    'root', $password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false]
);
$verify = static function (bool $condition, string $why): void {
    if (!$condition) { throw new RuntimeException('Game features: ' . $why); }
};
$name = 'mc_gamefeatures_' . bin2hex(random_bytes(5));
$admin = $connect(null);
try {
    $admin->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    foreach (['001_auth.php', '002_profiles.php', '003_levels.php'] as $base) {
        foreach (require dirname(__DIR__, 2) . '/database/migrations/' . $base as $sql) {
            $db->exec($sql);
        }
    }
    $migration = require dirname(__DIR__, 2) .
        '/database/migrations/20261020_001_gameplay_rotations_rewards.php';
    $migration($db);
    $pool = new RewardsRepository($db);
    $quests = $pool->challenges();
    $verify(count($quests) >= 3, 'new schema must include three genuine challenges');
    foreach ($quests as $quest) {
        $verify((int)$quest['amount'] > 0 && (int)$quest['reward'] > 0,
            'quest amounts and rewards must not be placeholders');
    }

    // Running the migration again must not duplicate or replace the quests.
    $migration($db);
    $verify($pool->challenges() === $quests, 'repeated migration preserves quest rows');

    $db->exec("INSERT INTO accounts (account_id, username, email, password_hash)
       VALUES (7, 'Fixture', 'fixture@example.invalid', 'test-hash')");
    $db->exec('INSERT INTO profiles (account_id) VALUES (7)');
    $state = $pool->state(7);
    $verify((int)$state['account_id'] === 7 &&
        (int)$state['small_count'] === 0 && (int)$state['big_count'] === 0,
        'native Rewards loads fresh player state');
    $update = $db->prepare(
        'UPDATE mucho_reward_state SET small_count=3 WHERE account_id=7'
    );
    $update->execute();
    $migration($db);
    $verify((int)$pool->state(7)['small_count'] === 3,
        'migration must never wipe existing player rewards');

    $service = new RewardsService($pool, new AccountAuthenticator($db));
    $encodedQuests = $service->challenges(0, 'test-device', '', '');
    $verify(str_starts_with($encodedQuests, 'SaKuJ') &&
        str_contains($encodedQuests, '|'),
        'native Quests handler must return non-error encoded payload');
    $encodedRewards = $service->rewards(0, 'test-device', '', '', 0);
    $verify(str_starts_with($encodedRewards, 'SaKuJ'),
        'native Rewards handler must return non-error encoded payload');

    $request = new Request('POST', '/getGJDailyLevel', [], [], []);
    $controller = new DiscoveryController($db);
    foreach (['daily', 'weekly', 'event'] as $type => $kind) {
        $_POST['type'] = (string)$type;
        $verify($controller->daily($request)->body === '-1',
            'empty ' . $kind . ' rotation must fail honestly');
    }
    $db->exec("INSERT INTO levels
        (level_id, account_id, name, level_data)
        VALUES (101,7,'Native daily fixture','kS1,1,2;')");
    $insert = $db->prepare(
        'INSERT INTO mucho_daily_rotation
            (kind,level_id,enabled,starts_at,ends_at)
         VALUES (?,101,1,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR),
                      DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
    );
    foreach (['daily', 'weekly', 'event'] as $kind) {
        $insert->execute([$kind]);
    }
    foreach (['daily', 'weekly', 'event'] as $type => $kind) {
        $_POST['type'] = (string)$type;
        $response = $controller->daily($request)->body;
        $verify(preg_match('/^[0-9]+\|[0-9]+$/D', $response) === 1,
            $kind . ' must produce native index and seconds remaining');
        $values = explode('|', $response);
        $verify((int)$values[1] > 0, $kind . ' requires positive remaining time');
    }
    unset($_POST['type']);
    echo "MUCHOCORE_GD_2209_GAMEPLAY_SCHEMA_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $name);
}
