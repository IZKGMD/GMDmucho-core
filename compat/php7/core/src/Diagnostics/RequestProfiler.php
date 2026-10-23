<?php

declare (strict_types=1);
namespace MuchoCore\Diagnostics;

use MuchoCore\Core\Environment;
use MuchoCore\Http\Response;
/**
 * Temporary, opt-in timings for the public Geometry Dash level-list hot path.
 *
 * No request params, IPs, credentials, SQL strings or response bodies are logged.
 * Disabled by default; only the canonical getGJLevels21 route is eligible.
 */
final class RequestProfiler
{
    private static $active = false;
    private static $finished = false;
    private static $startNs = 0;
    private static $lastNs = 0;
    /** @var array<string,float> */
    private static $phases = [];
    /** @var array<string,float> */
    private static $details = [];
    private static $cacheHit = null;
    private static $result = 'incomplete';
    private static $httpStatus = 200;
    public static function begin(string $normalizedPath, int $entryStartNs)
    {
        if ($normalizedPath !== '/getgjlevels21' || Environment::get('MUCHO_PERF_PROFILE', '0') !== '1') {
            return;
        }
        self::$active = true;
        self::$finished = false;
        self::$startNs = $entryStartNs;
        self::$lastNs = hrtime(true);
        self::$phases = ['bootstrap_ms' => self::millis(self::$lastNs - self::$startNs)];
        self::$details = [];
        self::$cacheHit = null;
        self::$result = 'incomplete';
        self::$httpStatus = 200;
        // Also cover a blocked request or exception path that calls exit().
        register_shutdown_function([self::class, 'finish']);
    }
    public static function enabled(): bool
    {
        return self::$active;
    }
    public static function clock(): int
    {
        return self::$active ? hrtime(true) : 0;
    }
    /**
     * Named, non-overlapping top-level phases.
     * Unknown labels are intentionally ignored to keep logs schema-bound.
     */
    public static function phase(string $name)
    {
        if (!self::$active || !in_array($name, ['protection', 'app_init', 'handler'], true)) {
            return;
        }
        $now = hrtime(true);
        self::$phases[$name . '_ms'] = self::millis($now - self::$lastNs);
        self::$lastNs = $now;
    }
    /**
     * Add nested measurements such as SQL execution and cache operations.
     * These already contribute to top-level phases and must not be summed
     * together with them when reconstructing total request time.
     */
    public static function observe(string $name, int $startNs)
    {
        if (!self::$active || $startNs <= 0 || !in_array($name, ['db_connect', 'cache_read', 'search', 'sql_levels', 'sql_count', 'sql_index_probe', 'encode', 'cache_write'], true)) {
            return;
        }
        $key = $name . '_ms';
        self::$details[$key] = round((self::$details[$key] ?? 0.0) + (hrtime(true) - $startNs) / 1000000, 3);
    }
    public static function cacheHit(bool $value)
    {
        if (self::$active) {
            self::$cacheHit = $value;
        }
    }
    public static function response(Response $response)
    {
        if (!self::$active) {
            return;
        }
        self::phase('handler');
        self::$httpStatus = $response->status;
        self::$result = (function () use (&$response) {
            $__mcMatch1 = $response->body;
            if ($__mcMatch1 === '-1') {
                return 'rejected';
            }
            if ($__mcMatch1 === '-2') {
                return 'no_results';
            }
            if ($__mcMatch1 === '') {
                return 'empty';
            }
            return 'data';
        })();
    }
    public static function finish()
    {
        if (!self::$active || self::$finished) {
            return;
        }
        self::$finished = true;
        $entry = ['route' => 'getGJLevels21', 'total_ms' => self::millis(hrtime(true) - self::$startNs), 'result' => self::$result, 'status' => self::$httpStatus, 'cache_hit' => self::$cacheHit, 'peak_memory_kib' => (int) ceil(memory_get_peak_usage(true) / 1024)] + self::$phases + self::$details;
        // Logs go only to the PHP-FPM error stream, not to the GD response.
        $json = \mucho7_json_encode($entry, JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            error_log('[MuchoCore Perf] ' . $json);
        }
    }
    private static function millis(int $nanoseconds): float
    {
        return round(max(0, $nanoseconds) / 1000000, 3);
    }
}
