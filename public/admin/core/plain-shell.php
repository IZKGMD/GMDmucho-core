<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pages[$page])?> — <?=h($branding['server_name'])?> Plain Admin</title>
<script src="/admin/admin-simple.js?v=1" defer></script>
<?php if ($page==='admins'): ?><script src="/admin/assets/qrcode.min.js?v=20260925" id="muchoQrRenderer"></script><?php endif ?>
</head>
<body>
<header>
<p><strong><?=h($branding['server_name'])?> · Plain Admin</strong></p>
<p><?=h(admin()['username'])?> (<?=h(admin()['role'])?>) · <a href="/admin/?page=<?=h($page)?>" data-styled-admin>Styled admin</a> · <a href="/admin/?logout=1">Logout</a></p>
<a href="#main">Skip to content</a>
<nav aria-label="Main">
<ul>
<?php $primary=['dashboard','players','levels','rating','clans','songs','settings']; ?>
<?php foreach($primary as $key): if(!canAdminPage($key)) continue; ?>
<li><a href="/admin/?page=<?=h($key)?>" <?=$page===$key?'aria-current="page"':''?>><?=h($pages[$key])?></a></li>
<?php endforeach ?>
</ul>
<details <?=!in_array($page,$primary,true)?'open':''?>>
<summary>More tools</summary>
<ul><?php foreach(array_diff(array_keys($pages),$primary) as $key): if(!canAdminPage($key)) continue; ?>
<li><a href="/admin/?page=<?=h($key)?>" <?=$page===$key?'aria-current="page"':''?>><?=h($pages[$key])?></a></li>
<?php endforeach ?></ul>
</details>
</nav>
<hr>
</header>
<main id="main">
<h1><?=h($pages[$page])?></h1>
<?php if($flash): ?><p role="status"><?=h($flash['text'])?></p><?php endif ?>
