<?php

declare(strict_types=1);

function assertSecurityRegression(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$comment = (string)file_get_contents(
    __DIR__ . '/../../src/Interaction/CommentService.php'
);
$recovery = (string)file_get_contents(
    __DIR__ . '/../../src/Account/RecoveryRepository.php'
);
$adminAccounts = (string)file_get_contents(
    __DIR__ . '/../../public/admin/actions/accounts.php'
);
$caddy = (string)file_get_contents(
    __DIR__ . '/../../docker/Caddyfile'
);

assertSecurityRegression(
    str_contains($comment, 'if (\n            $cmd === \'!rate\'') &&
    str_contains($comment, 'GameRole::OWNER') &&
    str_contains($comment, 'GameRole::ELDER_MODERATOR'),
    'direct !rate command has an elder-moderator/owner authorization gate'
);

assertSecurityRegression(
    str_contains($recovery, 'DELETE FROM mucho_legacy_19_sessions') &&
    str_contains($recovery, 'WHERE account_id = :account_id'),
    'account recovery revokes legacy 1.9 upload sessions'
);

assertSecurityRegression(
    str_contains($adminAccounts, 'DELETE FROM mucho_legacy_19_sessions') &&
    str_contains($adminAccounts, 'WHERE account_id=:id'),
    'admin password reset revokes legacy 1.9 upload sessions'
);

assertSecurityRegression(
    str_contains($adminAccounts, "'role_id'=>(int)$roleRow['id']"),
    'admin account-save uses the resolved role row id'
);

assertSecurityRegression(
    str_contains($caddy, '@admin_http') &&
    str_contains($caddy, 'protocol http') &&
    str_contains($caddy, 'path /admin /admin/*') &&
    str_contains($caddy, 'redir @admin_http https://{host}{uri} 308'),
    'admin panel redirects HTTP to HTTPS while game HTTP remains available'
);

echo "MUCHOCORE_SECURITY_REGRESSIONS_OK\n";
