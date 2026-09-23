<?php
declare(strict_types=1);

/*
 * MuchoCore Roles CLI
 * Canonical source: accounts.role_id -> roles.code.
 * Copyright (C) 2026 IZK
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;
use MuchoCore\User\GameRole;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const ROLE_ALIASES = [
    'PLAYER' => GameRole::USER,
    'USER' => GameRole::USER,
    'MODERATOR' => GameRole::MODERATOR,
    'ELDER_MODERATOR' => GameRole::ELDER_MODERATOR,
    'OWNER' => GameRole::OWNER,
];

function ensureAccount(PDO $db, int $id): void
{
    $q = $db->prepare(
        'SELECT account_id
         FROM accounts
         WHERE account_id=:id
         LIMIT 1'
    );

    $q->execute(['id' => $id]);

    if ($q->fetchColumn() === false) {
        throw new RuntimeException('account_not_found');
    }
}

function setRole(PDO $db, int $id, string $role): void
{
    ensureAccount($db, $id);

    $canonical = ROLE_ALIASES[$role] ?? null;

    if ($canonical === null) {
        throw new RuntimeException(
            'usage: --set ACCOUNT_ID PLAYER|MODERATOR|ELDER_MODERATOR|OWNER'
        );
    }

    $q = $db->prepare(
        'SELECT id
         FROM roles
         WHERE code=:role
         LIMIT 1'
    );
    $q->execute(['role' => $canonical]);

    $roleId = $q->fetchColumn();

    if ($roleId === false) {
        throw new RuntimeException('role_not_found');
    }

    $q = $db->prepare(
        'UPDATE accounts
         SET role_id=:role_id
         WHERE account_id=:id'
    );
    $q->execute([
        'role_id' => (int)$roleId,
        'id' => $id,
    ]);

    echo "ROLE_SET_OK
";
}

function showRole(PDO $db, int $id): void
{
    ensureAccount($db, $id);

    $q = $db->prepare(
        'SELECT
            a.account_id,
            a.username,
            COALESCE(r.code, :fallback) AS role_code,
            COALESCE(r.name, :fallback_name) AS role_name,
            COALESCE(r.priority, 0) AS priority
         FROM accounts a
         LEFT JOIN roles r ON r.id=a.role_id
         WHERE a.account_id=:id
         LIMIT 1'
    );

    $q->execute([
        'id' => $id,
        'fallback' => GameRole::USER,
        'fallback_name' => GameRole::displayName(GameRole::USER),
    ]);

    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('account_not_found');
    }

    echo json_encode(
        $row,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
}

try {
    $db = (new Database())->connection();

    $cmd = $argv[1] ?? '';

    if ($cmd === '--install') {
        $count = (int)$db->query(
            'SELECT COUNT(*)
             FROM roles
             WHERE code IN (
                "user",
                "moderator",
                "elder_moderator",
                "owner"
             )'
        )->fetchColumn();

        if ($count !== 4) {
            throw new RuntimeException('canonical_roles_missing');
        }

        echo "ROLES_INSTALL_OK
";
        exit;
    }

    if ($cmd === '--set') {
        $id = (int)($argv[2] ?? 0);
        $role = strtoupper(trim((string)($argv[3] ?? '')));

        if ($id <= 0) {
            throw new RuntimeException(
                'usage: --set ACCOUNT_ID PLAYER|MODERATOR|ELDER_MODERATOR|OWNER'
            );
        }

        setRole($db, $id, $role);
        exit;
    }

    if ($cmd === '--show') {
        $id = (int)($argv[2] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException(
                'usage: --show ACCOUNT_ID'
            );
        }

        showRole($db, $id);
        exit;
    }

    throw new RuntimeException(
        'commands: --install | --set | --show'
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
