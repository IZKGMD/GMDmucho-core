<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'MuchoCore\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use MuchoCore\V71\DatabaseBridge;
use MuchoCore\V71\SchemaInspector;

try {
    $db = DatabaseBridge::pdo();

    $db->exec(
        "CREATE TABLE IF NOT EXISTS mucho_level_lists (
            list_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT NOT NULL,
            list_name VARCHAR(64) NOT NULL DEFAULT 'Unnamed list',
            list_desc VARCHAR(500) NOT NULL DEFAULT '',
            list_version INT NOT NULL DEFAULT 1,
            level_ids TEXT NOT NULL,
            difficulty INT NOT NULL DEFAULT 0,
            original_id BIGINT NOT NULL DEFAULT 0,
            unlisted TINYINT(1) NOT NULL DEFAULT 0,
            downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes BIGINT NOT NULL DEFAULT 0,
            featured INT NOT NULL DEFAULT 0,
            stars INT NOT NULL DEFAULT 0,
            count_for_reward INT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            PRIMARY KEY (list_id),
            KEY idx_mucho_lists_account (account_id),
            KEY idx_mucho_lists_public_recent (unlisted, created_at),
            KEY idx_mucho_lists_downloads (downloads),
            KEY idx_mucho_lists_likes (likes)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $schema = new SchemaInspector($db);

    // One-time continuity import from a legacy Cvolton-style `lists` table.
    // Existing Mucho rows always win; this runs only while mucho_level_lists is empty.
    $legacyImported = 0;
    if ($schema->tableExists('lists')) {
        $currentCount = (int)$db->query('SELECT COUNT(*) FROM mucho_level_lists')->fetchColumn();

        if ($currentCount === 0) {
            try {
                $legacy = $db->query('SELECT * FROM lists');
                $insertLegacy = $db->prepare(
                    'INSERT IGNORE INTO mucho_level_lists
                     (list_id, account_id, list_name, list_desc, list_version, level_ids,
                      difficulty, original_id, unlisted, downloads, likes, featured, stars,
                      count_for_reward, created_at, updated_at)
                     VALUES
                     (:list_id, :account_id, :list_name, :list_desc, :list_version, :level_ids,
                      :difficulty, :original_id, :unlisted, :downloads, :likes, :featured, :stars,
                      :count_for_reward, :created_at, :updated_at)'
                );

                while ($row = $legacy->fetch(PDO::FETCH_ASSOC)) {
                    $toUnix = static function ($value): int {
                        if ($value === null || $value === '') {
                            return time();
                        }
                        if (is_numeric($value)) {
                            return (int)$value;
                        }
                        $parsed = strtotime((string)$value);
                        return $parsed !== false ? $parsed : time();
                    };

                    $insertLegacy->execute([
                        ':list_id' => (int)($row['listID'] ?? 0),
                        ':account_id' => (int)($row['accountID'] ?? 0),
                        ':list_name' => (string)($row['listName'] ?? 'Unnamed list'),
                        ':list_desc' => (string)($row['listDesc'] ?? ''),
                        ':list_version' => max(1, (int)($row['listVersion'] ?? 1)),
                        ':level_ids' => (string)($row['listlevels'] ?? $row['listLevels'] ?? ''),
                        ':difficulty' => (int)($row['starDifficulty'] ?? 0),
                        ':original_id' => (int)($row['original'] ?? 0),
                        ':unlisted' => (int)($row['unlisted'] ?? 0) === 1 ? 1 : 0,
                        ':downloads' => max(0, (int)($row['downloads'] ?? 0)),
                        ':likes' => (int)($row['likes'] ?? 0),
                        ':featured' => (int)($row['starFeatured'] ?? 0),
                        ':stars' => (int)($row['starStars'] ?? 0),
                        ':count_for_reward' => (int)($row['countForReward'] ?? 0),
                        ':created_at' => $toUnix($row['uploadDate'] ?? null),
                        ':updated_at' => $toUnix($row['updateDate'] ?? $row['uploadDate'] ?? null),
                    ]);
                    $legacyImported += $insertLegacy->rowCount();
                }
            } catch (Throwable $legacyError) {
                fwrite(STDERR, "LEGACY_LISTS_IMPORT_WARN=" . $legacyError->getMessage() . PHP_EOL);
            }
        }
    }

    echo "DB_OK\n";
    echo "LEGACY_LISTS_IMPORTED={$legacyImported}\n";
    echo "TABLE_mucho_level_lists=" . ($schema->tableExists('mucho_level_lists') ? "OK" : "MISSING") . "\n";

    foreach ([
        'mucho_daily_rotation',
        'mucho_gauntlets',
        'mucho_map_packs',
        'mucho_level_scores',
        'mucho_platformer_scores',
    ] as $table) {
        echo "V6_2_{$table}=" . ($schema->tableExists($table) ? "OK" : "WARN_MISSING") . "\n";
    }

    echo "COMMENT_HISTORY_comments=" . ($schema->tableExists('comments') ? "OK" : "WARN_MISSING") . "\n";
    echo "COMMENT_HISTORY_users=" . ($schema->tableExists('users') ? "OK" : "WARN_MISSING") . "\n";
    echo "TOP_ARTISTS_songs=" . ($schema->tableExists('songs') ? "OK" : "WARN_MISSING") . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "DB_ERROR=" . $e->getMessage() . PHP_EOL);
    exit(2);
}
