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

/*
 * Pre-auth identity protection: login attempts for one username remain
 * throttled even when the attacker rotates source IPs.
 */
$preAuthDir = $dir . '-preauth';
$preAuthProtect = new MuchoProtect(
    new RateLimiter($preAuthDir),
    new \MuchoCore\Security\AbusePenaltyStore($preAuthDir . '-penalty')
);
$loginSubject = '/loginGJAccount';

for ($i = 0; $i < 12; $i++) {
    $result = $preAuthProtect->inspect(
        new Request(
            'POST',
            '/loginGJAccount22.php',
            [],
            [
                'userName' => 'TargetPlayer',
                'password' => 'wrong-password',
                'gameVersion' => '22',
                'binaryVersion' => '42',
            ],
            ['REMOTE_ADDR' => '198.51.100.' . ($i + 1)]
        ),
        $loginSubject
    );

    if ($result['decision'] !== 'allow') {
        fwrite(STDERR, "MuchoProtect pre-auth username setup failed at request {$i}\n");
        exit(1);
    }
}

$preAuthBlocked = $preAuthProtect->inspect(
    new Request(
        'POST',
        '/loginGJAccount22.php',
        [],
        [
            'userName' => 'TargetPlayer',
            'password' => 'wrong-password',
            'gameVersion' => '22',
            'binaryVersion' => '42',
        ],
        ['REMOTE_ADDR' => '198.51.100.200']
    ),
    $loginSubject
);

if (
    $preAuthBlocked['decision'] !== 'block' ||
    $preAuthBlocked['reason'] !== 'username_rate_limit'
) {
    fwrite(
        STDERR,
        "MuchoProtect pre-auth username limit failed: "
        . json_encode($preAuthBlocked, JSON_UNESCAPED_SLASHES)
        . "\n"
    );
    exit(1);
}

/*
 * Legacy clients without credentials can still be throttled by a stable
 * device identity (UDID) while source IP changes.
 */
$deviceBlocked = $preAuthProtect->inspect(
    new Request(
        'POST',
        '/uploadGJLevel21.php',
        [],
        [
            'udid' => 'legacy-device-123',
        ],
        ['REMOTE_ADDR' => '203.0.113.200']
    ),
    '/uploadGJLevel21'
);
if ($deviceBlocked['decision'] !== 'allow') {
    fwrite(STDERR, "MuchoProtect first device request unexpectedly blocked\n");
    exit(1);
}

for ($i = 0; $i < 7; $i++) {
    $preAuthProtect->inspect(
        new Request(
            'POST',
            '/uploadGJLevel21.php',
            [],
            [
                'udid' => 'legacy-device-123',
            ],
            ['REMOTE_ADDR' => '203.0.113.' . ($i + 1)]
        ),
        '/uploadGJLevel21'
    );
}

$deviceLimit = $preAuthProtect->inspect(
    new Request(
        'POST',
        '/uploadGJLevel21.php',
        [],
        ['udid' => 'legacy-device-123'],
        ['REMOTE_ADDR' => '203.0.113.250']
    ),
    '/uploadGJLevel21'
);

if (
    $deviceLimit['decision'] !== 'block' ||
    $deviceLimit['reason'] !== 'device_rate_limit'
) {
    fwrite(
        STDERR,
        "MuchoProtect device identity limit failed: "
        . json_encode($deviceLimit, JSON_UNESCAPED_SLASHES)
        . "\n"
    );
    exit(1);
}

/*
 * API v2 uses the same policy engine. Music uploads keep their legacy
 * account-wide 5-per-15-minute security budget while source IPs rotate.
 */
$v2Dir = $dir . '-v2';
$v2Protect = new MuchoProtect(
    new RateLimiter($v2Dir),
    new \MuchoCore\Security\AbusePenaltyStore($v2Dir . '-penalty')
);

for ($i = 0; $i < 5; $i++) {
    $result = $v2Protect->inspect(
        new Request(
            'POST',
            '/api/v2/music-upload.php',
            [],
            [
                'accountID' => '888',
                'gjp2' => 'music-account-credential',
            ],
            ['REMOTE_ADDR' => '198.' . (20 + $i) . '.10.5']
        ),
        '/api/v2/music-upload.php'
    );

    if ($result['decision'] !== 'allow') {
        fwrite(STDERR, "MuchoProtect v2 music account setup failed at request {$i}\\n");
        exit(1);
    }
}

$v2Blocked = $v2Protect->inspect(
    new Request(
        'POST',
        '/api/v2/music-upload.php',
        [],
        [
            'accountID' => '888',
            'gjp2' => 'music-account-credential',
        ],
        ['REMOTE_ADDR' => '198.30.10.5']
    ),
    '/api/v2/music-upload.php'
);

if (
    $v2Blocked['decision'] !== 'block' ||
    $v2Blocked['reason'] !== 'account_rate_limit'
) {
    fwrite(
        STDERR,
        "MuchoProtect v2 central policy failed: "
        . json_encode($v2Blocked, JSON_UNESCAPED_SLASHES)
        . "\\n"
    );
    exit(1);
}

/*
 * Network rotation guard: multiple IPs inside one IPv4 /24 share a wider
 * endpoint budget. Once the shared burst is exceeded, the penalty follows
 * the network prefix and blocks the next rotated IP too.
 */
$networkDir = $dir . '-network';
$networkProtect = new MuchoProtect(
    new RateLimiter($networkDir),
    new \MuchoCore\Security\AbusePenaltyStore($networkDir . '-penalty')
);

$networkPath = '/uploadGJLevel21';

