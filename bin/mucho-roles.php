#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * MuchoCore Roles CLI
 * Copyright (C) 2026 IZK
 *
 * Compatibility wrapper for the normalized roles/accounts.role_id schema.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

const ROLES = [
    'user',
    'moderator',
    'admin',
    'owner',
];

function usage(): never
{
    echo <<<TXT
MuchoCore Roles

Commands:
  mucho-roles --install
  mucho-roles --set ACCOUNT_ID|USERNAME ROLE
  mucho-roles --show ACCOUNT_ID|USERNAME

Legacy aliases:
  mod, helper, elder -> moderator

TXT;
    exit(1);
}

function accountId(PDO $db, string $target): int
{
    if (ctype_digit($target)) {
        return (int)$target;
    }

    $q = $db->prepare(
        'SELECT account_id FROM accounts
         WHERE username=:username
         LIMIT 1'
    );
    $q->execute(['username' => $target]);

    return (int)($q->fetchColumn() ?: 0);
}

function roleId(PDO $db, string $role): int
{
    $q = $db->prepare(
        'SELECT id FROM roles
         WHERE code=:role
         LIMIT 1'
    );
    $q->execute(['role' => $role]);

    return (int)($q->fetchColumn() ?: 0);
}

try {
    if (PHP_SAPI !== 'cli') {
        usage();
    }

    $db = (new Database())->connection();
    $command = (string)($argv[1] ?? '');

    if ($command === '--install') {
        $count = (int)$db->query(
            'SELECT COUNT(*) FROM roles
             WHERE code IN ("user","moderator","admin","owner")'
        )->fetchColumn();

        if ($count !== 4) {
            throw new RuntimeException(
                'Normalized role schema is incomplete.'
            );
        }

        echo "ROLES_INSTALL_OK
";
        exit(0);
    }

    if ($command === '--set') {
        $target = trim((string)($argv[2] ?? ''));
        $role = strtolower(trim((string)($argv[3] ?? '')));

        $role = [
            'mod' => 'moderator',
            'helper' => 'moderator',
            'elder' => 'moderator',
        ][$role] ?? $role;

        if (
            $target === '' ||
            !in_array($role, ROLES, true)
        ) {
            usage();
        }

        $id = accountId($db, $target);
        $roleId = roleId($db, $role);

        if ($id <= 0) {
            throw new RuntimeException('Account not found.');
        }

        if ($roleId <= 0) {
            throw new RuntimeException(
                "Role '{$role}' is not installed."
            );
        }

        $q = $db->prepare(
            'UPDATE accounts
             SET role_id=:role_id
             WHERE account_id=:account_id'
        );
        $q->execute([
            'role_id' => $roleId,
            'account_id' => $id,
        ]);

        echo "ROLE_SET_OK
";
        exit(0);
    }

    if ($command === '--show') {
        $target = trim((string)($argv[2] ?? ''));

        if ($target === '') {
            usage();
        }

        $q = $db->prepare(
            'SELECT
                a.account_id,
                a.username,
                COALESCE(r.code,"user") AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id=a.role_id
             WHERE a.account_id=:id
             LIMIT 1'
        );
        $q->execute([
            'id' => accountId($db, $target),
        ]);

        $row = $q->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Account not found.');
        }

        echo json_encode(
            $row,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit(0);
    }

    usage();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
