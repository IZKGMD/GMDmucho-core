<?php

declare(strict_types=1);

/**
 * Read-only MariaDB observations for the disposable GD 2.209 HTTP replay.
 *
 * The PHP CLI and Caddy fixture must point to the same testgdps database.
 * There is deliberately no SQL mutation or arbitrary SQL parameter.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MUCHO_TEST_ALLOW_WRITES') !== '1' ||
    getenv('DB_NAME') !== 'testgdps') {
    fwrite(STDERR, "Refusing a non-disposable database\n");
    exit(2);
}

$db = \MuchoCore\V71\DatabaseBridge::pdo();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    fwrite(STDERR, "Unexpected database connection\n");
    exit(2);
}

$action = $argv[1] ?? '';
$id = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
$peer = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id <= 0 || $peer === false || $peer < 0) {
    fwrite(STDERR, "Invalid fixture IDs\n");
    exit(2);
}

switch ($action) {
    case 'account':
        $sql = 'SELECT a.account_id,a.username,
                    COALESCE(p.user_id,a.account_id) AS user_id
                FROM accounts a
                LEFT JOIN profiles p ON p.account_id=a.account_id
                WHERE a.account_id=? LIMIT 1';
        $params = [$id];
        break;

    case 'cloud':
        $sql = 'SELECT sha256,revision,size_bytes
                FROM mucho_cloud_saves WHERE account_id=? LIMIT 1';
        $params = [$id];
        break;

    case 'user_info':
        $sql = 'SELECT levels_info,payload_sha256
                FROM mucho_account_user_info WHERE account_id=? LIMIT 1';
        $params = [$id];
        break;

    case 'following':
        $sql = 'SELECT COUNT(*) AS total FROM mucho_follows
                WHERE follower_account_id=? AND followed_account_id=?';
        $params = [$id, $peer];
        break;

    case 'follow_notifications':
        $sql = "SELECT COUNT(*) AS total FROM mucho_notifications
                WHERE account_id=? AND actor_account_id=?
                  AND notification_type='follow' AND source_id=?";
        $params = [$peer, $id, $id];
        break;

    case 'level':
        $sql = 'SELECT level_id,account_id,name,level_data,
                       description,is_deleted
                FROM levels WHERE level_id=? LIMIT 1';
        $params = [$id];
        break;

    default:
        fwrite(STDERR, "Unknown read-only fixture operation\n");
        exit(2);
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$record = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($record ?: null, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
