#!/usr/bin/env php
<?php

declare(strict_types=1);

// Read-only static inventory check: no database, server boot or credentials.
require_once dirname(__DIR__) . '/src/Routing/Router.php';

use MuchoCore\Routing\Router;

try {
    $file = $argv[1] ?? '';
    if ($file === '' || !is_file($file) || filesize($file) > 8 * 1024 * 1024) {
        throw new RuntimeException('Usage: php bin/mucho-client-audit.php CLIENT_REPORT.json');
    }
    $report = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    if (($report['schema_version'] ?? null) !== 1 ||
        !is_array($report['endpoints'] ?? null) ||
        !array_is_list($report['endpoints']) ||
        count($report['endpoints']) < 1 || count($report['endpoints']) > 4096) {
        throw new RuntimeException('Expected a nonempty client-probe.py report.');
    }
    $source = (string)file_get_contents(dirname(__DIR__) . '/src/Core/Application.php');
    preg_match_all('~\$route\(\s*[\x27\x22](/[^\x27\x22]+)[\x27\x22]~', $source, $matches);
    if (($matches[1] ?? []) === []) {
        throw new RuntimeException('Cannot read application route inventory.');
    }
    $router = new Router();
    $routes = array_fill_keys(array_map($router->normalizePath(...), $matches[1]), true);
    $routes['/checkifserveronline'] = true;
    $mapped = [];
    $unmapped = [];
    foreach ($report['endpoints'] as $endpoint) {
        $path = $endpoint['path'] ?? null;
        if (!is_string($path) || preg_match('~^/[A-Za-z][A-Za-z0-9_]{2,79}\.php$~D', $path) !== 1) {
            throw new RuntimeException('Invalid endpoint name in probe report.');
        }
        $canonical = $router->normalizePath($path);
        if (isset($routes[$canonical])) {
            $mapped[$path] = $canonical;
        } else {
            $unmapped[$path] = $canonical;
        }
    }
    ksort($mapped);
    ksort($unmapped);
    echo json_encode([
        'schema_version' => 1,
        'client_sha256' => $report['sha256'] ?? null,
        'claimed_version' => $report['claimed_version'] ?? null,
        'mapped_endpoints' => $mapped,
        'unmapped_endpoints' => $unmapped,
        'static_routes_mapped' => $unmapped === [],
        'runtime_verified' => false,
        'native_client_verified' => false,
        'note' => 'Route presence does not verify authentication, request/response formats or native ABI.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($unmapped === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(2);
}
