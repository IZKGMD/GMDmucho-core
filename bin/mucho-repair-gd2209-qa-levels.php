<?php
/**
 * Repair ONLY the disposable MC2209 QA/VIS visual test levels after a
 * verified SQL backup. Never touch real uploaded levels or their ratings.
 *
 * Dry run: php bin/mucho-repair-gd2209-qa-levels.php
 * Apply:   php bin/mucho-repair-gd2209-qa-levels.php --apply --backup=/absolute/path.sql.gz
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;
use MuchoCore\Cache\CacheManager;

function fixtureWalkthrough(string $source, int $levelId): string
{
    $bytes = base64_decode(strtr($source, '-_', '+/'), true);
    if (!is_string($bytes)) throw new RuntimeException('Fixture payload is not Base64.');
    $old = @gzdecode($bytes);
    if (!is_string($old) || strpos($old, 'kS') !== 0) {
        throw new RuntimeException('Fixture is not expected gzip-wrapped GD editor data.');
    }
    $headerEnd = strpos($old, ';');
    if ($headerEnd === false || $headerEnd > 4096) {
        throw new RuntimeException('Invalid QA editor header.');
    }
    $settings = substr($old, 0, $headerEnd + 1);
    // Flat, obstacle-free cube course. These six solid objects float far
    // above the player; the furthest sets a finite finish location.
    // Vary coordinates for every fixture to avoid shared stale level caches.
    $flat = $settings;
    for ($i = 0; $i < 6; $i++) {
        $x = 500 + $i * 110 + $levelId * 3;
        $y = 630 + ($levelId % 4) * 30;
        $flat .= '1,8,2,' . $x . ',3,' . $y . ';';
    }
    return base64_encode(gzencode($flat, 9));
}

function fixtureBackupIsVerified(string $path): bool
{
    if ($path === '' || !is_file($path) || is_link($path)
        || substr($path, -7) !== '.sql.gz'
        || filemtime($path) < time() - 900 || filesize($path) < 32
        || !is_file($path . '.sha256') || is_link($path . '.sha256')) {
        return false;
    }
    $expected = strtolower(trim((string)file_get_contents($path . '.sha256')));
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) return false;
    return hash_equals($hash . '  ' . basename($path), $expected);
}

function fixtureRows(PDO $db): array
{
    $rows = $db->query('SELECT level_id,name,level_data,object_count
         FROM levels WHERE level_id BETWEEN 1 AND 29 AND is_deleted=0
         ORDER BY level_id')->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 29) {
        throw new RuntimeException('Expected exactly 29 active QA fixtures; not updating any levels.');
    }
    $hashes = [];
    foreach ($rows as $i => $row) {
        $id = (int)$row['level_id'];
        $name = (string)$row['name'];
        if ($id !== $i + 1 || ($id === 1
                ? $name !== 'Unnamed 0'
                : preg_match('/^MC2209 (?:QA|VIS) [0-9]{2} /D', $name) !== 1)
            || (int)$row['object_count'] !== 37) {
            throw new RuntimeException('Fixture identity mismatch at level ' . $id);
        }
        $hashes[hash('sha256', $row['level_data'])] = true;
    }
    if (count($hashes) !== 1) {
        throw new RuntimeException('QA level data already diverged. Manual review required.');
    }
    return $rows;
}

// Safe even when included by a dry-run fixture.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $apply = in_array('--apply', $argv, true);
    $backup = '';
    foreach ($argv as $arg) {
        if (strpos($arg, '--backup=') === 0) $backup = substr($arg, 9);
    }
    $pdo = (new Database())->connection();
    $rows = fixtureRows($pdo);
    $planned = [];
    foreach ($rows as $row) {
        $planned[(int)$row['level_id']] =
            fixtureWalkthrough((string)$row['level_data'], (int)$row['level_id']);
    }
    if (!$apply) {
        echo "GD2209_QA_REPAIR_DRY_RUN_OK fixtures=29 old_payloads=1 new_payloads=" .
            count(array_unique($planned)) . PHP_EOL;
        exit(0);
    }
    if (getenv('MUCHO_QA_FIXTURE_APPLY') !== 'YES' || !fixtureBackupIsVerified($backup)) {
        throw new RuntimeException('Explicit apply flag and fresh verified SQL backup required.');
    }
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE levels SET level_data=?,object_count=6,
            level_version=level_version+1,updated_at=UTC_TIMESTAMP()
            WHERE level_id=? AND level_data=? AND object_count=37
            AND is_deleted=0');
        foreach ($rows as $row) {
            $id = (int)$row['level_id'];
            $update->execute([$planned[$id], $id, (string)$row['level_data']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Concurrent fixture modification: ' . $id);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    try {
        CacheManager::fromEnvironment($pdo)->deletePrefix('mucho:levels:');
    } catch (Throwable $e) {
        error_log('[MuchoCore] QA fixture cache invalidation warning.');
    }
    echo "GD2209_QA_REPAIR_APPLIED fixtures=29 distinct=29 original_ratings_preserved=yes" . PHP_EOL;
}
