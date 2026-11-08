<?php

declare(strict_types=1);

/**
 * PHP 8 private-message integration, isolated MariaDB only.
 * Verifies both pre-migration wire messages and new decoded UTF-8 rows.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdMessageEncoder;
use MuchoCore\Social\MessageRepository;
use MuchoCore\Social\MessageService;
use MuchoCore\Social\RelationshipRepository;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Private-message test requires isolated CI MariaDB.');
}

$connect = static fn(?string $database): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($database === null ? '' : ';dbname=' . $database) . ';charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Private messages: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$name = 'mc_private_it_' . bin2hex(random_bytes(5));
$server = $connect(null);
$db = null;

try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($name);

    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0,
        messages_state TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');

    foreach (require dirname(__DIR__, 2) . '/database/migrations/021_social_base.php' as $sql) {
        $db->exec($sql);
    }

    $db->exec('CREATE TABLE mucho_clans (
        clan_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        tag VARCHAR(12) NOT NULL
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_clan_members (
        clan_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_gd2209_restrictions (
        account_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(30) NOT NULL,
        expires_at DATETIME NULL
    ) ENGINE=InnoDB');

    foreach ([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma'] as $id => $username) {
        $db->prepare('INSERT INTO accounts(account_id,username,password_hash,gjp2_hash)
            VALUES (?,?,?,?)')
            ->execute([$id, $username, password_hash('test-' . $id, PASSWORD_DEFAULT), '']);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (?)')->execute([$id]);
    }

    $encoder = new GdMessageEncoder();
    $messages = new MessageService(
        new MessageRepository($db),
        new AccountAuthenticator($db),
        $encoder,
        new RelationshipRepository($db)
    );
    $subject = 'Тема: #|~ 😀';
    $body = 'Привет #|: -4 ~ 😀 — текст не должен ломаться';
    $subjectWire = $encoder->encodeSubject($subject);
    $bodyWire = $encoder->encodeBody($body);

    // A previous deployment may contain messages with no content_format column.
    $legacySubject = $encoder->encodeSubject('Old #|~');
    $legacyBody = $encoder->encodeBody('Old body -4 Привет');
    $db->prepare('INSERT INTO messages (account_id,to_account_id,subject,body)
        VALUES (?,?,?,?)')->execute([1, 2, $legacySubject, $legacyBody]);
    $oldId = (int)$db->lastInsertId();

    foreach (require dirname(__DIR__, 2) . '/database/migrations/20261108_001_message_content_format.php' as $sql) {
        $db->exec($sql);
    }
    $q = $db->prepare('SELECT subject,body,content_format FROM messages WHERE id=?');
    $q->execute([$oldId]);
    $storedLegacy = $q->fetch(PDO::FETCH_ASSOC);
    $check($storedLegacy !== false &&
        (int)$storedLegacy['content_format'] === 0 &&
        $storedLegacy['subject'] === $legacySubject &&
        $storedLegacy['body'] === $legacyBody,
        'migration leaves old messages unchanged');

    $check(str_contains($messages->readMessage(2, 'test-2', $oldId), ':5:' . $legacyBody . ':7:'),
        'legacy wire body is not encoded twice');
    $check(!$messages->sendMessage(1, 'test-1', 1, $subjectWire, $bodyWire),
        'cannot send private message to self');
    $check(!$messages->sendMessage(1, 'test-1', 2, '%%%bad', $bodyWire),
        'malformed subject is rejected');
    $check(!$messages->sendMessage(1, 'test-1', 2, $subjectWire, '%%%bad'),
        'malformed body is rejected');
    $check(!$messages->sendMessage(1, 'test-1', 2, str_repeat('A', 341), $bodyWire),
        'oversized encoded subject is rejected');
    $check(!$messages->sendMessage(1, 'test-1', 2, $subjectWire, str_repeat('A', 87381)),
        'oversized encoded body is rejected');

    $check($messages->sendMessage(1, 'test-1', 2, $subjectWire, $bodyWire),
        'valid encoded UTF-8 message is accepted');
    $id = (int)$db->lastInsertId();

    $q = $db->prepare('SELECT subject,body,content_format,is_read FROM messages WHERE id=?');
    $q->execute([$id]);
    $stored = $q->fetch(PDO::FETCH_ASSOC);
    $check($stored !== false &&
        $stored['subject'] === $subject &&
        $stored['body'] === $body &&
        (int)$stored['content_format'] === 1 &&
        (int)$stored['is_read'] === 0,
        'new message stores plaintext with format marker');
    $inbox = $messages->getMessages(2, 'test-2', 0, false);
    $outbox = $messages->getMessages(1, 'test-1', 0, true);
    $check(str_contains($inbox, ':4:' . $subjectWire . ':8:'),
        'receiver inbox contains Base64 subject');
    $check(str_contains($outbox, ':4:' . $subjectWire . ':8:'),
        'sender outbox contains Base64 subject');

    $check($messages->readMessage(3, 'test-3', $id) === '-1',
        'unrelated account cannot read message');
    $check(str_contains($messages->readMessage(2, 'test-2', $id), ':5:' . $bodyWire . ':7:'),
        'receiver reads encoded body without leaking wire delimiters');
    $check((int)$db->query('SELECT is_read FROM messages WHERE id=' . $id)->fetchColumn() === 1,
        'reading marks message as read');
    $check($messages->deleteMessage(2, 'test-2', $id, false),
        'receiver can delete only inbox copy');
    $check($messages->readMessage(2, 'test-2', $id) === '-1',
        'receiver cannot re-download deleted copy');
    $check(str_contains($messages->getMessages(1, 'test-1', 0, true), ':1:' . $id . ':'),
        'sender outbox survives receiver deletion');
    $check(!$messages->deleteMessage(3, 'test-3', $id, false),
        'unrelated account cannot delete a copy');
    $check($messages->deleteMessage(1, 'test-1', $id, true),
        'sender can delete own outbox copy');
    $check($messages->readMessage(1, 'test-1', $id) === '-1',
        'sender cannot re-download deleted copy');
    $check((int)$db->query('SELECT COUNT(*) FROM messages WHERE id=' . $id)->fetchColumn() === 1,
        'soft deletion preserves stored row');
    $check((int)$db->query('SELECT COUNT(*) FROM messages WHERE content_format=1')->fetchColumn() === 1,
        'rejections never insert a new message');

    // Recipient privacy and blocking must be enforced before insertion.
    $db->exec('UPDATE accounts SET messages_state=2 WHERE account_id=2');
    $check(!$messages->sendMessage(1, 'test-1', 2, $subjectWire, $bodyWire),
        'disabled recipient messages are rejected');
    $db->exec('UPDATE accounts SET messages_state=1 WHERE account_id=2');
    $check(!$messages->sendMessage(1, 'test-1', 2, $subjectWire, $bodyWire),
        'friends-only messages reject strangers');
    $db->exec('INSERT INTO friends (account_id,friend_account_id) VALUES (1,2)');
    $check($messages->sendMessage(1, 'test-1', 2, $subjectWire, $bodyWire),
        'friends-only messages accept friends');
    $db->exec('INSERT INTO blocks(account_id,blocked_account_id) VALUES (2,1)');
    $check(!$messages->sendMessage(1, 'test-1', 2, $subjectWire, $bodyWire),
        'recipient block refuses private messages');
    $db->exec('DELETE FROM blocks');
    $db->exec('UPDATE accounts SET messages_state=0 WHERE account_id=2');
    $db->exec("INSERT INTO mucho_gd2209_restrictions (account_id,kind,expires_at)
        VALUES (1,'message',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))");
    $check(!$messages->sendMessage(1, 'test-1', 2, $subjectWire, $bodyWire),
        'temporary message restriction prevents sending');

    echo "MUCHOCORE_PRIVATE_MESSAGE_INTEGRATION_OK\n";
} finally {
    $db = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
