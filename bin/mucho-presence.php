<?php
declare(strict_types=1);

/*
 * MuchoCore Presence CLI v2.1
 * Copyright (C) 2026 IZK
 */

require_once '/var/www/mucho-core/public/api/v2/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function presenceInstall(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'pgsql') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mucho_profile_presence (
                account_id BIGINT PRIMARY KEY,
                client_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                last_seen TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mucho_profile_presence (
                account_id BIGINT PRIMARY KEY,
                client_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                last_seen TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    echo "PRESENCE_INSTALL_OK\n";
}

function requireProfile(PDO $db, int $id): void
{
    $q = $db->prepare(
        'SELECT account_id
         FROM mucho_profile_customization
         WHERE account_id=?
         LIMIT 1'
    );

    $q->execute([$id]);

    if ($q->fetchColumn() === false) {
        throw new RuntimeException('profile_not_found');
    }
}

function issueToken(PDO $db, int $id, string $path): void
{
    requireProfile($db, $id);

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'pgsql') {
        $q = $db->prepare("
            INSERT INTO mucho_profile_presence
                (account_id, client_token_hash)
            VALUES (?, ?)
            ON CONFLICT (account_id)
            DO UPDATE SET
                client_token_hash=EXCLUDED.client_token_hash,
                last_seen=NULL,
                updated_at=CURRENT_TIMESTAMP
        ");
    } else {
        $q = $db->prepare("
            INSERT INTO mucho_profile_presence
                (account_id, client_token_hash)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                client_token_hash=VALUES(client_token_hash),
                last_seen=NULL
        ");
    }

    $q->execute([$id, $hash]);

    if (file_put_contents($path, $token . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('token_file_write_failed');
    }

    chmod($path, 0600);

    echo "PRESENCE_TOKEN_ISSUED\n";
    echo "ACCOUNT_ID=$id\n";
    echo "TOKEN_FILE=$path\n";
}

function showPresence(PDO $db, int $id): void
{
    $q = $db->prepare("
        SELECT
            account_id,
            CASE
                WHEN client_token_hash='' THEN 0
                ELSE 1
            END AS token_configured,
            last_seen,
            created_at,
            updated_at
        FROM mucho_profile_presence
        WHERE account_id=?
        LIMIT 1
    ");

    $q->execute([$id]);

    $row = $q->fetch();

    echo json_encode(
        $row ?: [
            'account_id' => $id,
            'configured' => false
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}

try {
    $db = muchoV2Db();

    $cmd = $argv[1] ?? '';

    if ($cmd === '--install') {
        presenceInstall($db);
        exit(0);
    }

    if ($cmd === '--issue-token') {
        $id = (int)($argv[2] ?? 0);
        $path = (string)($argv[3] ?? '');

        if ($id <= 0 || $path === '') {
            throw new RuntimeException(
                'usage: --issue-token ACCOUNT_ID OUTPUT_FILE'
            );
        }

        presenceInstall($db);
        issueToken($db, $id, $path);
        exit(0);
    }

    if ($cmd === '--show') {
        $id = (int)($argv[2] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException(
                'usage: --show ACCOUNT_ID'
            );
        }

        presenceInstall($db);
        showPresence($db, $id);
        exit(0);
    }

    fwrite(STDERR, "Commands:\n");
    fwrite(STDERR, "  --install\n");
    fwrite(
        STDERR,
        "  --issue-token ACCOUNT_ID OUTPUT_FILE\n"
    );
    fwrite(STDERR, "  --show ACCOUNT_ID\n");

    exit(1);

} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
