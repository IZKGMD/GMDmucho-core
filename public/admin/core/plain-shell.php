<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pages[$page])?> — <?=h($branding['server_name'])?> Plain Admin</title>
<script src="/admin/admin-simple.js?v=2" defer></script>
<?php if ($page==='admins'): ?><script src="/admin/assets/qrcode.min.js?v=20260925" id="muchoQrRenderer"></script><?php endif ?>
</head>
<body>
<header>
<?php adminBrand($branding['server_name']); ?>
<p><?=h(admin()['username'])?> (<?=h(admin()['role'])?>)</p>
<?php adminInterfaceSwitch($_GET); ?>
<p><a href="#main">Skip to content</a></p>
<details><summary>Menu · <?=h($pages[$page])?></summary>
<?php require __DIR__.'/navigation.php'; ?>
</details>
<hr>
</header>
<main id="main">
<h1><?=h($pages[$page])?></h1>
<?php if($flash): ?><p role="status"><?=h($flash['text'])?></p><?php endif ?>
