<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use MuchoCore\News\NewsService;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('News board contract: ' . $message);
    }
};

$fields = NewsService::validate("  Hello\nworld ", " Line 1\nLine 2 ", "https://muchogdps.space/updates");
$check($fields['title'] === 'Hello world', 'headline must be normalized');
$check($fields['body'] === "Line 1\nLine 2", 'news body preserved');
$check($fields['external_url'] === 'https://muchogdps.space/updates', 'HTTPS link accepted');
$check(NewsService::isSafeLink('https://example.com/x'), 'ordinary HTTPS link');
foreach (['javascript:alert(1)','http://example.com','https://user:pass@example.com','https://example.com/'.chr(10)] as $url) {
    $check(!NewsService::isSafeLink($url), 'unsafe news link rejected');
}
foreach ([
    ['', 'Text', ''],
    [str_repeat('x', 141), 'Text', ''],
    ['Title', '', ''],
    ['Title', str_repeat('x', 5001), ''],
    ['Title', 'Text', 'http://not-secure.example'],
] as [$title, $body, $url]) {
    try {
        NewsService::validate($title, $body, $url);
    } catch (RuntimeException) {
        continue;
    }
    throw new RuntimeException('Invalid news input was accepted.');
}

$index = file_get_contents($root . '/public/admin/index.php');
$pages = file_get_contents($root . '/public/admin/config/pages.php');
$router = file_get_contents($root . '/public/admin/core/AdminRouter.php');
$adminPage = file_get_contents($root . '/public/admin/pages/news.php');
$action = file_get_contents($root . '/public/admin/actions/news.php');
$application = file_get_contents($root . '/src/Core/Application.php');
$migration = file_get_contents($root . '/database/migrations/20261016_001_news_board.php');

$check(str_contains($pages, "'news'=>'News Board'"), 'admin news page registered');
$check(str_contains($router, "'news'") && str_contains($router, '/pages/news.php'), 'news page routed');
$check(str_contains($index, "if (\$pageKey === 'news')") && str_contains($index, "=== 'owner'"), 'navigation owner-only');
$check(str_contains($index, "'news-save','news-toggle','news-delete'"), 'news actions routed');
$check(str_contains($action, "!== 'owner'") && str_contains($action, 'requireRank(40)'), 'news actions protected by owner role');
$check(str_contains($adminPage, "!== 'owner'"), 'page requires owner role');
$check(str_contains($adminPage, 'csrf()'), 'all news forms include CSRF');
$check(str_contains($adminPage, 'h($post['), 'posted text HTML-escaped');
$check(str_contains($application, "'/muchocore/news'"), 'public API route registered');
$check(str_contains($application, "'GET'"), 'public API read-only');
$check(str_contains($application, '$news->published()'), 'public API only uses published records');
$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS mucho_news_posts'), 'persistent news migration');
$check(is_file($root . '/public/news.php'), 'public HTML news board exists');
echo "MUCHOCORE_NEWS_BOARD_OK\n";
