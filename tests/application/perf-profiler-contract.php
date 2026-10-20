<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Diagnostics\RequestProfiler;
use MuchoCore\Http\Response;

function perfCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $message);
    }
}

$log = tempnam(sys_get_temp_dir(), 'muchocore-profiler-');
if ($log === false) {
    throw new RuntimeException('Could not create profiler log fixture.');
}
$previousLog = ini_set('error_log', $log);

try {
    // The opt-in flag is supplied by Docker Compose in production; no
    // mutation of application .env or sensitive files is needed in this test.
    putenv('MUCHO_PERF_PROFILE=1');
    $_ENV['MUCHO_PERF_PROFILE'] = '1';

    RequestProfiler::begin('/health', hrtime(true));
    perfCheck(!RequestProfiler::enabled(), 'other routes never profile');

    RequestProfiler::begin('/getgjlevels21', hrtime(true) - 1_000_000);
    perfCheck(RequestProfiler::enabled(), 'explicitly enabled level-list route profiles');
    $sqlStart = RequestProfiler::clock();
    perfCheck($sqlStart > 0, 'monotonic timing clock available');

    RequestProfiler::phase('protection');
    RequestProfiler::phase('app_init');
    RequestProfiler::cacheHit(true);
    RequestProfiler::observe('sql_levels', $sqlStart);
    RequestProfiler::observe('password=exfiltrate', $sqlStart);
    RequestProfiler::response(Response::text('FAKE_SECRET_GD_RESPONSE'));
    RequestProfiler::finish();
    RequestProfiler::finish();

    $text = (string)file_get_contents($log);
    perfCheck(substr_count($text, '[MuchoCore Perf] ') === 1, 'one JSON line per request');
    $index = strpos($text, '[MuchoCore Perf] ');
    perfCheck($index !== false, 'profile marker present');
    $json = trim(substr($text, $index + strlen('[MuchoCore Perf] ')));
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    perfCheck(($data['route'] ?? '') === 'getGJLevels21', 'route fixed to allowlist');
    perfCheck(($data['result'] ?? '') === 'data', 'only wire result classification logged');
    perfCheck(($data['status'] ?? null) === 200, 'status is preserved');
    perfCheck(($data['cache_hit'] ?? null) === true, 'cache hit recorded');
    perfCheck(isset($data['bootstrap_ms'], $data['protection_ms'],
        $data['app_init_ms'], $data['handler_ms'], $data['sql_levels_ms']),
        'expected timings present');
    perfCheck(($data['total_ms'] ?? 0) > 0, 'end-to-end PHP timing present');
    perfCheck(!str_contains($text, 'FAKE_SECRET_GD_RESPONSE')
        && !str_contains($text, 'password=exfiltrate'),
        'response bodies and arbitrary metric names are never logged');
} finally {
    if (is_string($previousLog)) {
        ini_set('error_log', $previousLog);
    }
    putenv('MUCHO_PERF_PROFILE');
    unset($_ENV['MUCHO_PERF_PROFILE']);
    @unlink($log);
}

echo "MUCHOCORE_REQUEST_PROFILER_OK\n";
