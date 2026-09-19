<?php

declare(strict_types=1);

namespace MuchoCore\Diagnostics;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final class ClientTrace
{
    private static ?Request $request = null;

    public static function captureRequest(Request $request): void
    {
        if (!self::enabled()) {
            return;
        }

        self::$request = $request;
    }

    public static function captureResponse(Response $response): void
    {
        if (!self::enabled() || self::$request === null) {
            return;
        }

        $entry = [
            'time' => gmdate('c'),
            'method' => self::$request->method,
            'path' => self::$request->path,
            'status' => $response->status,
            'content_type' => $response->contentType,
            'query_keys' => array_values(array_map('strval', array_keys(self::$request->query))),
            'post_keys' => self::safeKeys(self::$request->post),
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
