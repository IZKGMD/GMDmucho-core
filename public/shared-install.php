<?php

declare(strict_types=1);

use MuchoCore\Database\Migrator;

$isHttps = !empty($_SERVER['HTTPS'])
    && strtolower((string)$_SERVER['HTTPS']) !== 'off';

session_set_cookie_params([
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

if (empty($_SESSION['mucho_install_csrf'])) {
    $_SESSION['mucho_install_csrf'] = bin2hex(random_bytes(32));
}

$root = dirname(__DIR__);
$storage = $root . '/storage';
$lock = $storage . '/shared-install.lock';
$bootstrap = $storage . '/admin-bootstrap.php';
$envFile = $root . '/.env';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pass(string $message): array
{
    return ['ok' => true, 'message' => $message];
}

function fail(string $message): array
{
    return ['ok' => false, 'message' => $message];
}

function currentHost(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    return preg_match('/^[A-Za-z0-9.-]+(?::[0-9]+)?$/', $host) === 1
        ? $host
        : '';
}

function currentUrl(): string
{
    $https = !empty($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off';

    $scheme = $https ? 'https' : 'http';
    $host = currentHost();

    return $host !== '' ? $scheme . '://' . $host : '';
}

function checkRequirements(string $root, string $storage): array
{
    $checks = [];

    $checks[] = version_compare(PHP_VERSION, '8.3.0', '>=')
        ? pass('PHP ' . PHP_VERSION . ' is supported.')
        : fail('PHP 8.3 or newer is required. Ask your hosting provider to switch this website to PHP 8.3+.');

    foreach (['pdo', 'pdo_mysql', 'openssl', 'json', 'mbstring', 'session', 'fileinfo'] as $extension) {
        $checks[] = extension_loaded($extension)
            ? pass('PHP extension ' . $extension . ' is enabled.')
            : fail('PHP extension ' . $extension . ' is missing. Enable it in your hosting control panel.');
    }

    $checks[] = is_file($root . '/composer.json')
        ? pass('MuchoCore files were found.')
        : fail('composer.json is missing. Upload the complete MuchoCore repository.');

    $checks[] = is_file($root . '/vendor/autoload.php')
        ? pass('Composer dependencies are installed.')
        : fail(
            'Composer dependencies are missing. In the hosting terminal, run: ' .
            'composer install --no-dev --optimize-autoloader'
        );

    $checks[] = is_writable($root)
        ? pass('The MuchoCore directory is writable.')
        : fail('The MuchoCore directory is not writable. Give your hosting PHP user write access.');

    $sizeInBytes = static function (string $value): int {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        return match ($unit) {
            'g' => (int)($number * 1024 * 1024 * 1024),
            'm' => (int)($number * 1024 * 1024),
            'k' => (int)($number * 1024),
            default => (int)$number,
        };
    };

    $postMax = $sizeInBytes((string)ini_get('post_max_size'));
    $uploadMax = $sizeInBytes((string)ini_get('upload_max_filesize'));

    $checks[] = $postMax >= 32 * 1024 * 1024
        ? pass('PHP post_max_size allows 32 MB GD payloads.')
        : fail('PHP post_max_size must be at least 32 MB for level uploads and Cloud Save.');

    $checks[] = $uploadMax >= 32 * 1024 * 1024
        ? pass('PHP upload_max_filesize allows 32 MB uploads.')
        : fail('PHP upload_max_filesize must be at least 32 MB.');

    if (!is_dir($storage)) {
        @mkdir($storage, 0750, true);
    }

    $checks[] = is_writable($storage)
        ? pass('The storage directory is writable.')
        : fail('The storage directory is not writable. PHP must be able to create its configuration and backup folders.');

    return $checks;
}

if (is_file($lock)) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>MuchoCore Installer</title>
        <style>
            body{font-family:system-ui,sans-serif;background:#f4f6f8;margin:0;padding:40px;color:#222}
            .box{max-width:760px;margin:auto;background:white;padding:32px;border-radius:16px;box-shadow:0 8px 30px #0001}
            h1{margin-top:0}.ok{padding:14px;border-radius:10px;background:#e8f7ee;color:#145c2f}
        </style>
    </head>
    <body>
    <div class="box">
        <h1>MuchoCore is already installed</h1>
        <div class="ok">
            The shared-hosting installer is locked.
            For security, delete <code>public/shared-install.php</code> from your hosting account.
        </div>
        <p>Then open <code>/health</code> and <code>/admin/</code> to test the server.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$requirements = checkRequirements($root, $storage);
$canInstall = !in_array(false, array_column($requirements, 'ok'), true);

$defaultUrl = currentUrl();
$defaultHost = currentHost();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');

    if (
        !hash_equals(
            (string)$_SESSION['mucho_install_csrf'],
            $postedCsrf
        )
    ) {
        $errors[] = 'This installation form expired. Refresh the page and try again.';
    }

    if (!$canInstall) {
        $errors[] = 'Fix the red checks above before installing.';
    }

    $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbPort = trim((string)($_POST['db_port'] ?? '3306'));
    $dbName = trim((string)($_POST['db_name'] ?? 'muchocore'));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $accountUrl = rtrim(trim((string)($_POST['account_url'] ?? $defaultUrl)), '/');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $dbHost)) {
        $errors[] = 'Database host contains unsupported characters.';
    }

    if (!preg_match('/^\d{1,5}$/', $dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) {
        $errors[] = 'Database port must be a number such as 3306.';
    }

    if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $dbName)) {
        $errors[] = 'Database name contains unsupported characters.';
    }

    if ($dbUser === '') {
        $errors[] = 'Database username cannot be empty.';
    }

    if ($accountUrl === '' || !preg_match('#^https?://[^/\s]+$#i', $accountUrl)) {
        $errors[] = 'Server URL must look like https://gdps.example.com';
    }

    if (strlen($adminPass) < 12) {
        $errors[] = 'Admin password must contain at least 12 characters.';
    }

    if ($adminPass !== $adminPass2) {
        $errors[] = 'The two admin passwords do not match.';
    }

    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            $normalizedRoot = str_replace('\\', '/', $root);
            $controlDir = $normalizedRoot . '/storage/control';
            $backupDir = $normalizedRoot . '/storage/backups/admin-v2';

            @mkdir($controlDir, 0750, true);
            @mkdir($backupDir, 0750, true);

            $env = implode(PHP_EOL, [
                'DB_HOST=' . $dbHost,
                'DB_PORT=' . $dbPort,
                'DB_NAME=' . $dbName,
                'DB_USER=' . $dbUser,
                'DB_PASS=' . $dbPass,
                'MUCHO_ACCOUNT_URL=' . $accountUrl,
                'MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net',
                'MUCHO_SITE_NAME=Mucho GDPS',
                'MUCHO_SITE_TAGLINE=Powered by MuchoCore',
                'MUCHO_SITE_DESCRIPTION=Custom Geometry Dash private server powered by MuchoCore.',
                'MUCHO_SITE_LOGO=MuchoGDPS',
                'MUCHO_SITE_ACCENT=#7768ff',
                'MUCHO_SITE_ACCENT2=#43d7cf',
                'MUCHO_SITE_GITHUB_URL=',
                'MUCHO_SITE_DISCORD_URL=',
                'MUCHO_SITE_TELEGRAM_URL=',
                'MUCHO_SITE_CLIENT_URL=',
                'MUCHO_SITE_COPYRIGHT=Copyright © 2026 IZK',
                'MUCHO_ADMIN_BOOTSTRAP=' . $normalizedRoot . '/storage/admin-bootstrap.php',
                'MUCHO_CONTROL_DIR=' . $controlDir,
                'MUCHO_BACKUP_DIR=' . $backupDir,
                'MUCHO_SHARED_HOSTING=1',
                'TZ=UTC',
                '',
            ]);

            if (is_file($envFile)) {
                $backup = $envFile . '.before-shared-install-' . date('Ymd-His');
                if (!@copy($envFile, $backup)) {
                    throw new RuntimeException('Could not back up the existing .env file.');
                }
            }

            if (@file_put_contents($envFile, $env, LOCK_EX) === false) {
                throw new RuntimeException('Could not write .env. Check file permissions.');
            }

            @chmod($envFile, 0600);

            $bootstrap = <<<PHP
<?php
return [
    'username' => 'admin',
    'password_hash' => %s,
];
PHP;

            $bootstrap = sprintf(
                $bootstrap,
                var_export(password_hash($adminPass, PASSWORD_DEFAULT), true)
            );

            if (@file_put_contents($bootstrapPath, $bootstrap . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the admin bootstrap file.');
            }

            @chmod($bootstrapPath, 0600);

            if (!is_file($root . '/config/cloudsave.key')) {
                @mkdir($root . '/config', 0750, true);
                $key = base64_encode(random_bytes(32));
                @file_put_contents($root . '/config/cloudsave.key', $key . PHP_EOL, LOCK_EX);
                @chmod($root . '/config/cloudsave.key', 0600);
            }

            require_once $root . '/vendor/autoload.php';

            Dotenv\Dotenv::createImmutable($root)->safeLoad();

            $migrator = new Migrator(
                $pdo,
                $root . '/database/migrations'
            );
            $migrator->migrate();

            if (@file_put_contents(
                $lock,
                "Installed: " . gmdate('c') . PHP_EOL,
                LOCK_EX
            ) === false) {
                throw new RuntimeException('Installation finished, but the security lock could not be created.');
            }

            @chmod($lock, 0600);
            $success = true;

            @unlink(__FILE__);
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

if ($success) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>MuchoCore Installed</title>
        <style>
            body{font-family:system-ui,sans-serif;background:#f4f6f8;margin:0;padding:40px;color:#222}
            .box{max-width:760px;margin:auto;background:white;padding:32px;border-radius:16px;box-shadow:0 8px 30px #0001}
            .ok{padding:16px;border-radius:10px;background:#e8f7ee;color:#145c2f}
            code{background:#eef1f4;padding:2px 5px;border-radius:5px}
        </style>
    </head>
    <body>
    <div class="box">
        <h1>MuchoCore is installed 🎉</h1>
        <div class="ok">
            The database is connected, migrations finished, and the admin account was created.
        </div>

        <h2>Next</h2>
        <p><a href="/">Open the GDPS</a></p>
        <p><a href="/admin/">Open the admin panel</a></p>
        <p><a href="/health">Open the health check</a></p>

        <h2>Important</h2>
        <p>Delete <code>public/shared-install.php</code> from your hosting account if the file still exists.</p>
        <p>Do not delete <code>.env</code>, <code>storage/admin-bootstrap.php</code> or <code>config/cloudsave.key</code>.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$checksHtml = '';
foreach ($requirements as $check) {
    $class = $check['ok'] ? 'check ok' : 'check bad';
    $icon = $check['ok'] ? '✓' : '!';
    $checksHtml .= '<div class="' . $class . '"><strong>' . $icon . '</strong> ' . e($check['message']) . '</div>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MuchoCore Shared Hosting Installer</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f3f5f7;margin:0;color:#20242a}
.wrap{max-width:900px;margin:0 auto;padding:28px 16px 60px}
.card{background:#fff;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 6px 24px rgba(0,0,0,.07)}
h1{margin:0 0 8px;font-size:32px}h2{margin-top:0}p{line-height:1.55}
.check{padding:12px 14px;border-radius:10px;margin:8px 0}.ok{background:#e9f8ef;color:#145c2f}.bad{background:#fff0f0;color:#8f1d1d}
form{display:grid;gap:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:700px){.grid{grid-template-columns:1fr}}
label{font-weight:700;display:block;margin-bottom:6px}input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd2d8;border-radius:9px;font-size:16px}
small{color:#68717a}.button{background:#1565c0;color:#fff;border:0;border-radius:10px;padding:13px 18px;font-size:16px;font-weight:700;cursor:pointer}
.button:disabled{background:#aeb6bf;cursor:not-allowed}
.err{background:#fff0f0;color:#8f1d1d;padding:14px;border-radius:10px}.tip{background:#eef6ff;padding:14px;border-radius:10px}
code{background:#eef1f4;padding:2px 5px;border-radius:5px}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>MuchoCore Shared Hosting Installer</h1>
        <p>Welcome! This page is for normal PHP hosting. You do not need Docker.</p>
        <div class="tip">
            <strong>How this works:</strong>
            first fix every red check, then enter your database details and create your admin password.
        </div>
    </div>

    <div class="card">
        <h2>Step 1 — Check your hosting</h2>
        <?= $checksHtml ?>
    </div>

    <?php if ($errors): ?>
        <div class="card">
            <h2>There is something to fix</h2>
            <div class="err">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Step 2 — Database</h2>
        <p>Use the database information from your hosting control panel.</p>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= e((string)$_SESSION['mucho_install_csrf']) ?>">
            <div class="grid">
                <div>
                    <label for="db_host">Database host</label>
                    <input id="db_host" name="db_host" value="<?= e((string)($_POST['db_host'] ?? 'localhost')) ?>" required>
                    <small>Often <code>localhost</code>, but use the value your host gives you.</small>
                </div>

                <div>
                    <label for="db_port">Database port</label>
                    <input id="db_port" name="db_port" value="<?= e((string)($_POST['db_port'] ?? '3306')) ?>" required>
                </div>

                <div>
                    <label for="db_name">Database name</label>
                    <input id="db_name" name="db_name" value="<?= e((string)($_POST['db_name'] ?? 'muchocore')) ?>" required>
                </div>

                <div>
                    <label for="db_user">Database username</label>
                    <input id="db_user" name="db_user" value="<?= e((string)($_POST['db_user'] ?? '')) ?>" required>
                </div>
            </div>

            <div>
                <label for="db_pass">Database password</label>
                <input id="db_pass" type="password" name="db_pass" required>
            </div>

            <h2>Step 3 — Server and admin</h2>

            <div>
                <label for="account_url">Your GDPS address</label>
                <input id="account_url" name="account_url" value="<?= e((string)($_POST['account_url'] ?? $defaultUrl)) ?>" required>
                <small>Example: <code>https://gdps.example.com</code>. Do not add <code>/database</code>.</small>
            </div>

            <div class="grid">
                <div>
                    <label for="admin_pass">Admin password</label>
                    <input id="admin_pass" type="password" name="admin_pass" minlength="12" required>
                    <small>At least 12 characters.</small>
                </div>

                <div>
                    <label for="admin_pass2">Repeat admin password</label>
                    <input id="admin_pass2" type="password" name="admin_pass2" minlength="12" required>
                </div>
            </div>

            <div class="tip">
                <strong>Admin username:</strong> <code>admin</code>
            </div>

            <button class="button" type="submit" <?= $canInstall ? '' : 'disabled' ?>>
                Install MuchoCore
            </button>
        </form>
    </div>

    <div class="card">
        <h2>If you do not know your database values</h2>
        <p>Open your hosting control panel and look for:</p>
        <p><strong>MySQL / MariaDB / Databases</strong></p>
        <p>You normally need four things: host, database name, username and password. The installer does not create the database for you because every hosting provider handles database creation differently.</p>
    </div>
</div>
</body>
</html>
