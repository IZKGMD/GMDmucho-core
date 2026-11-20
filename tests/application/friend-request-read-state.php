<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Social/RelationshipRepository.php';

use MuchoCore\Social\RelationshipRepository;

// Exercise the real repository queries against a disposable database.
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE friend_requests (
    id INTEGER PRIMARY KEY,
    account_id INTEGER NOT NULL,
    to_account_id INTEGER NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0
)');
$db->exec('INSERT INTO friend_requests VALUES
    (1, 10, 20, 0), (2, 10, 20, 2), (3, 10, 20, 1),
    (4, 10, 30, 0), (5, 10, 20, 255)');
$repo = new RelationshipRepository($db);
$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};
$state = static fn(int $id): int => (int)$db->query(
    'SELECT is_read FROM friend_requests WHERE id=' . $id
)->fetchColumn();

$count = $repo->requestCount(20, false);
$check(!$repo->readRequest(20, 2), 'hidden request cannot be marked read');
$check($state(2) === 2, 'hidden state survives a direct read request');
$check($repo->requestCount(20, false) === $count, 'read does not restore a hidden inbox item');
$check($repo->readRequest(20, 1) && $state(1) === 1, 'recipient can read an unread request');
$check($repo->readRequest(20, 1), 'repeating a successful read remains successful');
$check($repo->readRequest(20, 3), 'an already-read request remains successful');
$check(!$repo->readRequest(10, 4) && $state(4) === 0, 'sender cannot mark recipient copy read');
$check(!$repo->readRequest(20, 4) && $state(4) === 0, 'unrelated account cannot mark request read');
$check(!$repo->readRequest(20, 999), 'missing request is rejected');
$check(!$repo->readRequest(20, 5) && $state(5) === 255, 'unknown state is not converted to visible');
$check((int)$db->query('SELECT COUNT(*) FROM friend_requests')->fetchColumn() === 5,
    'reading preserves stored requests');

$check(!$repo->deleteRequest(30, 0, false, 1), 'unrelated account cannot delete by request ID');
$check(!$repo->deleteRequest(10, 0, false, 1), 'sender cannot delete as the recipient');
$check(!$repo->deleteRequest(20, 30, false, 1), 'request ID and peer must point to the same record');
$check(!$repo->deleteRequest(20, 0, false), 'an identity-free delete must leave the inbox intact');
$check($repo->deleteRequest(20, 0, false, 1), 'recipient can decline a specific request by its ID');
$check($state(3) === 1, 'deleting by request ID preserves other entries from that peer');
$check($repo->deleteRequest(10, 30, true, 4), 'sender can cancel its own request by ID and peer');
$check($repo->deleteRequest(20, 10, false), 'legacy recipient can still delete by peer ID alone');
$check((int)$db->query('SELECT COUNT(*) FROM friend_requests')->fetchColumn() === 0,
    'only authorized request deletions reached storage');

echo "FRIEND_REQUEST_READ_STATE_OK\n";
