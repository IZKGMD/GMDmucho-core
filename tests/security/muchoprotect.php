<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Http/ClientIp.php';
require_once dirname(__DIR__, 2) . '/src/Compatibility/ClientVersion.php';
require_once dirname(__DIR__, 2) . '/src/Http/Request.php';
require_once dirname(__DIR__, 2) . '/src/Routing/Router.php';
require_once dirname(__DIR__, 2) . '/src/Security/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/src/Security/AbusePenaltyStore.php';
require_once dirname(__DIR__, 2) . '/src/Security/MuchoProtect.php';

use MuchoCore\Http\Request;
use MuchoCore\Routing\Router;
use MuchoCore\Security\MuchoProtect;
use MuchoCore\Security\RateLimiter;

$dir = sys_get_temp_dir() . '/muchocore-protect-test-' . bin2hex(random_bytes(6));
$mainPenaltyDir = $dir . '-main-penalty';
$protect = new MuchoProtect(
    new RateLimiter($dir),
    new \MuchoCore\Security\AbusePenaltyStore($mainPenaltyDir)
);
putenv('MUCHO_PROTECT=1');
$_ENV['MUCHO_PROTECT'] = '1';

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

$penaltyDir = $dir . '-penalty';
$penaltyProtect = new MuchoProtect(
    new RateLimiter($penaltyDir),
    new \MuchoCore\Security\AbusePenaltyStore($penaltyDir)
);
$penaltyRequest = new Request(
    'POST',
    '/uploadGJComment21.php',
    [],
    ['accountID' => '123'],
    ['REMOTE_ADDR' => '127.0.0.90']
);
for ($i = 0; $i < 8; $i++) {
    $penaltyProtect->inspect($penaltyRequest, '/uploadGJComment21.php');
}
$firstPenalty = $penaltyProtect->inspect($penaltyRequest, '/uploadGJComment21.php');
if ($firstPenalty['decision'] !== 'block') {
    fwrite(STDERR, "MuchoProtect penalty activation failed\n");
    exit(1);
}
$secondPenalty = $penaltyProtect->inspect($penaltyRequest, '/uploadGJComment21.php');
if ($secondPenalty['decision'] !== 'block' || $secondPenalty['reason'] !== 'temporary_penalty') {
    fwrite(STDERR, "MuchoProtect temporary penalty enforcement failed\n");
    exit(1);
}

$globalDir = $dir . '-global';
$globalProtect = new MuchoProtect(
    new RateLimiter($globalDir),
    new \MuchoCore\Security\AbusePenaltyStore($globalDir)
);
$globalRequest = new Request(
    'GET',
    '/getGJUserInfo20.php',
    [],
    [],
    ['REMOTE_ADDR' => '127.0.0.91']
);
for ($i = 0; $i < 180; $i++) {
    $globalProtect->inspect($globalRequest, '/getGJUserInfo20.php');
}
$globalBlocked = false;
for ($i = 0; $i < 2; $i++) {
    $result = $globalProtect->inspect($globalRequest, '/getGJUserInfo20.php');
    if ($result['decision'] === 'block') {
        $globalBlocked = true;
        break;
    }
}
if (!$globalBlocked) {
    fwrite(STDERR, "MuchoProtect global burst guard failed\n");
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

if ($unlisted['decision'] !== 'allow' || $unlisted['reason'] !== 'ok') {
    fwrite(STDERR, "MuchoProtect read-path policy failed\n");
    exit(1);
}

$coveredReads = [
    '/getGJCommentHistory.php',
    '/getGJLevelLists.php',
    '/getGJTopArtists.php',
    '/getGJLevels21.php',
    '/downloadGJLevel21.php',
    '/downloadGJLevel22.php',
    '/getGJSongInfo.php',
    '/getGJUserInfo20.php',
    '/getGJUsers20.php',
    '/getGJScores20.php',
    '/getGJComments21.php',
    '/getGJAccountComments20.php',
    '/getGJMessages20.php',
    '/downloadGJMessage20.php',
    '/getGJFriendRequests20.php',
    '/getGJUserList20.php',
    '/getGJCreators.php',
    '/getGJDailyLevel.php',
    '/getGJGauntlets21.php',
    '/getGJMapPacks21.php',
    '/getGJLevelScores211.php',
    '/getGJLevelScoresPlat.php',
    '/getGJRewards.php',
    '/getGJSecretReward.php',
    '/getGJChallenges.php',
];

foreach ($coveredReads as $index => $path) {
    $result = $protect->inspect(
        new Request(
            'GET',
            $path,
            [],
            [],
            ['REMOTE_ADDR' => '192.0.2.' . ($index + 1)]
        ),
        (new Router())->normalizePath($path)
    );

    if ($result['decision'] !== 'allow' || $result['reason'] !== 'ok') {
        fwrite(STDERR, "MuchoProtect read coverage failed for {$path}\n");
        exit(1);
    }
}

/*
 * Verify 2.2 credentials are recognized through gjp2 and account
 * protection remains bound to account + credential, not accountID alone.
 */
$accountDir = $dir . '-account';
$accountProtect = new MuchoProtect(
    new RateLimiter($accountDir),
    new \MuchoCore\Security\AbusePenaltyStore($accountDir . '-penalty')
);
$loginEndpoint = (new Router())->normalizePath('/loginGJAccount22.php');

for ($i = 0; $i < 12; $i++) {
    $ip = '10.20.0.' . (intdiv($i, 3) + 1);

    $result = $accountProtect->inspect(
        new Request(
            'POST',
            $loginEndpoint,
            [],
            [
                'accountID' => '456',
                'gameVersion' => '22',
                'binaryVersion' => '42',
                'gjp2' => 'credential-A',
            ],
            ['REMOTE_ADDR' => $ip]
        ),
        $loginEndpoint
    );

    if ($result['decision'] !== 'allow') {
        fwrite(STDERR, "MuchoProtect 2.2 account setup failed at request {$i}\n");
        exit(1);
    }
}

$accountBlocked = $accountProtect->inspect(
    new Request(
        'POST',
        $loginEndpoint,
        [],
        [
            'accountID' => '456',
            'gameVersion' => '22',
            'binaryVersion' => '42',
            'gjp2' => 'credential-A',
        ],
        ['REMOTE_ADDR' => '10.20.1.50']
    ),
    $loginEndpoint
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
        $loginEndpoint,
        [],
        [
            'accountID' => '456',
            'gameVersion' => '22',
            'binaryVersion' => '42',
            'gjp2' => 'credential-B',
        ],
        ['REMOTE_ADDR' => '10.20.1.51']
    ),
    $loginEndpoint
);

