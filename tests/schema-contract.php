<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

/*
 * Fresh-install schema guard.
 * Every table below is directly used by a first-party endpoint, API or
 * bundled admin module. The migration must keep the table declarations.
 */

$migration = file_get_contents(
    dirname(__DIR__) . '/database/migrations/017_core_compatibility_tables.php'
);

if ($migration === false) {
    throw new RuntimeException('Compatibility migration is missing.');
}

$tables = [
    'mucho_level_lists',
    'friends',
    'blocks',
    'friend_requests',
    'messages',
    'mucho_cloud_saves',
    'mucho_cloud_save_revisions',
    'mucho_reward_state',
    'mucho_challenge_pool',
    'mucho_level_scores',
    'mucho_platformer_scores',
    'mucho_daily_rotation',
    'mucho_gauntlets',
    'mucho_map_packs',
    'mucho_level_reports',
    'mucho_profile_presence',
    'mucho_account_roles',
    'mucho_music_rate_limits',
    'admin_users',
    'admin_audit_logs',
    'mucho_profile_customization',
    'mucho_feature_flags',
    'mucho_client_releases',
    'mucho_admin_client_tokens',
    'mucho_admin_client_login_attempts',
    'mucho_admin_client_audit',
    'mucho_client_release_uploads',
    'mucho_client_release_files',
    'gdps_settings',
    'admin_deleted_accounts_v4',
];

$missing = [];

foreach ($tables as $table) {
    if (
        preg_match(
            '/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($table, '/') . '\b/i',
            $migration
        ) !== 1
    ) {
        $missing[] = $table;
    }
}

if ($missing !== []) {
    throw new RuntimeException(
        "Missing schema declarations:\n - " .
        implode("\n - ", $missing)
    );
}

echo "SCHEMA_CONTRACT_OK (" . count($tables) . " tables)\n";
