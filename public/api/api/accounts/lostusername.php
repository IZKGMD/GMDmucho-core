<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

try {
    (new \MuchoCore\Account\RecoveryController())->handle();
} catch (Throwable $e) {
    error_log(
        '[MuchoCore Recovery] '
        . $e::class
        . ': '
        . $e->getMessage()
    );

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MuchoCore — ошибка</title>
<link rel="stylesheet" href="/recovery/recovery.css">
</head>
<body>
<main class="shell">
<section class="card">
<div class="brand">Mucho<span>Core</span></div>
<div class="eyebrow">MUCHOCORE</div>
<h1>Временная ошибка</h1>
<p class="lead">Сервис восстановления сейчас недоступен. Попробуйте немного позже.</p>
<a class="button secondary" href="/api/api/accounts/lostusername.php">Повторить</a>
</section>
</main>
</body>
</html>';
}
