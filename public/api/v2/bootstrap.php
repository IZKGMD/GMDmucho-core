<?php
declare(strict_types=1);

/*
 * MuchoCore API v2
 * Copyright (C) 2026 IZK
 */

define('MUCHO_V2_ROOT', dirname(__DIR__, 3));
const MUCHO_V2_VERSION = '2.2';

function muchoV2Env(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $env = [];

    foreach ([
        MUCHO_V2_ROOT . '/.env',
        MUCHO_V2_ROOT . '/.env.local',
        MUCHO_V2_ROOT . '/config/.env'
    ] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $lines = file(
            $file,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');

            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && str_ends_with($value, '"')) ||
                    ($value[0] === "'" && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            }

            $env[$key] = $value;
        }
    }

    $cache = $env;
    return $cache;
}

function muchoV2Db(): PDO
{
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $e = muchoV2Env();

    if (!empty($e['DATABASE_URL'])) {
        $url = parse_url($e['DATABASE_URL']);

        if (!$url) {
            throw new RuntimeException('Invalid DATABASE_URL');
        }

        $scheme = strtolower((string)($url['scheme'] ?? ''));

        $driver = str_starts_with($scheme, 'postgres')
            ? 'pgsql'
            : 'mysql';

        $host = $url['host'] ?? '127.0.0.1';
        $port = $url['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $name = ltrim($url['path'] ?? '', '/');

        $dsn = "$driver:host=$host;port=$port;dbname=$name";

        if ($driver === 'mysql') {
            $dsn .= ';charset=utf8mb4';
        }

        $db = new PDO(
            $dsn,
            urldecode($url['user'] ?? ''),
            urldecode($url['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        return $db;
    }

    $host = $e['DB_HOST'] ?? $e['MYSQL_HOST'] ?? '127.0.0.1';
    $port = $e['DB_PORT'] ?? $e['MYSQL_PORT'] ?? '3306';
    $name = $e['DB_DATABASE']
        ?? $e['DB_NAME']
        ?? $e['MYSQL_DATABASE']
        ?? '';

    $user = $e['DB_USERNAME']
        ?? $e['DB_USER']
        ?? $e['MYSQL_USER']
        ?? '';

    $pass = $e['DB_PASSWORD']
        ?? $e['DB_PASS']
        ?? $e['MYSQL_PASSWORD']
        ?? '';

    if ($name === '') {
        throw new RuntimeException('Database name is not configured');
    }

    $db = new PDO(
        "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    return $db;
}

function muchoV2Send(array $payload, int $status = 200): never
{

    if (
        function_exists('muchoV2RequestId') &&
        !array_key_exists('request_id',$payload)
    ) {
        $payload['request_id']=muchoV2RequestId();
    }

    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    echo PHP_EOL;
    exit;
}

function muchoV2Fail(
    string $error,
    int $status = 400
): never {
    muchoV2Send([
        'ok' => false,
        'api' => 'MuchoCore',
        'version' => MUCHO_V2_VERSION,
        'error' => $error
    ], $status);
}

function muchoV2RequireMethod(string $method): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($current !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        muchoV2Fail('method_not_allowed', 405);
    }
}

function muchoV2StringList(string $raw): array
{
    $raw = trim($raw);

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $items = $decoded;
    } else {
        $items = preg_split('/\s*,\s*/u', $raw) ?: [];
    }

    $result = [];

    foreach ($items as $item) {
        if (!is_scalar($item)) {
            continue;
        }

        $item = trim((string)$item);

        if ($item === '' || in_array($item, $result, true)) {
            continue;
        }

        $result[] = $item;
    }

    return $result;
}

function muchoV2PinnedLevels(string $raw): array
{
    $result = [];

    foreach (preg_split('/[,\s]+/', trim($raw)) ?: [] as $value) {
        if (
            $value !== '' &&
            ctype_digit($value) &&
            (int)$value > 0
        ) {
            $id = (int)$value;

            if (!in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        if (count($result) >= 3) {
            break;
        }
    }

    return $result;
}


require_once __DIR__ . '/security.php';
