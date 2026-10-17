<?php

declare(strict_types=1);

/**
 * Static safety contract. It intentionally does not start containers,
 * change live DNS, or open ports in CI.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $file) use ($root): string {
    $data = file_get_contents($root . '/' . $file);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException('Missing staging asset: ' . $file);
    }
    return $data;
};
$compose = $read('docker-compose.gd2209-stage.yml');
$base = $read('docker-compose.test.yml');
$caddy = $read('docker/Caddyfile.gd2209-stage');
$runner = $read('bin/mucho-gd2209-staging.sh');
$assert = static function (bool $ok, string $detail): void {
    if (!$ok) {
        throw new RuntimeException('GD209 staging isolation: ' . $detail);
    }
};
$assert(str_contains($compose, '127.0.0.1:18080:80'),
    'web ingress must bind to localhost only');
$assert(!preg_match('/["\x27](?:80|443):(?:80|443)["\x27]/', $compose),
    'never claim production HTTP(S) ports');
$assert(str_contains($base, 'testgdps-db:') &&
    str_contains($base, 'testgdps-app:') &&
    !preg_match('/^\s+(?:db|app|worker|caddy):\s*$/m', $base),
    'base Compose file must have only separate tenant services');
$assert(str_contains($compose, 'testgdps-app:') &&
    str_contains($compose, 'gd2209-stage-web:') &&
    !preg_match('/^\s+(?:db|app|worker|caddy):\s*$/m', $compose),
    'overlay must not mutate any production service');
$assert(str_contains($caddy, 'php_fastcgi testgdps-app:9000') &&
    !str_contains($caddy, 'php_fastcgi app:9000'),
    'stage Caddy must route only to test FPM');
$assert(str_contains($runner, 'muchocore-gd2209-staging') &&
    str_contains($runner, '/opt/mucho-gd2209-staging') &&
    str_contains($runner, 'docker-compose.test.yml') &&
    str_contains($runner, 'docker-compose.gd2209-stage.yml'),
    'runner must use stable isolated project and test Compose files');
$assert(!str_contains($runner, 'down -v') ||
    str_contains($runner, 'Do not use'), 'no staging data deletion operation');
$assert(!str_contains($runner, 'reset --hard') &&
    !str_contains($runner, 'docker compose restart app'),
    'runner must not rewrite or restart production');
$assert(str_contains($runner, "MUCHO_CLIENT_TRACE=1") &&
    str_contains($runner, "MUCHO_AUTO_UPDATE=0"),
    'stage diagnostics must be available without auto-update');

echo "MUCHOCORE_GD_2209_STAGING_ISOLATION_OK\n";
