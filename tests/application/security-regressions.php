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
    str_contains($caddy, 'not header X-Forwarded-Proto https') &&
    str_contains($caddy, 'redir @admin_http https://{host}{uri} 308'),
    'admin panel redirects direct HTTP without looping behind TLS-terminating proxies'
);

$adminIndex = (string)file_get_contents(
    __DIR__ . '/../../public/admin/index.php'
);

$releaseModule = (string)file_get_contents(
    __DIR__ . '/../../public/admin/client-release-upload-module.php'
);

$releasePost = strpos(
    $releaseModule,
    "if (\$_SERVER['REQUEST_METHOD']==='POST')"
);

$releaseStorageCall = strpos(
    $releaseModule,
    'ensureReleaseManager($db);'
);

assertSecurityRegression(
    $releasePost !== false &&
    $releaseStorageCall !== false &&
    $releaseStorageCall > $releasePost &&
    preg_match(
        '/ensureReleaseManager\\(\\$db\\);\\s*checkCsrf\\(\\);/',
        $releaseModule
    ) === 1,
    'client release storage initialization is deferred to release upload actions'
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
$v2Security = (string)file_get_contents(
    __DIR__ . '/../../public/api/v2/security.php'
);
$v2MusicUpload = (string)file_get_contents(
    __DIR__ . '/../../public/api/v2/music-upload.php'
);

assertSecurityRegression(
    str_contains($v2Security, '\\MuchoCore\\Security\\MuchoProtect') &&
    str_contains($v2Security, 'MuchoCore\\Http\\Request::fromGlobals') &&
    !str_contains($v2Security, 'mucho_api_rate_limits'),
    'API v2 rate limiting is routed through the central MuchoProtect engine'
);

assertSecurityRegression(
    !str_contains($v2MusicUpload, 'new \\MuchoCore\\Security\\RateLimiter') &&
    str_contains($v2Security, "'account_rate_limit' => 900"),
    'music upload uses the central account identity window without a second limiter'
);

assertSecurityRegression(
    str_contains(
        (string)file_get_contents(__DIR__ . '/../../src/Security/RateLimiter.php'),
        'function cleanup('
    ) &&
    str_contains(
        (string)file_get_contents(__DIR__ . '/../../src/Security/AbusePenaltyStore.php'),
        'function cleanup('
    ),
    'security storage exposes bounded stale-state cleanup'
);


assertSecurityRegression(
    str_contains(
        $legacyLevelTransfer,
        "effectiveGameVersion() > 0"
    ) &&
    str_contains(
        $legacyLevelTransfer,
        "effectiveGameVersion() < 19"
    ) &&
    str_contains(
        $legacyLevelTransfer,
        'trim($udid) !== \'\''
    ) &&
    str_contains(
        $legacyLevelTransfer,
        "credential === ''"
    ),
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
    str_contains($v2Security, 'MuchoProtect') &&
    str_contains($musicUpload, 'MUCHO_PUBLIC_URL') &&
    !str_contains($musicUpload, 'HTTP_HOST'),
    'music upload is covered by central MuchoProtect and uses trusted public origin'
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

$installer = (string)file_get_contents(
    __DIR__ . '/../../install.sh'
);
$recoveryService = (string)file_get_contents(
    __DIR__ . '/../../src/Account/RecoveryService.php'
);

assertSecurityRegression(
    str_contains($installer, 'Admin username: $ADMIN_USER') &&
    str_contains($installer, 'The admin panel username is: $ADMIN_USER'),
    'installer reports the configured administrator username'
);

assertSecurityRegression(
    str_contains($recoveryService, 'MuchoCore — Account recovery') &&
    str_contains($recoveryService, '<html lang="en">') &&
    str_contains($recoveryService, 'Hello, {$username}!'),
    'account recovery email copy uses the English project language'
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