if ($differentCredential['decision'] !== 'allow') {
    fwrite(STDERR, "MuchoProtect credential isolation test failed\n");
    exit(1);
}

$strictFailurePath = $dir . '-strict-failure';
if (file_put_contents($strictFailurePath, 'x') === false) {
    fwrite(STDERR, "Unable to create strict limiter fixture\n");
    exit(1);
}
$strictLimiter = new RateLimiter($strictFailurePath);
if ($strictLimiter->allowStrict('strict-test', 1, 60) !== false) {
    fwrite(STDERR, "RateLimiter allowStrict did not fail closed\n");
    exit(1);
}
if ($strictLimiter->allow('strict-test', 1, 60) !== true) {
    fwrite(STDERR, "RateLimiter legacy fail-open contract changed unexpectedly\n");
    exit(1);
}
@unlink($strictFailurePath);

$strictProtectDir = $dir . '-protect-strict';
if (file_put_contents($strictProtectDir, 'x') === false) {
    fwrite(STDERR, "Unable to create strict protection fixture\n");
    exit(1);
}
$strictProtect = new MuchoProtect(
    new RateLimiter($strictProtectDir),
    new \MuchoCore\Security\AbusePenaltyStore($dir . '-strict-protect-penalty')
);
$strictResult = $strictProtect->inspect(
    new Request(
        'GET',
        '/getGJUserInfo20.php',
        [],
        [],
        ['REMOTE_ADDR' => '127.0.0.95']
    ),
    '/getGJUserInfo20.php'
);
if ($strictResult['decision'] !== 'block') {
    fwrite(STDERR, "MuchoProtect did not fail closed when limiter storage was unavailable\n");
    exit(1);
}
@unlink($strictProtectDir);

$penaltyStatusDir = $dir . '-status-only';
$statusProbe = new \MuchoCore\Security\AbusePenaltyStore($penaltyStatusDir);
$status = $statusProbe->status('never-penalized');
if ($status['active'] || is_dir($penaltyStatusDir)) {
    fwrite(STDERR, "MuchoProtect penalty status created unnecessary storage\n");
    exit(1);
}

$directPenaltyDir = $dir . '-backoff';
$directPenalties = new \MuchoCore\Security\AbusePenaltyStore($directPenaltyDir);
$first = $directPenalties->penalize('same-abuser');
$second = $directPenalties->penalize('same-abuser');
if (
    $first['seconds'] !== 15 ||
    $first['strikes'] !== 1 ||
    $second['seconds'] !== 30 ||
    $second['strikes'] !== 2
) {
    fwrite(STDERR, "MuchoProtect exponential penalty backoff failed\n");
    exit(1);
}

foreach ([
    $dir,
    $mainPenaltyDir,
    $accountDir,
    $accountDir . '-penalty',
    $dir . '-penalty',
    $dir . '-global',
    $directPenaltyDir,
] as $cleanupDir) {
    foreach (glob($cleanupDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($cleanupDir);
}

echo "MuchoProtect tests passed\n";
