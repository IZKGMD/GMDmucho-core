<?php

declare(strict_types=1);

// Only the disposable acceptance tenant may seed or remove this fixture.
if (PHP_SAPI !== 'cli') {
    exit(2);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = (new \MuchoCore\Database\Database())->connection();
$database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/^(?:mucho_acceptance_|mc_acceptance_|testgdps$)/D', $database)) {
    fwrite(STDERR, "Refusing to modify a non-acceptance database.\n");
    exit(2);
}

const FIXTURE_ID = 99999001;
const FIXTURE_NAME = 'MuchoCore Acceptance Fixture';

$command = $argv[1] ?? '';
if ($command === '--create') {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO songs
            (id, name, author_id, author_name, size, download_url, is_verified)
         VALUES (?, ?, 0, ?, 1.0, ?, 1)'
    );
    $stmt->execute([
        FIXTURE_ID,
        FIXTURE_NAME,
        'MuchoCore Test',
        'https://example.invalid/acceptance.mp3',
    ]);
    $stmt = $db->prepare('SELECT name,is_verified FROM songs WHERE id=?');
    $stmt->execute([FIXTURE_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row && $row['name'] === FIXTURE_NAME &&
        (int)$row['is_verified'] === 1 ? '1' : '-1';
    exit(0);
}

if ($command === '--delete') {
    $stmt = $db->prepare('DELETE FROM songs WHERE id=? AND name=?');
    $stmt->execute([FIXTURE_ID, FIXTURE_NAME]);
    echo '1';
    exit(0);
}

fwrite(STDERR, "Usage: php music-fixture.php --create|--delete\n");
exit(2);
