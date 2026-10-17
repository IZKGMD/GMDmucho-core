<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Social\FollowingService;
use MuchoCore\Social\RelationshipRepository;
use MuchoCore\Social\RelationshipService;
use MuchoCore\Social\NotificationService;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Refusing GD 2.209 integration test outside isolated CI MariaDB.');
}

$connect = static fn(?string $database): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($database === null ? '' : ';dbname=' . $database) . ';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('GD 2.209 social integration: ' . $message);
    }
};
$database = 'mc_2209_it_' . bin2hex(random_bytes(5));
$server = $connect(null);

try {
    $server->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($database);

    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        cube INT NOT NULL DEFAULT 1,
        color1 INT NOT NULL DEFAULT 0,
        color2 INT NOT NULL DEFAULT 3,
        special INT NOT NULL DEFAULT 0,
        UNIQUE KEY(account_id)
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE blocks (
        account_id BIGINT UNSIGNED NOT NULL,
        blocked_account_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY(account_id,blocked_account_id)
    ) ENGINE=InnoDB');

    foreach ([
        '20261016_003_gd2209_social.php',
        '20261016_004_gd2209_notifications.php',
    ] as $file) {
        $sql = require dirname(__DIR__, 2) . '/database/migrations/' . $file;
        foreach ($sql as $query) {
            $db->exec($query);
        }
    }

    foreach ([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma'] as $id => $name) {
        $insert = $db->prepare(
            'INSERT INTO accounts (account_id,username,password_hash,gjp2_hash)
             VALUES (:id,:name,:hash,:gjp)'
        );
        $insert->execute([
            'id' => $id,
            'name' => $name,
            'hash' => password_hash('pass-' . $id, PASSWORD_DEFAULT),
            'gjp' => password_hash('gjp-' . $id, PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id' => $id]);
    }

    $auth = new AccountAuthenticator($db);
    $following = new FollowingService($db, $auth, new GdRelationshipEncoder());
    $relationship = new RelationshipService(new RelationshipRepository($db), $auth, new GdRelationshipEncoder());
    $notifications = new NotificationService($db, $auth);

    $check(!$following->follow(1, 'pass-1', 1), 'self-follow must fail');
    $check(!$following->follow(1, 'pass-1', 999), 'unknown peer must fail');
    $check($following->follow(1, 'pass-1', 2), 'first follow succeeds');
    $check($following->follow(1, 'pass-1', 2), 'duplicate follow is idempotent');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_follows')->fetchColumn() === 1,
        'duplicate follow does not duplicate relations'
    );
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_notifications')->fetchColumn() === 1,
        'follow creates exactly one notification'
    );
    $check(
        count($following->list(1, 'pass-1', 0, false)['rows']) === 1,
        'following list returns subscribed peer'
    );
    $check(
        count($following->list(2, 'pass-2', 0, true)['rows']) === 1,
        'followers list returns initiator'
    );
    $check(
        str_contains($following->encodedList(1, 'pass-1', 0, false), 'Beta'),
        'following wire payload contains target username'
    );
    $check(
        str_contains($relationship->userList(1, 'pass-1', 3), '1:Beta:'),
        '2.209 getGJUserList20 type 3 returns followed accounts'
    );
    $check(
        $relationship->userList(2, 'pass-2', 3) === '-2',
        'followed list is viewer-private, not follower list'
    );
    $check(
        $following->encodedList(1, 'pass-1', 1001, false) === '-2',
        'out-of-range pagination returns no results'
    );
    $check(
        str_contains($notifications->list(2, 'pass-2', 0), 'Alpha'),
        'notification delivered only to target'
    );
    $check(
        $notifications->list(1, 'pass-1', 0) === '-2',
        'actor should not get own follow notification'
    );
    $notificationId = (int)$db->query('SELECT id FROM mucho_notifications LIMIT 1')->fetchColumn();
    $check(
        !$notifications->claim(1, 'pass-1', $notificationId),
        'another account cannot claim target notification'
    );
    $check(
        $notifications->claim(2, 'pass-2', $notificationId),
        'target can claim notification exactly once'
    );
    $check(
        !$notifications->claim(2, 'pass-2', $notificationId),
        'duplicate claim is rejected'
    );
    $check(
        $following->mute(1, 'pass-1', 2, true) &&
        $following->mute(1, 'pass-1', 2, true),
        'mute is idempotent'
    );
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_muted_users')->fetchColumn() === 1,
        'mute stores one row'
    );
    $check(
        str_contains($relationship->userList(1, 'pass-1', 4), '1:Beta:'),
        '2.209 getGJUserList20 type 4 returns muted accounts'
    );
    $check(
        $relationship->userList(2, 'pass-2', 4) === '-2',
        'muted accounts remain private to owner'
    );
    try {
        $relationship->userList(1, 'pass-2', 4);
        throw new RuntimeException('muted list accepted invalid credential');
    } catch (RuntimeException $e) {
        $check($e->getMessage() === 'Unauthorized.',
            'GD 2.209 user list enforces credential ownership');
    }
    $check($following->mute(1, 'pass-1', 2, false), 'unmute succeeds');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_muted_users')->fetchColumn() === 0,
        'unmute removes row'
    );
    $check($following->unfollow(1, 'pass-1', 2), 'unfollow succeeds');
    $check($following->unfollow(1, 'pass-1', 2), 'duplicate unfollow succeeds');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_follows')->fetchColumn() === 0,
        'unfollow removes relation'
    );
    $db->exec('INSERT INTO blocks(account_id,blocked_account_id) VALUES (2,1)');
    $check(!$following->follow(1, 'pass-1', 2), 'block denies follow');
    $check(!$following->mute(1, 'pass-1', 2, true), 'block denies mute');
    $db->exec('DELETE FROM blocks');
    $db->exec('UPDATE accounts SET is_banned=1 WHERE account_id=2');
    $check(!$following->follow(1, 'pass-1', 2), 'banned target cannot be followed');

    foreach ([
        static fn() => $following->follow(1, 'pass-2', 3),
        static fn() => $following->unfollow(1, 'pass-2', 3),
        static fn() => $following->list(1, 'pass-2', 0, false),
        static fn() => $following->mute(1, 'pass-2', 3, true),
        static fn() => $notifications->list(2, 'pass-1', 0),
        static fn() => $notifications->claim(2, 'pass-1', $notificationId),
    ] as $attack) {
        try {
            $attack();
            throw new RuntimeException('Stolen account ID accepted wrong credential');
        } catch (RuntimeException $e) {
            $check(
                $e->getMessage() === 'Unauthorized.',
                'unauthorized writes/read require a valid account credential'
            );
        }
    }

    $db->exec('DELETE FROM accounts WHERE account_id=1');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_notifications')->fetchColumn() === 0,
        'notifications cascade away when actor account is deleted'
    );
    echo "MUCHOCORE_GD_2209_SOCIAL_INTEGRATION_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $database);
}
