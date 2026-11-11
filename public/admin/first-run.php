<?php
declare(strict_types=1);

use MuchoCore\Admin\FirstRunSetup;
use MuchoCore\Core\Environment;
use MuchoCore\Http\ClientIp;


/** Keep first-run language consistent with the existing admin preference. */
function muchoAdminFirstRunLanguage(array $query, array $cookies): string
{
    $requested = $query['lang'] ?? null;
    if ($requested === 'en' || $requested === 'ru') {
        return $requested;
    }

    return ($cookies['mucho_admin_language'] ?? null) === 'ru' ? 'ru' : 'en';
}

/** Both languages are rendered on the server, including validation errors. */
function muchoAdminFirstRunCopy(string $language): array
{
    $english = [
        'completed' => 'Initial setup has already been completed. Use the server console to recover access.',
        'not_installed' => 'Server installation is not complete. Run the MuchoCore installer first.',
        'requires_https' => 'Administrator registration is available only over HTTPS.',
        'expired' => 'This page has expired. Refresh it and try again.',
        'rate_limited' => 'Too many attempts. Please try again later.',
        'username_invalid' => 'Username: 1–32 characters, using Latin letters, digits, _ or -.',
        'password_invalid' => 'Password must contain 12–256 characters without control characters.',
        'password_mismatch' => 'Passwords do not match.',
        'secret_invalid' => 'This activation link is invalid. Generate a new owner link on the server.',
        'activation_required' => 'The server is ready for its first administrator. Open the private setup link shown by the installer. If you lost it, run sudo mucho owner-link on the server.',
        'activate_wait' => 'Verifying your installation link…',
        'heading' => 'Create first administrator',
        'awaiting' => 'Finish administrator setup',
        'unavailable' => 'Setup unavailable',
        'title' => 'MuchoCore — First-time setup',
        'language' => 'Language',
        'introduction' => 'Welcome to the control panel. Choose the GDPS owner username and password. This registration page will be locked after setup.',
        'username' => 'Administrator username',
        'password' => 'New password',
        'confirm' => 'Confirm password',
        'submit' => 'Create owner account',
        'footer_prefix' => 'After setup:',
        'footer_link' => 'sign in to admin',
    ];

    if ($language !== 'ru') {
        return $english;
    }

    return [
        'awaiting' => 'Завершите настройку администратора',
        'completed' => 'Первоначальная настройка уже была завершена. Для восстановления доступа используйте серверную консоль.',
        'not_installed' => 'Установка сервера ещё не завершена. Сначала запустите установщик MuchoCore.',
        'requires_https' => 'Создание администратора доступно только по HTTPS.',
        'expired' => 'Страница устарела. Обновите её и попробуйте снова.',
        'rate_limited' => 'Слишком много попыток. Повторите позже.',
        'username_invalid' => 'Логин: 1–32 символа, латинские буквы, цифры, _ или -.',
        'password_invalid' => 'Пароль должен содержать 12–256 символов без управляющих знаков.',
        'password_mismatch' => 'Пароли не совпадают.',
        'secret_invalid' => 'Ссылка активации недействительна. Создайте новую ссылку владельца на сервере.',
        'activation_required' => 'Для создания первого администратора откройте приватную ссылку активации из установщика. Никому её не передавайте.',
        'activate_wait' => 'Проверяем ссылку установки…',
        'heading' => 'Создать первого администратора',
        'unavailable' => 'Настройка недоступна',
        'title' => 'MuchoCore — первая настройка',
        'language' => 'Язык',
        'introduction' => 'Первый запуск панели управления. Выберите логин и пароль владельца GDPS. Позже эта страница регистрации станет недоступна.',
        'username' => 'Логин администратора',
        'password' => 'Новый пароль',
        'confirm' => 'Повторите пароль',
        'submit' => 'Создать аккаунт владельца',
        'footer_prefix' => 'После настройки:',
        'footer_link' => 'вход в админку',
    ];
}

/**
 * First-run administrator onboarding, reached only when admin_users is empty.
 * A one-time browser activation link authorizes the session before owner creation.
 * An anonymous first visitor cannot register an owner.
 */
