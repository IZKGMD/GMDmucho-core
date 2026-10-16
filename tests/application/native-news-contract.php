<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use MuchoCore\News\NativeNewsFormat;

function checkNativeNews(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Native news contract failed: ' . $description);
    }
}

$post = [
    'id' => 7,
    'title' => 'Test news',
    'body' => 'Hello GDPS!',
    'url' => 'https://muchogdps.space/',
    'pinned' => true,
    'published_at' => '2026-09-13 12:00:00',
];

$expected = '4,1|1:7;2:U2VwdGVtYmVyIDEzLCAyMDI2;3:VGVzdCBuZXdz;4:0,SGVsbG8gR0RQUyE='
    . '~2,UmVhZCBtb3Jl,aHR0cHM6Ly9tdWNob2dkcHMuc3BhY2Uv';
$raw = NativeNewsFormat::textPayload([$post]);
checkNativeNews($raw === $expected, 'wire-format record field order, Base64URL and elements');
$encoded = NativeNewsFormat::encode([$post]);
checkNativeNews(preg_match('/^[A-Za-z0-9_-]+=*$/D', $encoded) === 1, 'outer base64url encoding');
$gzip = base64_decode(strtr($encoded, '-_', '+/'), true);
checkNativeNews(is_string($gzip), 'outer decode');
checkNativeNews(gzdecode($gzip) === $expected, 'gzip + base64url transport');
checkNativeNews(NativeNewsFormat::textPayload([]) === '4,0', 'empty board');
// A monotonic DB revision prevents updates/deletions from producing a lower
// version number than the one cached by an older native client.
$service = file_get_contents(__DIR__ . '/../../src/News/NewsService.php');
$stateMigration = file_get_contents(__DIR__ . '/../../database/migrations/20261016_002_native_news_state.php');
$nativeVersionEndpoint = file_get_contents(__DIR__ . '/../../public/news/version.php');
checkNativeNews(str_contains($stateMigration, 'mucho_news_state'), 'news version persisted');
checkNativeNews(str_contains($stateMigration, '1000000'), 'version starts above vanilla client revision');
checkNativeNews(str_contains($service, 'SET version=version+1'), 'each edit increments monotonic version');
checkNativeNews(str_contains($service, '$this->bumpNativeVersion()'), 'news mutations invalidate version');
checkNativeNews(str_contains($nativeVersionEndpoint, '$nativeNewsService->nativeVersion()'), 'version endpoint serves monotonic state');
$plain = $post;
$plain['url'] = null;
checkNativeNews(!str_contains(NativeNewsFormat::textPayload([$plain]), '~2,'), 'optional URL omitted');

foreach ([
    ['id' => 0, 'title' => 'Hi', 'body' => 'hello', 'published_at' => '2026-09-13'],
    ['id' => 1, 'title' => '', 'body' => 'hello', 'published_at' => '2026-09-13'],
    ['id' => 1, 'title' => 'Hi', 'body' => 'hello', 'published_at' => 'not a date'],
] as $bad) {
    try {
        NativeNewsFormat::textPayload([$bad]);
    } catch (RuntimeException) {
        continue;
    }
    throw new RuntimeException('Invalid news payload was accepted.');
}

$cfg = file_get_contents(__DIR__ . '/../../config/mucho_v71.php');
$caddy = file_get_contents(__DIR__ . '/../../docker/Caddyfile');
$docker = file_get_contents(__DIR__ . '/../../docker-compose.yml');
checkNativeNews(str_contains($cfg, 'MUCHO_NATIVE_NEWS'), 'opt-in news content URL');
checkNativeNews(str_contains($docker, 'MUCHO_NATIVE_NEWS'), 'news flag forwarded into app container');
checkNativeNews(str_contains($caddy, '/gd-content/news/news.dat'), 'native news route');
checkNativeNews(str_contains($caddy, '/gd-content/news/news_version.txt'), 'native version route');
checkNativeNews(str_contains($caddy, 'geometrydashfiles.b-cdn.net'), 'non-news CDN proxy configured');
// Regression for deployments where /news/*.dat falls back to the common
// public/index.php instead of reaching the native PHP file rewrite in Caddy.
$appSource = file_get_contents(__DIR__ . '/../../src/Core/Application.php');
foreach ([
    '/news/news.dat',
    '/news/news_version.txt',
    '/gd-content/news/news.dat',
    '/gd-content/news/news_version.txt',
] as $fallbackPath) {
    checkNativeNews(
        str_contains($appSource, "\$route('".$fallbackPath."'"),
        'news path handled by PHP core fallback: ' . $fallbackPath
    );
}
checkNativeNews(
    str_contains($appSource, 'NativeNewsFormat::encode($news->published(30))'),
    'Caddy and fallback publish the same native wire representation'
);
checkNativeNews(
    str_contains($appSource, '$news->nativeVersion()'),
    'Caddy and fallback publish the same monotonic revision'
);
checkNativeNews(
    str_contains($appSource, "Response::text('', 503)"),
    'news generator failures return an explicit error, not -1'
);

echo "MUCHOCORE_GD_2209_NATIVE_NEWS_OK\n";
