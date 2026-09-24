<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Http/ClientIp.php';
require_once dirname(__DIR__) . '/src/Http/Request.php';
require_once dirname(__DIR__) . '/src/Security/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Security/MuchoProtect.php';

use MuchoCore\Http\Request;
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

foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

echo "MuchoProtect tests passed\n";
