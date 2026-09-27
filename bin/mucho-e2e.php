#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$failures = 0;

$check = static function (string $name, callable $fn) use (&$failures): void {
    try {
        $ok = (bool)$fn();
    } catch (Throwable $e) {
        $ok = false;
        fwrite(STDERR, "ERROR {$name}: {$e->getMessage()}\n");
    }

    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;

    if (!$ok) {
        $failures++;
    }
};

$db = (new Database())->connection();

$check('database', static fn(): bool =>
    (int)$db->query('SELECT 1')->fetchColumn() === 1
);

$check('intelligence_schema', static function () use ($db): bool {
    foreach ([
        'mucho_level_search_index',
        'mucho_level_revisions',
        'mucho_jobs',
        'mucho_cache',
        'mucho_backup_verifications',
    ] as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name=?'
        );
        $stmt->execute([$table]);

        if ((int)$stmt->fetchColumn() !== 1) {
            return false;
        }
    }

    return true;
});

$baseUrl = rtrim(trim((string)(
    getenv('MUCHO_E2E_BASE_URL')
    ?: ($_ENV['MUCHO_E2E_BASE_URL'] ?? '')
)), '/');

if ($baseUrl === '') {
    $domain = trim((string)(
        getenv('DOMAIN')
        ?: ($_ENV['DOMAIN'] ?? '')
    ));

    if ($domain !== '') {
        $baseUrl = 'https://' . $domain;
    }
}

if ($baseUrl !== '') {
    $fetch = static function (string $url, string $body = ''): array {
        $context = stream_context_create([
            'http' => [
                'method' => $body === '' ? 'GET' : 'POST',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' =>
                    "User-Agent: MuchoCore-E2E/1.0\r\n" .
                    "Accept: text/plain\r\n" .
                    ($body === ''
                        ? ''
                        : "Content-Type: application/x-www-form-urlencoded\r\n"),
                'content' => $body,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        return [
            'body' => $result === false ? '' : (string)$result,
            'status' => (string)($http_response_header[0] ?? ''),
        ];
    };

    $check('http.health', static function () use ($fetch, $baseUrl): bool {
        $r = $fetch($baseUrl . '/health');
        return trim($r['body']) === '1' &&
            str_contains($r['status'], ' 200 ');
    });

    $check('http.server_online', static function () use ($fetch, $baseUrl): bool {
        $r = $fetch($baseUrl . '/checkIfServerOnline');
        return trim($r['body']) === '1' &&
            str_contains($r['status'], ' 200 ');
    });

    $check('protocol.readonly_level_list', static function () use ($fetch, $baseUrl): bool {
        $body = http_build_query([
            'type' => '0',
            'page' => '0',
            'gameVersion' => '22',
            'binaryVersion' => '31',
            'str' => '',
        ]);

        $r = $fetch($baseUrl . '/getGJLevels21', $body);
        return str_contains($r['status'], ' 200 ') &&
            (
                trim($r['body']) === '-2' ||
                str_contains($r['body'], '#')
            );
    });
} else {
    echo "SKIP http checks: set MUCHO_E2E_BASE_URL or DOMAIN\n";
}

echo $failures === 0
    ? "MUCHOCORE_E2E_OK\n"
    : "MUCHOCORE_E2E_FAILED={$failures}\n";

exit($failures === 0 ? 0 : 1);
