<?php
declare(strict_types=1);

use MuchoCore\Admin\FirstRunSetup;
use MuchoCore\Core\Environment;
use MuchoCore\Http\ClientIp;

/**
 * First-run administrator onboarding, reached only when admin_users is empty.
 * Requiring the installer-provisioned secret prevents first-visitor takeover.
 */
function muchoAdminFirstRun(PDO $db, string $rootDir, bool $https, bool $alreadyCompleted): never
{
    if (adminIsPlain()) {
        adminPlainStartOutput();
    }

    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/html; charset=utf-8');

    $escape = static fn(string $value): string => htmlspecialchars(
        $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
    );

    $path = (string)(Environment::get(
        'MUCHO_ADMIN_BOOTSTRAP',
        $rootDir . '/storage/admin-bootstrap.php'
    ) ?? $rootDir . '/storage/admin-bootstrap.php');

    // The secret is never rendered in the browser, sent in a URL or logged.
    $config = is_readable($path) ? require $path : null;
    $hash = is_array($config) && is_string($config['password_hash'] ?? null)
        ? $config['password_hash'] : '';
    $validBootstrap = $hash !== '' && (password_get_info($hash)['algo'] ?? null) !== null;
    $hostname = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $local = in_array($hostname, ['localhost', '127.0.0.1', 'localhost:8080', 'localhost:8081'], true);
    $allowed = !$alreadyCompleted && $validBootstrap && ($https || $local);
    $notice = '';

    if ($alreadyCompleted) {
        http_response_code(423);
        $notice = 'Первоначальная настройка уже была завершена. Для восстановления доступа используйте серверную консоль.';
    } elseif (!$validBootstrap) {
        http_response_code(503);
        $notice = 'Установка сервера ещё не завершена. Сначала запустите установщик MuchoCore.';
    } elseif (!$https && !$local) {
        http_response_code(403);
        $notice = 'Создание администратора доступно только по HTTPS.';
    }

    $csrf = (string)($_SESSION['mucho_first_owner_csrf'] ?? '');
    if ($csrf === '') {
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['mucho_first_owner_csrf'] = $csrf;
    }

    $error = '';
    $username = trim((string)($config['username'] ?? 'admin'));

    if ($allowed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $providedCsrf = (string)($_POST['csrf'] ?? '');
        if (!hash_equals($csrf, $providedCsrf)) {
            http_response_code(403);
            $error = 'Страница устарела. Обновите её и попробуйте снова.';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['password_confirm'] ?? '');
            $setupKey = (string)($_POST['setup_key'] ?? '');

            $ratePath = sys_get_temp_dir() . '/muchocore-first-owner-' .
                hash('sha256', ClientIp::resolve($_SERVER));
            $state = ['start' => time(), 'tries' => 0];
            if (is_file($ratePath)) {
                $stored = json_decode((string)@file_get_contents($ratePath), true);
                if (is_array($stored)) {
                    $state = $stored;
                }
            }
            if (time() - (int)($state['start'] ?? 0) > 900) {
                $state = ['start' => time(), 'tries' => 0];
            }

            if ((int)$state['tries'] >= 8) {
                http_response_code(429);
                $error = 'Слишком много попыток. Повторите позже.';
            } elseif (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/D', $username)) {
                $error = 'Логин: 1–32 символа, латинские буквы, цифры, _ или -.';
            } elseif (strlen($password) < 12 || strlen($password) > 256 ||
                      preg_match('/[\x00-\x1F\x7F]/', $password)) {
                $error = 'Пароль должен содержать 12–256 символов без управляющих знаков.';
            } elseif ($password !== $confirm) {
                $error = 'Пароли не совпадают.';
            } elseif ($setupKey === '' || !password_verify($setupKey, $hash)) {
                $state['tries'] = (int)$state['tries'] + 1;
                @file_put_contents($ratePath, json_encode($state), LOCK_EX);
                $error = 'Неверный установочный секрет.';
            } else {
                $created = FirstRunSetup::createOwner($db, $username, $password);
                if ($created !== null) {
                    @unlink($ratePath);
                    unset($_SESSION['mucho_first_owner_csrf']);
                    establishAdminSession($created);
                    header('Location: /admin/', true, 303);
                    exit;
                }

                // A parallel request finished setup before this request.
                header('Location: /admin/', true, 303);
                exit;
            }
        }
    }

    $heading = $allowed ? 'Создать первого администратора' : 'Настройка недоступна';
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>MuchoCore — первая настройка</title>
<style>
:root{color-scheme:dark;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
*{box-sizing:border-box}
body{min-height:100vh;margin:0;padding:24px;display:grid;place-items:center;background:radial-gradient(ellipse at 18% 5%,#24305b 0,transparent 42%),#090c14;color:#edf2ff}
main{width:min(480px,100%);padding:30px;border:1px solid #2c3752;border-radius:22px;background:#101624;box-shadow:0 24px 65px #0005}
mark{display:inline-block;padding:7px 11px;border-radius:9px;background:#252d52;color:#cbd7ff;font-size:11px;font-weight:850;letter-spacing:.11em}
h1{margin:16px 0 9px;font-size:26px;line-height:1.2}
p{color:#a4b0c5;line-height:1.55;font-size:14px}
label{display:block;margin-top:17px;margin-bottom:6px;color:#c4d1e4;font-size:13px;font-weight:650}
input{width:100%;padding:12px 13px;border:1px solid #35415b;border-radius:11px;background:#080d17;color:#fff;font-size:15px}
input:focus{outline:2px solid #8b8bff;outline-offset:1px}
button{margin-top:23px;width:100%;padding:13px;border:0;border-radius:12px;background:#8578ff;color:white;font-size:15px;font-weight:750;cursor:pointer}
.alert{padding:12px 14px;border-radius:10px;background:#321b25;border:1px solid #704257;color:#ffd1da}
small{color:#7e8da6;font-size:12px;line-height:1.5}
footer{margin-top:23px;padding-top:16px;border-top:1px solid #293348;color:#77849b;font-size:12px}
a{color:#b1baff}
</style>
</head>
<body>
<main>
<mark>MUCHOCORE / FIRST RUN</mark>
<h1><?= $escape($heading) ?></h1>
<?php if ($notice !== ''): ?>
<p class="alert" role="alert"><?= $escape($notice) ?></p>
<?php else: ?>
<p>Первый запуск панели управления. Выберите логин и пароль владельца GDPS. Позже эта страница регистрации станет недоступна.</p>
<?php if ($error !== ''): ?><p class="alert" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<form method="post" action="/admin/" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
<label for="username">Логин администратора</label>
<input id="username" name="username" maxlength="32" pattern="[A-Za-z_][A-Za-z0-9_-]{0,31}" value="<?= $escape($username) ?>" autocomplete="username" required>
<label for="password">Новый пароль</label>
<input id="password" name="password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required>
<label for="password_confirm">Повторите пароль</label>
<input id="password_confirm" name="password_confirm" type="password" minlength="12" maxlength="256" autocomplete="new-password" required>
<label for="setup_key">Установочный секрет</label>
<input id="setup_key" name="setup_key" type="password" autocomplete="off" required>
<p><small>Введите секрет, который был задан во время установки VPS или shared-хостинга. Это защита от создания владельца посторонним посетителем.</small></p>
<button type="submit">Создать аккаунт владельца</button>
</form>
<?php endif; ?>
<footer>После настройки: <a href="/admin/">вход в админку</a> · MuchoCore</footer>
</main>
</body>
</html>
<?php
    exit;
}
