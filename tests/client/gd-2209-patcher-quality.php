<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/Gd2209Binary.php';

use MuchoCore\Client\Gd2209Binary;

function must(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

function rejected(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        must(str_contains($e->getMessage(), $contains), 'Unexpected refusal: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected patch refusal: ' . $contains);
}

// x64 PE fixture with a valid section table and immutable data beyond .rdata.
$pe = str_repeat("\0", 0x400);
$pe = substr_replace($pe, 'MZ', 0, 2);
$pe = substr_replace($pe, pack('V', 0x80), 0x3c, 4);
$pe = substr_replace($pe, "PE\0\0", 0x80, 4);
$pe = substr_replace($pe, pack('v', 0x8664), 0x84, 2);
$pe = substr_replace($pe, pack('v', 1), 0x86, 2);
$pe = substr_replace($pe, pack('v', 0xf0), 0x94, 2);
$pe = substr_replace($pe, ".rdata\0\0", 0x188, 8);
$pe = substr_replace($pe, pack('V', 0x200), 0x188 + 16, 4);
$pe = substr_replace($pe, pack('V', 0x400), 0x188 + 20, 4);
$pe .= "https://www.boomlings.com/database/getGJLevels21.php\0";
$pe .= "https://www.boomlings.com/databas/checkIfServerOnline.php\0";
$pe .= "https://www.boomlings.com/database/getGJNotifications.php\0";
$pe .= str_repeat("\0", 0x200 - (strlen($pe) - 0x400));
$pe .= "RESOURCE-DO-NOT-TOUCH";

[$patched, $count, $paths, $routes] =
    Gd2209Binary::patchBytes($pe, 'linasgdpsprivatese.5v.pl');
must($count === 3, 'Missing matched routes');
must(count($routes) === 3, 'Incomplete route manifest');
must(strlen($patched) === strlen($pe), 'Modified original EXE length');
must(substr($patched, 0, 0x400) === substr($pe, 0, 0x400), 'PE header changed');
must(substr($patched, 0x600) === substr($pe, 0x600), 'Unexpected non-.rdata change');
$seen = ['short_alias' => 0, 'root_alias' => 0];
foreach ($routes as $route) {
    must(isset($seen[$route['mode']]), 'Long shared domain unexpectedly used /database/');
    ++$seen[$route['mode']];
    $prefix = $route['mode'] === 'short_alias' ? '/a/' : '/';
    must(str_starts_with($route['target_path'], $prefix), 'Incorrect target route');
    must(str_starts_with($route['source_path'], '/database/'), 'Source canonicalization missing');
}
must($seen['short_alias'] >= 1, 'Missing long-host /a/ rewrite');
must($seen['root_alias'] >= 1, 'Malformed /databas/ URL must use a root alias');
$rules = Gd2209Binary::apacheRewriteSnippet([
    'server_url' => 'https://linasgdpsprivatese.5v.pl',
    'routes' => $routes,
]);
must(str_contains($rules, 'RewriteEngine On'), 'Missing rewrite directives');
must(str_contains($rules, 'mc/public/php7.php [QSA,L,NC]'),
    'Generated handler must point to the native PHP7 adapter');
must(str_contains($rules, 'a/getGJLevels21\\.php'),
    'Short alias omitted from Apache rules');
must(str_contains($rules, 'checkIfServerOnline\\.php'),
    'Root fallback omitted from Apache rules');
must(!str_contains($rules, 'RewriteRule ^(.*)'), 'Unsafe catch-all rewrite');
rejected(static fn () => Gd2209Binary::apacheRewriteSnippet([
    'routes' => [['target_path' => '/../../config/php7-local.php']],
]), 'Unexpected patched');
rejected(static fn () => Gd2209Binary::apacheRewriteSnippet([
    'routes' => $routes,
], '../config/secret.php'), 'Invalid PHP route handler');

must(str_contains($patched, 'https://linasgdpsprivatese.5v.pl/a/getGJLevels21.php'),
    'Long-host URL was not encoded');
must(!str_contains($patched, 'https://www.boomlings.com/database/getGJLevels21.php'),
    'Old server URL leaked');

[$normal, $normalCount, , $normalRoutes] =
    Gd2209Binary::patchBytes($pe, 'muchogdps.space');
must($normalCount === 3, 'Normal-domain route count changed');
foreach ($normalRoutes as $route) {
    must($route['mode'] === 'database', 'Short domain unexpectedly shortened API path');
    must(str_starts_with($route['target_path'], '/database/'),
        'Database route unexpectedly rewritten');
}
must(str_contains($normal, 'https://muchogdps.space/database/getGJLevels21.php'),
    'Direct database route missing');

rejected(static fn () => Gd2209Binary::patchBytes($pe, str_repeat('x', 47) . '.com'),
    'too long');
$badOffset = substr_replace($pe, pack('V', 0xFFFFFFF0), 0x3c, 4);
rejected(static fn () => Gd2209Binary::patchBytes($badOffset, 'muchogdps.space'),
    'PE header');
$badTable = substr_replace($pe, pack('v', 255), 0x86, 2);
rejected(static fn () => Gd2209Binary::patchBytes($badTable, 'muchogdps.space'),
    'section table');
$badRange = substr_replace($pe, pack('V', 0xFFFFFF00), 0x188 + 20, 4);
rejected(static fn () => Gd2209Binary::patchBytes($badRange, 'muchogdps.space'),
    'section');

echo "MUCHOCORE_GD2209_PATCH_QUALITY_OK\n";