foreach (range(1, 9) as $index) {
    $octet = (($index - 1) % 3) + 1;

    $result = $networkProtect->inspect(
        new Request(
            'POST',
            '/uploadGJLevel21.php',
            [],
            [],
            ['REMOTE_ADDR' => '198.18.44.' . $octet]
        ),
        $networkPath
    );

    if ($result['decision'] !== 'allow') {
        fwrite(STDERR, "MuchoProtect network rotation setup failed at request {$index}\\n");
        exit(1);
    }
}

$networkBlocked = $networkProtect->inspect(
    new Request(
        'POST',
        '/uploadGJLevel21.php',
        [],
        [],
        ['REMOTE_ADDR' => '198.18.44.50']
    ),
    $networkPath
);

if (
    $networkBlocked['decision'] !== 'block' ||
    $networkBlocked['reason'] !== 'network_burst_limit'
) {
    fwrite(
        STDERR,
        "MuchoProtect network rotation guard failed: "
        . json_encode($networkBlocked, JSON_UNESCAPED_SLASHES)
        . "\\n"
    );
    exit(1);
}

$networkPenalty = $networkProtect->inspect(
    new Request(
        'POST',
        '/uploadGJLevel21.php',
        [],
        [],
        ['REMOTE_ADDR' => '198.18.44.51']
    ),
    $networkPath
);

if (
    $networkPenalty['decision'] !== 'block' ||
    $networkPenalty['reason'] !== 'network_penalty'
) {
    fwrite(
        STDERR,
        "MuchoProtect network penalty escalation failed: "
        . json_encode($networkPenalty, JSON_UNESCAPED_SLASHES)
        . "\\n"
    );
    exit(1);
}

/*
 * Clan routes now have explicit limits instead of falling back to global
 * protection only.
 */
$clanProtect = new MuchoProtect(
    new RateLimiter($dir . '-clan'),
    new \MuchoCore\Security\AbusePenaltyStore($dir . '-clan-penalty')
);
$clanRequest = new Request(
    'POST',
    '/api/clans/create',
    [],
    [
        'accountID' => '777',
        'gjp2' => 'credential-clan',
    ],
    ['REMOTE_ADDR' => '192.0.2.220']
);

for ($i = 0; $i < 2; $i++) {
    $result = $clanProtect->inspect($clanRequest, '/api/clans/create');
    if ($result['decision'] !== 'allow') {
        fwrite(STDERR, "MuchoProtect clan create policy failed at burst request {$i}\n");
        exit(1);
    }
}

$clanBlocked = $clanProtect->inspect($clanRequest, '/api/clans/create');
if (
    $clanBlocked['decision'] !== 'block' ||
    $clanBlocked['reason'] !== 'burst_limit'
) {
    fwrite(
        STDERR,
        "MuchoProtect clan create burst limit failed: "
        . json_encode($clanBlocked, JSON_UNESCAPED_SLASHES)
        . "\n"
    );
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

/*
 * Storage hygiene: stale limiter and penalty files are removable without
 * touching fresh state, and cleanup is bounded by the requested entry count.
 */
$rateCleanupDir = $dir . '-rate-cleanup';
if (!is_dir($rateCleanupDir) && !mkdir($rateCleanupDir, 0700, true)) {
    fwrite(STDERR, "Unable to create rate cleanup fixture\n");
    exit(1);
}

$staleRateFile = $rateCleanupDir . '/' . hash('sha256', 'stale-rate') . '.json';
$freshRateFile = $rateCleanupDir . '/' . hash('sha256', 'fresh-rate') . '.json';

file_put_contents($staleRateFile, '{"start":1,"count":1}');
file_put_contents($freshRateFile, '{"start":1,"count":1}');
touch($staleRateFile, time() - 7200);
touch($freshRateFile, time());

$rateCleanup = new RateLimiter($rateCleanupDir);
if ($rateCleanup->cleanup(3600, 1) !== 1 || is_file($staleRateFile) || !is_file($freshRateFile)) {
    fwrite(STDERR, "RateLimiter stale cleanup contract failed\n");
    exit(1);
}

$penaltyCleanupDir = $dir . '-penalty-cleanup';
if (!is_dir($penaltyCleanupDir) && !mkdir($penaltyCleanupDir, 0700, true)) {
    fwrite(STDERR, "Unable to create penalty cleanup fixture\n");
    exit(1);
}

$stalePenaltyFile = $penaltyCleanupDir . '/' . hash('sha256', 'stale-penalty') . '.json';
$freshPenaltyFile = $penaltyCleanupDir . '/' . hash('sha256', 'fresh-penalty') . '.json';

file_put_contents($stalePenaltyFile, '{"expires":1,"last":1,"strikes":1}');
file_put_contents($freshPenaltyFile, '{"expires":1,"last":1,"strikes":1}');
touch($stalePenaltyFile, time() - 7200);
touch($freshPenaltyFile, time());

$penaltyCleanup = new MuchoCoreSecurityAbusePenaltyStore($penaltyCleanupDir);
if ($penaltyCleanup->cleanup(3600, 1) !== 1 || is_file($stalePenaltyFile) || !is_file($freshPenaltyFile)) {
    fwrite(STDERR, "AbusePenaltyStore stale cleanup contract failed\n");
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
    $preAuthDir,
    $preAuthDir . '-penalty',
    $dir . '-clan',
    $dir . '-clan-penalty',
    $networkDir,
    $networkDir . '-penalty',
    $rateCleanupDir,
    $penaltyCleanupDir,
    $v2Dir,
    $v2Dir . '-penalty',
    $dir . '-global',
    $directPenaltyDir,
] as $cleanupDir) {
    foreach (glob($cleanupDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($cleanupDir);
}

echo "MuchoProtect tests passed\n";
