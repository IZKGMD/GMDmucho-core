<?php

declare(strict_types=1);

namespace MuchoCore\Diagnostics;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final class ClientTrace
{
    private static ?Request $request = null;
    private static float $startedAt = 0.0;

    public static function captureRequest(Request $request): void
    {
        if (!self::enabled()) {
            return;
        }

        self::$request = $request;
        self::$startedAt = microtime(true);
    }

    public static function captureResponse(Response $response): void
    {
        if (!self::enabled() || self::$request === null) {
            return;
        }

        $version = self::$request->clientVersion();

        $entry = [
            'time' => gmdate('c'),
            'request_id' => (string)($_SERVER['MUCHO_REQUEST_ID'] ?? ''),
            'method' => self::$request->method,
            'path' => self::$request->path,
            'status' => $response->status,
            'content_type' => $response->contentType,
            'response_length' => strlen($response->body),
            'response_sha256' => hash('sha256', $response->body),
            'client_family' => $version->family(),
            'game_version' => $version->gameVersion,
            'binary_version' => $version->binaryVersion,
            'query_keys' => array_values(array_map('strval', array_keys(self::$request->query))),
            'post_keys' => self::safeKeys(self::$request->post),
            'duration_ms' => self::$startedAt > 0
                ? round((microtime(true) - self::$startedAt) * 1000, 3)
                : null,
        ];

        $path = getenv('MUCHO_CLIENT_TRACE_FILE')
            ?: dirname(__DIR__, 2) . '/storage/client-trace.ndjson';

        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $line = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private static function enabled(): bool
    {
        return in_array(
            strtolower((string)getenv('MUCHO_CLIENT_TRACE')),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private static function safeKeys(array $values): array
    {
        return array_values(array_map('strval', array_keys($values)));
    }
}
