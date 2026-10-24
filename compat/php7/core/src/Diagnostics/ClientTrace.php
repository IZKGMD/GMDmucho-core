<?php

declare (strict_types=1);
namespace MuchoCore\Diagnostics;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
final class ClientTrace
{
    private static $request = null;
    private static $startedAt = 0.0;
    public static function captureRequest(Request $request)
    {
        if (!self::enabled()) {
            return;
        }
        self::$request = $request;
        self::$startedAt = microtime(true);
    }
    public static function captureResponse(Response $response)
    {
        if (!self::enabled() || self::$request === null) {
            return;
        }
        $version = self::$request->clientVersion();
        $entry = ['time' => gmdate('c'), 'request_id' => (string) ($_SERVER['MUCHO_REQUEST_ID'] ?? ''), 'method' => self::$request->method, 'path' => self::$request->path, 'status' => $response->status, 'content_type' => $response->contentType, 'response_length' => strlen($response->body), 'response_sha256' => hash('sha256', $response->body), 'result_kind' => self::resultKind($response), 'level_list_type' => self::levelListType(self::$request), 'moderation_queue_filters' => self::moderationQueueFilters(self::$request), 'client_family' => $version->family(), 'game_version' => $version->gameVersion, 'binary_version' => $version->binaryVersion, 'query_keys' => self::safeKeys(self::$request->query), 'post_keys' => self::safeKeys(self::$request->post), 'duration_ms' => self::$startedAt > 0 ? round((microtime(true) - self::$startedAt) * 1000, 3) : null];
        $path = getenv('MUCHO_CLIENT_TRACE_FILE') ?: dirname(\MUCHO7_PROJECT_ROOT . '/src/Diagnostics', 2) . '/storage/client-trace.ndjson';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $line = \mucho7_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
    private static function enabled(): bool
    {
        return in_array(strtolower((string) getenv('MUCHO_CLIENT_TRACE')), ['1', 'true', 'yes', 'on'], true);
    }
    /**
     * GD 2.209 moderator queue filter selectors only; no identifying,
     * credential or arbitrary string values leave this process.
     */
    private static function moderationQueueFilters(Request $request)
    {
        if (preg_match('#/(?:adminGetReportQueue)(?:\.php)?$#i', $request->path) !== 1) {
            return null;
        }
        $out = [];
        foreach (['mode', 'flags', 'sort', 'page'] as $field) {
            if (!array_key_exists($field, $request->post)) {
                continue;
            }
            $raw = $request->post[$field];
            if (is_int($raw) || is_string($raw) && preg_match('/^-?[0-9]{1,6}$/D', $raw) === 1) {
                $out[$field] = (int) $raw;
            }
        }
        return $out;
    }
    /** A public search selector only; never log accounts or credentials. */
    private static function levelListType(Request $request)
    {
        if (preg_match('#/(?:getGJLevels(?:21|22)?)(?:\.php)?$#i', $request->path) !== 1) {
            return null;
        }
        $type = $request->postInt('type', 0);
        return $type >= 0 && $type <= 100 ? $type : null;
    }
    /** Classify the GD wire marker, never record response bodies. */
    private static function resultKind(Response $response): string
    {
        if ($response->status >= 400) {
            return 'http_error';
        }
        return (function () use (&$response) {
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
    /** Only conventional field names; do not allow values disguised as keys. */
    private static function safeKeys(array $values): array
    {
        $keys = [];
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $key) !== 1) {
                continue;
            }
            $keys[] = $key;
            if (count($keys) >= 64) {
                break;
            }
        }
        return $keys;
    }
}