function muchoAdminFirstRun(PDO $db, string $rootDir, bool $https, bool $alreadyCompleted): never
{
    $language = muchoAdminFirstRunLanguage($_GET, $_COOKIE);
    $copy = muchoAdminFirstRunCopy($language);
    $requestedLanguage = $_GET['lang'] ?? null;
    if ($requestedLanguage === 'en' || $requestedLanguage === 'ru') {
        setcookie('mucho_admin_language', $language, [
            'expires' => time() + 31536000,
            'path' => '/admin',
            'secure' => $https,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

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
    $authorized = $allowed && (int)($_SESSION['mucho_first_owner_authorized_until'] ?? 0) >= time();
    $notice = '';

    if ($alreadyCompleted) {
        http_response_code(423);
        $notice = $copy['completed'];
    } elseif (!$validBootstrap) {
        http_response_code(503);
        $notice = $copy['not_installed'];
    } elseif (!$https && !$local) {
        http_response_code(403);
        $notice = $copy['requires_https'];
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
            $error = $copy['expired'];
        } else {
            $action = (string)($_POST['action'] ?? '');
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

            if ($action === 'activate') {
                $setupKey = (string)($_POST['setup_key'] ?? '');
                if ((int)$state['tries'] >= 8) {
                    http_response_code(429);
                    $error = $copy['rate_limited'];
                } elseif ($setupKey === '' || !password_verify($setupKey, $hash)) {
                    $state['tries'] = (int)$state['tries'] + 1;
                    @file_put_contents($ratePath, json_encode($state), LOCK_EX);
                    $error = $copy['secret_invalid'];
                } else {
                    @unlink($ratePath);
                    session_regenerate_id(true);
                    $_SESSION['mucho_first_owner_authorized_until'] = time() + 900;
                    $_SESSION['mucho_first_owner_csrf'] = bin2hex(random_bytes(32));
                    header('Location: /admin/?lang='.$language, true, 303);
                    exit;
                }
            } elseif (!$authorized) {
                http_response_code(403);
                $error = $copy['activation_required'];
            } else {
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $confirm = (string)($_POST['password_confirm'] ?? '');

                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/D', $username)) {
                    $error = $copy['username_invalid'];
                } elseif (strlen($password) < 12 || strlen($password) > 256 ||
                          preg_match('/[\x00-\x1F\x7F]/', $password)) {
                    $error = $copy['password_invalid'];
                } elseif ($password !== $confirm) {
                    $error = $copy['password_mismatch'];
                } else {
                    $created = FirstRunSetup::createOwner($db, $username, $password);
                    if ($created !== null) {
                        unset(
                            $_SESSION['mucho_first_owner_authorized_until'],
                            $_SESSION['mucho_first_owner_csrf']
                        );
                        establishAdminSession($created);
                        header('Location: /admin/', true, 303);
                        exit;
                    }

                    header('Location: /admin/', true, 303);
                    exit;
                }
            }
        }
    }

    if ($allowed && !$authorized) {
        $notice = $copy['activation_required'];
    }

    $heading = $allowed
        ? ($authorized ? $copy['heading'] : ($copy['awaiting'] ?? $copy['heading']))
        : $copy['unavailable'];
    ?>
<!doctype html>
<html lang="<?= $escape($language) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $escape($copy['title']) ?></title>
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
.language-switcher{display:flex;justify-content:flex-end;gap:6px;margin-bottom:17px}
.language-switcher a{display:inline-block;padding:6px 10px;border:1px solid #35415b;border-radius:8px;color:#aebbd3;text-decoration:none;font-size:12px;font-weight:700}
.language-switcher a[aria-current="page"]{border-color:#8578ff;background:#252d52;color:#fff}
.language-switcher a:focus-visible{outline:2px solid #b1baff;outline-offset:2px}
</style>
</head>
<body>
<main>
<nav class="language-switcher" aria-label="<?= $escape($copy['language']) ?>">
<a href="/admin/?lang=en" hreflang="en" lang="en" data-lang="en" <?= $language === 'en' ? 'aria-current="page"' : '' ?>>EN</a>
<a href="/admin/?lang=ru" hreflang="ru" lang="ru" data-lang="ru" <?= $language === 'ru' ? 'aria-current="page"' : '' ?>>RU</a>
</nav>
<mark>MUCHOCORE / FIRST RUN</mark>
<h1><?= $escape($heading) ?></h1>
<input type="hidden" id="mucho-first-run-csrf" name="csrf" value="<?= $escape($csrf) ?>">
<?php if ($notice !== ''): ?>
<p class="alert" role="alert"><?= $escape($notice) ?></p>
<?php if ($error !== ''): ?><p class="alert" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php else: ?>
<p><?= $escape($copy['introduction']) ?></p>
<?php if ($error !== ''): ?><p class="alert" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<form method="post" action="/admin/?lang=<?= $escape($language) ?>" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
<label for="username"><?= $escape($copy['username']) ?></label>
<input id="username" name="username" maxlength="32" pattern="[A-Za-z_][A-Za-z0-9_-]{0,31}" value="<?= $escape($username) ?>" autocomplete="username" required>
<label for="password"><?= $escape($copy['password']) ?></label>
<input id="password" name="password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required>
<label for="password_confirm"><?= $escape($copy['confirm']) ?></label>
<input id="password_confirm" name="password_confirm" type="password" minlength="12" maxlength="256" autocomplete="new-password" required>
<button type="submit"><?= $escape($copy['submit']) ?></button>
</form>
<?php endif; ?>
<footer><?= $escape($copy['footer_prefix']) ?> <a href="/admin/"><?= $escape($copy['footer_link']) ?></a> · MuchoCore</footer>
</main>
<script>
(function () {
    const params = new URLSearchParams(window.location.hash.slice(1));
    const secret = params.get('setup');
    if (secret === null) return;
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
    if (secret.length < 10 || secret.length > 512) return;

    const form = document.createElement('form');
    form.method = 'post';
    form.action = window.location.pathname + window.location.search;
    const values = {
        action: 'activate',
        csrf: document.getElementById('mucho-first-run-csrf').value,
        setup_key: secret
    };
    for (const [name, value] of Object.entries(values)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
})();
document.querySelectorAll('.language-switcher [data-lang]').forEach(function (link) {
    link.addEventListener('click', function () {
        try { localStorage.setItem('mucho_admin_language', link.dataset.lang); } catch (_) {}
    });
});
</script>
</body>
</html>
<?php
    exit;
}
