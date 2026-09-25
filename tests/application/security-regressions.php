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

$rawExceptionUiPattern = "'Error: '" . '.$e->getMessage()';

assertSecurityRegression(
    !str_contains($adminLevels, $rawExceptionUiPattern) &&
    str_contains($adminLevels, 'The operation could not be completed. Please try again.'),
    'level management does not expose raw exception messages'
);

$hostHeaderExpression = '$_SERVER' . "['HTTP_HOST']";

assertSecurityRegression(
    str_contains($recoveryController, 'MUCHO_ACCOUNT_URL') &&
    str_contains($recoveryController, 'client-controlled') &&
    !str_contains($recoveryController, $hostHeaderExpression),
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

$adminActions = (string)file_get_contents(
    __DIR__ . '/../../public/admin/actions/admins.php'
);
$heartbeat = (string)file_get_contents(
    __DIR__ . '/../../public/api/v2/heartbeat.php'
);
$rewards = (string)file_get_contents(
    __DIR__ . '/../../src/Interaction/RewardsService.php'
);
$rewardsController = (string)file_get_contents(
    __DIR__ . '/../../src/Interaction/RewardsController.php'
);
$musicUpload = (string)file_get_contents(
    __DIR__ . '/../../public/api/v2/music-upload.php'
);
$legacyLevelTransfer = (string)file_get_contents(
    __DIR__ . '/../../src/Level/LevelTransferController.php'
);

assertSecurityRegression(
    str_contains($legacyLevelTransfer, 'in_array(') &&
    str_contains($legacyLevelTransfer, '[1, 19]') &&
    str_contains($legacyLevelTransfer, 'credential === \'\''),
    'GD 1.0 legacy UDID uploads are allowed without GJP credentials'
);

$iconProxy = (string)file_get_contents(
    __DIR__ . '/../../public/admin/icon-proxy.php'
);

$likeMigration = (string)file_get_contents(
    __DIR__ . '/../../database/migrations/20260925_003_like_identity_integrity.php'
);

assertSecurityRegression(
    str_contains($adminActions, 'mucho_admin_client_tokens') &&
    str_contains($adminActions, 'SET revoked_at=NOW()'),
    'admin security-state changes revoke API bearer tokens'
);

assertSecurityRegression(
    str_contains($heartbeat, 'a.is_active') &&
    str_contains($heartbeat, 'a.is_banned') &&
    str_contains($heartbeat, 'is_banned') &&
    str_contains($heartbeat, 'hash_equals'),
    'presence heartbeat rejects inactive or banned accounts'
);

assertSecurityRegression(
    str_contains($rewards, "hash('sha256', " . '$ip' . " . '|' . " . '$udid' . ")") &&
    str_contains($rewards, 'filter_var($ip, FILTER_VALIDATE_IP)'),
    'anonymous secret rewards bind claims to IP and UDID'
);

assertSecurityRegression(
    str_contains($rewardsController, '$request->clientIp()'),
    'secret reward controller passes the resolved client IP'
);

assertSecurityRegression(
    str_contains($musicUpload, 'allowStrict') &&
    str_contains($musicUpload, 'MUCHO_PUBLIC_URL') &&
    !str_contains($musicUpload, 'HTTP_HOST'),
    'music upload uses fail-closed limiting and trusted public origin'
);

assertSecurityRegression(
    str_contains($iconProxy, 'allowStrict') &&
    str_contains($iconProxy, '512000') &&
    str_contains($iconProxy, 'gdicon.oat.zone'),
    'public icon proxy is rate limited and response-size bounded'
);

assertSecurityRegression(
    str_contains($likeMigration, 'uq_like_item_user_ip') &&
    str_contains($likeMigration, '(item_id, type, account_id, ip)'),
    'anonymous like identity is keyed by IP without weakening authenticated uniqueness'
);

$frontController = (string)file_get_contents(
    __DIR__ . '/../../public/index.php'
);

assertSecurityRegression(
    str_contains($frontController, 'MuchoCore FrontController') &&
    preg_match("~Cache-Control['\\\"]?[^\\n]*no-store~", $frontController) === 1,
    'front controller logs failures without exposing exception details'
);

$backupDownloadGuard =
    str_contains($adminIndex, "if (admin() && isset(\$_GET['download']))") &&
    str_contains($adminIndex, 'requireRank(40);');

$databaseGuard =
    str_contains($adminIndex, "elseif(\$page==='database')") &&
    str_contains($adminIndex, 'requireRank(40);');

assertSecurityRegression(
    $backupDownloadGuard,
    'backup downloads require owner-level admin access'
);

assertSecurityRegression(
    $databaseGuard,
    'database browser requires owner-level admin access'
);

echo "MUCHOCORE_SECURITY_REGRESSIONS_OK\n";
