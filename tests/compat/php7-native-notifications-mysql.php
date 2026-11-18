<?php
/**
 * PHP 7.0 compatible test of the actual compiled GD 2.209 notification
 * implementation against disposable local MariaDB. No real user data.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/compat/php7/domain.php';

function notifCheck($ok, $reason) {
    if (!$ok) {
        throw new RuntimeException('PHP7 2.209 notification: ' . $reason);
    }
}
$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password)
    || $password === '' || !ctype_digit((string)$port)) {
    throw new RuntimeException('Requires isolated CI MariaDB.');
}
$connect = function ($database) use ($host, $password, $port) {
    return new PDO('mysql:host=' . $host . ';port=' . $port
        . ($database === null ? '' : ';dbname=' . $database)
        . ';charset=utf8mb4', 'root', $password, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
};
$name = 'mc_notif_native_' . bin2hex(random_bytes(5));
$server = $connect(null);
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE,
        cube INT NOT NULL DEFAULT 1,
        color1 INT NOT NULL DEFAULT 0,
        color2 INT NOT NULL DEFAULT 3,
        special INT NOT NULL DEFAULT 0,
        stars INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $insert = $db->prepare('INSERT INTO accounts
        (account_id,username,password_hash,gjp2_hash)
        VALUES(?,?,?,?)');
    foreach (array(1=>'Alpha',2=>'Beta') as $id=>$namePart) {
        $insert->execute(array($id,$namePart,
            password_hash('pass-'.$id,PASSWORD_BCRYPT),
            password_hash('gjp-'.$id,PASSWORD_BCRYPT)));
    }
    foreach (require $root .
        '/compat/php7/core/database/migrations/20261016_004_gd2209_notifications.php'
        as $sql) {
        $db->exec($sql);
    }
    $db->exec("INSERT INTO mucho_notifications
        (account_id,actor_account_id,notification_type,source_id)
        VALUES (2,1,'follow',0),(2,1,'message',999)");
    $service = new \MuchoCore\Social\NotificationService(
        $db,new \MuchoCore\Account\AccountAuthenticator($db)
    );
    $wire = $service->list(2,'pass-2',0);
    $parts = explode('#',$wire);
    notifCheck(count($parts) === 2 && $parts[1] === '1:0:20',
        'only supported follow notifications count for paging');
    notifCheck(strpos($parts[0],'|') === false,
        'unsupported event cannot leak a second or malformed wire object');
    $values = explode(':',$parts[0]);
    notifCheck(count($values) === 32,
        'compiled PHP7 emits all 16 GD 2.209 notification key/value pairs');
    $fields = array();
    foreach (array_chunk($values,2) as $pair) {
        $fields[(int)$pair[0]] = $pair[1];
    }
    notifCheck(array_keys($fields) === range(1,16)
        && $fields[2] === '13' && $fields[7] === '1'
        && $fields[9] === '0' && $fields[15] === '1'
        && $fields[16] === 'Alpha',
        'follow notification slots match canonical 2.209 PHP8');
    notifCheck(base64_decode(strtr($fields[4], '-_', '+/'),true) === 'New follower'
        && base64_decode(strtr($fields[5], '-_', '+/'),true)
            === 'Alpha started following you.',
        'follow title and body are URL-safe Base64, not legacy fields');
    $bad = (int)$db->query(
        "SELECT id FROM mucho_notifications WHERE notification_type='message'"
    )->fetchColumn();
    $follow = (int)$db->query(
        "SELECT id FROM mucho_notifications WHERE notification_type='follow'"
    )->fetchColumn();
    notifCheck(!$service->claim(2,'pass-2',$bad),
        'unsupported event cannot be marked claimed');
    notifCheck(!$service->claim(1,'pass-1',$follow),
        'unrelated account cannot claim somebody else notification');
    notifCheck($service->claim(2,'pass-2',$follow)
        && !$service->claim(2,'pass-2',$follow),
        'authentic owner claims supported event exactly once');
    $rows = $db->query('SELECT notification_type,is_read,reward_claimed
        FROM mucho_notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    notifCheck((int)$rows[0]['is_read'] === 1
        && (int)$rows[0]['reward_claimed'] === 1
        && (int)$rows[1]['is_read'] === 0
        && (int)$rows[1]['reward_claimed'] === 0,
        'unsupported event remains unmodified');
    $newWire = $service->list(2,'pass-2',0);
    $newValues = explode(':',explode('#',$newWire)[0]);
    notifCheck($newValues[13] === '0', 'claimed follow event is not new');
    notifCheck($service->list(1,'pass-1',0) === '-2',
        'notification inbox belongs solely to authenticated receiver');
    echo "MUCHOCORE_PHP7_GD2209_NOTIFICATION_PARITY_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
