<?php
declare(strict_types=1);

/*
 * MuchoCore Roles CLI v2.2
 * Copyright (C) 2026 IZK
 */

require_once '/var/www/mucho-core/public/api/v2/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const ROLES = [
    'PLAYER',
    'MODERATOR',
    'ELDER_MODERATOR',
    'OWNER'
];

function installRoles(PDO $db): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mucho_account_roles (
                account_id BIGINT PRIMARY KEY,
                role VARCHAR(16) NOT NULL DEFAULT 'PLAYER',
                verified SMALLINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mucho_account_roles (
                account_id BIGINT PRIMARY KEY,
                role VARCHAR(16) NOT NULL DEFAULT 'PLAYER',
                verified TINYINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

function requireAccount(PDO $db, int $id): void
{
    $q = $db->prepare("
        SELECT account_id
        FROM mucho_profile_customization
        WHERE account_id=?
        LIMIT 1
    ");

    $q->execute([$id]);

    if ($q->fetchColumn() === false) {
        throw new RuntimeException('profile_not_found');
    }
}

function ensureRole(PDO $db, int $id): void
{
    requireAccount($db, $id);

    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $q = $db->prepare("
            INSERT INTO mucho_account_roles(account_id)
            VALUES(?)
            ON CONFLICT (account_id) DO NOTHING
        ");
    } else {
        $q = $db->prepare("
            INSERT IGNORE INTO mucho_account_roles(account_id)
            VALUES(?)
        ");
    }

    $q->execute([$id]);
}

try {
    $db = muchoV2Db();
    installRoles($db);

    $cmd = $argv[1] ?? '';

    if ($cmd === '--install') {
        echo "ROLES_INSTALL_OK\n";
        exit;
    }

    if ($cmd === '--set') {
        $id = (int)($argv[2] ?? 0);
        $role = strtoupper(trim((string)($argv[3] ?? '')));

        if ($id <= 0 || !in_array($role, ROLES, true)) {
            throw new RuntimeException(
                'usage: --set ACCOUNT_ID PLAYER|MODERATOR|ELDER_MODERATOR|OWNER'
            );
        }

        ensureRole($db, $id);

        $q = $db->prepare("
            UPDATE mucho_account_roles
            SET role=?
            WHERE account_id=?
        ");

        $q->execute([$role, $id]);

        echo "ROLE_SET_OK\n";
        exit;
    }

    if ($cmd === '--verify') {
        $id = (int)($argv[2] ?? 0);
        $verified = (int)($argv[3] ?? -1);

        if ($id <= 0 || !in_array($verified, [0,1], true)) {
            throw new RuntimeException(
                'usage: --verify ACCOUNT_ID 0|1'
            );
        }

        ensureRole($db, $id);

        $q = $db->prepare("
            UPDATE mucho_account_roles
            SET verified=?
            WHERE account_id=?
        ");

        $q->execute([$verified, $id]);

        echo "VERIFIED_SET_OK\n";
        exit;
    }

    if ($cmd === '--show') {
        $id = (int)($argv[2] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException(
                'usage: --show ACCOUNT_ID'
            );
        }

        ensureRole($db, $id);

        $q = $db->prepare("
            SELECT
                account_id,
                role,
                verified,
                created_at,
                updated_at
            FROM mucho_account_roles
            WHERE account_id=?
        ");

        $q->execute([$id]);

        echo json_encode(
            $q->fetch(),
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit;
    }

    throw new RuntimeException(
        'commands: --install | --set | --verify | --show'
    );

} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
