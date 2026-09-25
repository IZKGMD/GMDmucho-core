<?php
declare(strict_types=1);

/*
 * MuchoCore Presence API v2.1
 * Copyright (C) 2026 IZK
 */

require_once __DIR__ . '/bootstrap.php';

muchoV2RequireMethod('POST');

try {
    $input = $_POST;

    $contentType = strtolower(
        $_SERVER['CONTENT_TYPE'] ?? ''
    );

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');

        $json = json_decode(
            $raw !== false ? $raw : '',
            true
        );

        if (is_array($json)) {
            $input = $json;
        }
    }

    $id = (int)($input['account_id'] ?? 0);
    $token = trim((string)($input['token'] ?? ''));

    if ($id <= 0) {
        muchoV2Fail('invalid_account_id', 400);
    }

    if (
        strlen($token) < 32 ||
        strlen($token) > 256
    ) {
        muchoV2Fail('invalid_token', 401);
    }

    $db = muchoV2Db();

    $q = $db->prepare("
        SELECT
            p.client_token_hash,
            p.last_seen,
            a.is_active,
            a.is_banned
        FROM mucho_profile_presence p
        INNER JOIN accounts a
            ON a.account_id = p.account_id
        WHERE p.account_id=?
        LIMIT 1
    ");

    $q->execute([$id]);

    $presence = $q->fetch();

    if (
        !$presence ||
        (int)($presence['is_active'] ?? 0) !== 1 ||
        (int)($presence['is_banned'] ?? 0) !== 0 ||
        !is_string($presence['client_token_hash']) ||
        $presence['client_token_hash'] === '' ||
        !hash_equals(
            $presence['client_token_hash'],
            hash('sha256', $token)
        )
    ) {
        muchoV2Fail('unauthorized', 401);
    }

    if (!empty($presence['last_seen'])) {
        $now = strtotime(
            (string)$db->query(
                'SELECT CURRENT_TIMESTAMP'
            )->fetchColumn()
        );

        $last = strtotime(
            (string)$presence['last_seen']
        );

        if (
            $now !== false &&
            $last !== false &&
            ($now - $last) < 5
        ) {
            if (PHP_SAPI !== 'cli') {
                header('Retry-After: 5');
            }

            muchoV2Send([
                'ok' => false,
                'api' => 'MuchoCore',
                'version' => MUCHO_V2_VERSION,
                'error' => 'heartbeat_rate_limited',
                'retry_after_seconds' => 5
            ], 429);
        }
    }

    $q = $db->prepare("
        UPDATE mucho_profile_presence
        SET last_seen=CURRENT_TIMESTAMP
        WHERE account_id=?
    ");

    $q->execute([$id]);

    muchoV2Send([
        'ok' => true,
        'api' => 'MuchoCore',
        'version' => MUCHO_V2_VERSION,
        'presence' => [
            'account_id' => $id,
            'online' => true,
            'next_heartbeat_seconds' => 30,
            'online_timeout_seconds' => 90
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        '[MuchoCore Presence] heartbeat: ' .
        $e->getMessage()
    );

    muchoV2Fail('internal_error', 500);
}
