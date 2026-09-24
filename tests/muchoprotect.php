<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Http/ClientIp.php';
require_once dirname(__DIR__) . '/src/Compatibility/ClientVersion.php';
require_once dirname(__DIR__) . '/src/Http/Request.php';
require_once dirname(__DIR__) . '/src/Routing/Router.php';
require_once dirname(__DIR__) . '/src/Security/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Security/MuchoProtect.php';

use MuchoCore\Http\Request;
use MuchoCore\Routing\Router;
use MuchoCore\Security\MuchoProtect;
use MuchoCore\Security\RateLimiter;

$dir = sys_get_temp_dir() . '/muchocore-protect-test-' . bin2hex(random_bytes(6));
$protect = new MuchoProtect(new RateLimiter($dir));
putenv('MUCHO_PROTECT=1');

$request = new Request(
    method: 'POST',
    path: '/uploadGJComment21.php',
    query: [],
    post: ['accountID' => '123'],
    server: ['REMOTE_ADDR' => '127.0.0.77']
);

$allowed = 0;
$blocked = 0;

for ($i = 0; $i < 10; $i++) {
    $result = $protect->inspect($request, '/uploadGJComment21.php');
    $result['decision'] === 'allow' ? $allowed++ : $blocked++;
}

if ($allowed !== 8 || $blocked !== 2) {
    fwrite(STDERR, "MuchoProtect policy test failed: allowed={$allowed}, blocked={$blocked}\n");
    exit(1);
}

$exempt = $protect->inspect(
    new Request('GET', '/health', [], [], ['REMOTE_ADDR' => '127.0.0.77']),
    '/health'
);

if ($exempt['decision'] !== 'allow') {
    fwrite(STDERR, "MuchoProtect health exemption failed\n");
    exit(1);
}

$isolated = $protect->inspect(
    new Request(
        'POST',
        '/uploadGJComment21.php',
        [],
        ['accountID' => '999'],
        ['REMOTE_ADDR' => '127.0.0.78']
    ),
    '/uploadGJComment21.php'
);

if ($isolated['decision'] !== 'allow') {
    fwrite(STDERR, "MuchoProtect account/IP isolation test failed\n");
    exit(1);
}

$unlisted = $protect->inspect(
    new Request(
        'GET',
        '/getGJUserInfo20.php',
        [],
        [],
        ['REMOTE_ADDR' => '127.0.0.79']
    ),
    '/getGJUserInfo20.php'
);

if ($unlisted['decision'] !== 'allow' || $unlisted['reason'] !== 'no_policy') {
    fwrite(STDERR, "MuchoProtect default read-path policy failed\n");
    exit(1);
}

/*
 * Verify 2.2 credentials are recognized through gjp2 and account
 * protection remains bound to account + credential, not accountID alone.
 */
$accountProtect = new MuchoProtect(
    new RateLimiter($dir . '-account')
);
$loginEndpoint = (new Router())->normalizePath('/loginGJAccount22.php');

for ($i = 0; $i < 12; $i++) {
    $ip = '10.20.0.' . (intdiv($i, 3) + 1);

    $result = $accountProtect->inspect(
        new Request(
            'POST',
            '/loginGJAccount22.php',
            [],
            [
                'accountID' => '456',
                'gameVersion' => '22',
                'binaryVersion' => '42',
                'gjp2' => 'credential-A',
            ],
            ['REMOTE_ADDR' => $ip]
        ),
        '/loginGJAccount22.php'
    );

    if ($result['decision'] !== 'allow') {
        fwrite(STDERR, "MuchoProtect 2.2 account setup failed at request {$i}\n");
        exit(1);
    }
}

$accountBlocked = $accountProtect->inspect(
    new Request(
        'POST',
        '/loginGJAccount22.php',
        [],
        [
            'accountID' => '456',
            'gameVersion' => '22',
            'binaryVersion' => '42',
            'gjp2' => 'credential-A',
        ],
        ['REMOTE_ADDR' => '10.20.1.50']
    ),
    '/loginGJAccount22.php'
);

if ($accountBlocked['decision'] !== 'block' || $accountBlocked['reason'] !== 'account_rate_limit') {
    fwrite(
        STDERR,
        "MuchoProtect account fingerprint / GJP2 test failed: "
        . json_encode($accountBlocked, JSON_UNESCAPED_SLASHES)
        . "\n"
    );
    exit(1);
}

$differentCredential = $accountProtect->inspect(
    new Request(
        'POST',
        '/loginGJAccount22.php',
        [],
        [
            'accountID' => '456',
            'gameVersion' => '22',
            'binaryVersion' => '42',
            'gjp2' => 'credential-B',
        ],
        ['REMOTE_ADDR' => '10.20.1.51']
    ),
    '/loginGJAccount22.php'
);

if ($differentCredential['decision'] !== 'allow') {
    fwrite(STDERR, "MuchoProtect credential isolation test failed\n");
    exit(1);
}

foreach ([$dir, $dir . '-account'] as $cleanupDir) {
    foreach (glob($cleanupDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($cleanupDir);
}

echo "MuchoProtect tests passed\n";
