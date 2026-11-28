<?php
declare(strict_types=1);

/**
 * Repair only the 18 temporary GD 2.209 visual test fixtures created on the
 * MuchoCore VPS. NEVER rewrite normal user levels or arbitrary level IDs.
 * Default is a read-only dry run; --apply requires an external SQL backup.
 */
const MUCHO_VIS_FIXTURE_SOURCE_SHA = '0f003e6e3597e0d9b4ade54aeb373642c4c1a1d3d96b22275941c4b2d0da57b0';

function muchoVisualPlayablePayload(string $stored): array
{
    $raw = base64_decode(strtr($stored, '-_', '+/'), true);
    $decoded = $raw === false ? false : @gzdecode($raw);
    if (!is_string($decoded)
        || hash('sha256', $decoded) !== MUCHO_VIS_FIXTURE_SOURCE_SHA) {
        throw new RuntimeException('Fixture payload fingerprint mismatch; refusing to alter level.');
    }
    $segments = explode(';', $decoded);
    $objects = 0;
    foreach ($segments as $index => &$segment) {
        if ($index === 0 || $segment === '') {
            continue; // GD level settings prefix, or final delimiter.
        }
        $parts = explode(',', $segment);
        if (count($parts) % 2 !== 0) {
            throw new RuntimeException('Unexpected fixture object structure.');
        }
        $id = null;
        $x = null;
        $yPosition = null;
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            if ($parts[$i] === '1') $id = (int)$parts[$i + 1];
            if ($parts[$i] === '2') $x = (int)$parts[$i + 1];
            if ($parts[$i] === '3') $yPosition = $i + 1;
        }
        if ($id !== 8 || $x === null || $x < 190 || $x > 920
            || $yPosition === null || (int)$parts[$yPosition] < 0
            || (int)$parts[$yPosition] > 320) {
            throw new RuntimeException('Unexpected fixture object encountered.');
        }
        // The fixtures intentionally duplicated 37 obstacle objects,
        // including the first at x=195 and ground level y=15.
        // Move only these objects well above the cube's playable corridor.
        $parts[$yPosition] = (string)((int)$parts[$yPosition] + 900);
        $segment = implode(',', $parts);
        ++$objects;
    }
    unset($segment);
    if ($objects !== 37) {
        throw new RuntimeException('Expected exactly 37 original fixture objects.');
    }
    $newDecoded = implode(';', $segments);
    $gzip = gzencode($newDecoded, 6);
    if ($gzip === false) {
        throw new RuntimeException('Failed encoding safe fixture.');
    }
    $encoded = base64_encode($gzip);
    if (gzdecode(base64_decode($encoded, true)) !== $newDecoded) {
        throw new RuntimeException('Fixture gzip roundtrip failed.');
    }
    return [$encoded, $objects, hash('sha256', $newDecoded)];
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (in_array('--self-test', $argv, true)) {
    echo "Self-test requires a real original fixture payload; use --dry-run against the VPS database.\n";
    exit(0);
}
$apply = in_array('--apply', $argv, true);
if (!$apply && !in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "Usage: php bin/mucho-fix-2209-visual-fixtures.php --dry-run|--apply\n");
    exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';
$pdo = (new \MuchoCore\Database\Database())->connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $pdo->query(
    'SELECT level_id,name,level_data FROM levels
     WHERE level_id BETWEEN 12 AND 29 ORDER BY level_id'
)->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 18) {
    throw new RuntimeException('Expected exactly 18 test levels; no changes applied.');
}
$updated = [];
$original = [];
$expectedNames = [
    'Unrated','Auto','Easy','Normal','Hard','Harder','Insane',
    'Easy Demon','Medium Demon','Hard Demon','Insane Demon',
    'Extreme Demon','Featured','Epic','Legendary','Mythic',
    'Leaderboard','Comments',
];
foreach ($rows as $index => $row) {
    $expectedName = sprintf('MC2209 VIS %02d %s', $index + 1, $expectedNames[$index]);
    if ((int)$row['level_id'] !== $index + 12 || $row['name'] !== $expectedName) {
        throw new RuntimeException('Fixture ID/name mismatch; cannot safely modify existing user levels.');
    }
    [$encoded, $objects, $newHash] = muchoVisualPlayablePayload((string)$row['level_data']);
    $updated[(int)$row['level_id']] = $encoded;
    $original[(int)$row['level_id']] = (string)$row['level_data'];
    printf("FIXTURE %d objects=%d new-sha256=%s\n", (int)$row['level_id'], $objects, $newHash);
}
if (!$apply) {
    echo "DRY_RUN_OK: 18 named test fixtures are eligible. No data modified.\n";
    exit(0);
}
umask(0077);
$backupDir = dirname(__DIR__) . '/backups/fixtures';
if (is_link(dirname(__DIR__) . '/backups') || is_link($backupDir)) {
    throw new RuntimeException('Backup directory may not be a symlink.');
}
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Cannot create private backup directory.');
}
$backupFile = $backupDir . '/gd2209-vis-before-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
$data = json_encode([
    'purpose' => 'Original 18 GD 2.209 visual test fixture level_data fields only',
    'created_at' => gmdate('c'),
    'records' => $original,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
if (file_put_contents($backupFile, $data, LOCK_EX) !== strlen($data)) {
    throw new RuntimeException('Could not write complete original fixture backup.');
}
chmod($backupFile, 0600);
if (!hash_equals(hash('sha256',$data),hash_file('sha256',$backupFile))) {
    throw new RuntimeException('Private fixture backup SHA256 verification failed.');
}
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE levels SET level_data=?,updated_at=updated_at
        WHERE level_id=? AND name=? AND level_data=? AND is_deleted=0');
    foreach ($rows as $index => $row) {
        $id = (int)$row['level_id'];
        $stmt->execute([$updated[$id],$id,$row['name'],$original[$id]]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Fixture $id changed concurrently. Roll back all changes.");
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
try {
    \MuchoCore\Cache\CacheManager::fromEnvironment($pdo)->deletePrefix('mucho:levels:');
} catch (Throwable $e) {
    error_log('[MuchoCore] Visual-fixture rating cache invalidation failed.');
}
echo "REPAIRED_18_FIXTURES: original payloads saved in a private local backup.\n";
