<?php
declare(strict_types=1);

/*
 * MuchoCore Profile API v2.2
 * Copyright (C) 2026 IZK
 */

require_once __DIR__ . '/bootstrap.php';

muchoV2RequireMethod('GET');

$id = filter_input(
    INPUT_GET,
    'account_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$id) {
    muchoV2Fail('invalid_account_id', 400);
}

try {
    $db = muchoV2Db();

    $q = $db->prepare("
        SELECT
            p.account_id,
            p.display_name,
            p.status,
            p.bio,
            p.theme_primary,
            p.theme_secondary,
            p.banner,
            p.title,
            p.badges,
            p.pinned_levels,
            p.showcase,
            p.favorite_difficulty,
            p.online_visible,
            p.created_at,
            p.updated_at,

            pr.last_seen,

            COALESCE(r.code, 'user') AS server_role

        FROM mucho_profile_customization p

        LEFT JOIN mucho_profile_presence pr
            ON pr.account_id=p.account_id

        LEFT JOIN roles r
            ON r.id = (
                SELECT a.role_id
                FROM accounts a
                WHERE a.account_id = p.account_id
                LIMIT 1
            )

        WHERE p.account_id=?
        LIMIT 1
    ");

    $q->execute([(int)$id]);
    $row = $q->fetch();

    if (!$row) {
        muchoV2Fail('profile_not_found', 404);
    }

    $visible = (bool)$row['online_visible'];
    $online = false;

    if ($visible && !empty($row['last_seen'])) {
        $last = strtotime((string)$row['last_seen']);

        $now = strtotime(
            (string)$db->query(
                'SELECT CURRENT_TIMESTAMP'
            )->fetchColumn()
        );

        if ($last !== false && $now !== false) {
            $online = ($now - $last) <= 90;
        }
    }

    $role = strtoupper((string)$row['server_role']);

    $roleRanks = [
        'PLAYER' => 0,
        'MOD' => 20,
        'ADMIN' => 30,
        'OWNER' => 100
    ];

    muchoV2Send([
        'ok' => true,
        'api' => 'MuchoCore',
        'version' => MUCHO_V2_VERSION,

        'data' => [
            'account_id' => (int)$row['account_id'],
            'display_name' => (string)$row['display_name'],

            'authority' => [
                'role' => $role,
                'rank' => $roleRanks[$role] ?? 0,
                'verified' => false
            ],

            'title' => (string)$row['title'],

            'badges' => muchoV2StringList(
                (string)$row['badges']
            ),

            'status' => (string)$row['status'],
            'bio' => (string)$row['bio'],

            'theme' => [
                'primary' => (string)$row['theme_primary'],
                'secondary' => (string)$row['theme_secondary'],
                'banner' => (string)$row['banner']
            ],

            'pinned_levels' => muchoV2PinnedLevels(
                (string)$row['pinned_levels']
            ),

            'showcase' => muchoV2StringList(
                (string)$row['showcase']
            ),

            'favorite_difficulty' =>
                (string)$row['favorite_difficulty'],

            'presence' => [
                'visible' => $visible,
                'online' => $visible ? $online : false,
                'last_seen' => $visible
                    ? ($row['last_seen'] ?: null)
                    : null
            ],

            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at']
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        '[MuchoCore API v2.2] profile: '.$e->getMessage()
    );

    muchoV2Fail('internal_error', 500);
}
