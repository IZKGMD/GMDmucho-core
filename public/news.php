<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MuchoCore\Database\Database;
use MuchoCore\News\NewsService;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$items = [];
$unavailable = false;
try {
    $items = (new NewsService((new Database())->connection()))->published(50);
} catch (Throwable $e) {
    error_log('[MuchoCore News] ' . $e::class . ': ' . $e->getMessage());
    $unavailable = true;
    http_response_code(503);
}
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=30');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
$escape = static fn(mixed $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>MuchoGDPS News</title>
<style>
:root{color-scheme:dark;font-family:Inter,system-ui,Segoe UI,sans-serif;background:#090b14;color:#edf2ff}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 50% -10%,#1d1944,transparent 60%),#090b14}
main{max-width:900px;margin:0 auto;padding:clamp(24px,5vw,64px) 20px 90px}
header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:38px}
.brand{letter-spacing:.12em;text-transform:uppercase;font-weight:900;color:#c1b7ff;font-size:13px}
header a{color:#aab8dd;text-decoration:none}header a:hover{color:white}
h1{font-size:clamp(36px,7vw,66px);line-height:1.1;letter-spacing:-.06em;margin:0 0 12px}
.subtitle{color:#9ba7c2;margin:0 0 36px;line-height:1.6}
article{background:linear-gradient(155deg,#181c31,#111525);border:1px solid #31365c;border-radius:19px;padding:clamp(20px,4vw,31px);margin:16px 0;box-shadow:0 16px 46px #05060b60}
article h2{font-size:clamp(20px,3vw,27px);letter-spacing:-.025em;margin:12px 0}
article p{white-space:pre-wrap;line-height:1.7;overflow-wrap:anywhere;color:#c6cde0;margin:0}
.meta{font-size:12px;color:#959fbd}.pin{color:#ffd783}
.article-link{display:inline-flex;margin-top:19px;color:#b7a8ff;font-weight:700;text-decoration:none}
.article-link:hover{text-decoration:underline}.empty{padding:32px;border:1px dashed #38405f;border-radius:16px;color:#aeb7d1}
</style>
</head>
<body><main>
<header><span class="brand">MuchoCore / MuchoGDPS</span><a href="/">Home ↗</a></header>
<h1>News &amp; updates</h1>
<p class="subtitle">Official server announcements from the MuchoGDPS owner.</p>
<?php if ($unavailable): ?>
<div class="empty">News are temporarily unavailable. Please try again later.</div>
<?php elseif (!$items): ?>
<div class="empty">Nothing published yet. Check back soon!</div>
<?php else: ?>
<?php foreach ($items as $item): ?>
<article>
<div class="meta"><?= $item['pinned'] ? '<span class="pin">📌 PINNED</span> · ' : '' ?><?= $escape($item['published_at']) ?></div>
<h2><?= $escape($item['title']) ?></h2>
<p><?= $escape($item['body']) ?></p>
<?php if ($item['url']): ?><a class="article-link" target="_blank" rel="noopener noreferrer" href="<?= $escape($item['url']) ?>">Read more ↗</a><?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>
</main></body></html>
