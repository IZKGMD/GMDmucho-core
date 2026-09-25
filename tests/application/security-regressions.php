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
    str_contains($comment, "if (\n            \$cmd === '!rate'") &&
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
    str_contains($adminAccounts, "'role_id'=>(int)\$roleRow['id']"),
    'admin account-save uses the resolved role row id'
);

assertSecurityRegression(
    str_contains($caddy, '@admin_http') &&
    str_contains($caddy, 'protocol http') &&
    str_contains($caddy, 'path /admin /admin/*') &&
    str_contains($caddy, 'redir @admin_http https://{host}{uri} 308'),
    'admin panel redirects HTTP to HTTPS while game HTTP remains available'
);

$adminIndex = (string)file_get_contents(
    __DIR__ . '/../../public/admin/index.php'
);

$adminLevels = (string)file_get_contents(
    __DIR__ . '/../../public/admin/actions/levels.php'
);
$recoveryController = (string)file_get_contents(
    __DIR__ . '/../../src/Account/RecoveryController.php'
);

assertSecurityRegression(
    str_contains($adminIndex, "set_exception_handler('muchoAdminHandleException');") &&
    str_contains($adminIndex, 'The operation could not be completed. Please try again.'),
    'admin frontend masks unexpected exception details'
);

assertSecurityRegression(
    !str_contains($adminIndex, "flash(
            'Error: '.$e->getMessage()"),
    'admin frontend does not expose raw exception messages'
);

assertSecurityRegression(
    !str_contains($adminLevels, "'Error: '.$e->getMessage()") &&
    str_contains($adminLevels, 'The operation could not be completed. Please try again.'),
    'level management does not expose raw exception messages'
);

assertSecurityRegression(
    str_contains($recoveryController, 'MUCHO_ACCOUNT_URL') &&
    str_contains($recoveryController, 'client-controlled') &&
    !str_contains($recoveryController, "$_SERVER['HTTP_HOST']"),
    'recovery links never derive their origin from the Host header'
);

assertSecurityRegression(
    str_contains($adminIndex, "set_exception_handler('muchoAdminHandleException');") &&
    str_contains($adminIndex, 'The administrator operation could not be completed. Try again later.'),
    'admin uncaught exceptions use a generic error page'
);

assertSecurityRegression(
    str_contains($adminLevels, 'The operation could not be completed. Please try again.'),
    'admin level action masks internal exceptions'
);

$frontController = (string)file_get_contents(
    __DIR__ . '/../../public/index.php'
);

assertSecurityRegression(
    str_contains($frontController, '[MuchoCore FrontController]') &&
    str_contains($frontController, "header('Cache-Control', 'no-store');"),
    'front controller logs failures without exposing exception details'
);

assertSecurityRegression(
    str_contains($adminIndex, "if (admin() && isset($_GET['download'])) {\n    requireRank(40);"),
    'backup downloads require owner-level admin access'
);

assertSecurityRegression(
    str_contains($adminIndex, "elseif(\$page==='database') {\n\nrequireRank(40);"),
    'database browser requires owner-level admin access'
);

echo "MUCHOCORE_SECURITY_REGRESSIONS_OK\n";
