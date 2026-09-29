<?php

declare(strict_types=1);

use MuchoCore\Backup\DatabaseBackupService;
use MuchoCore\Database\Migrator;

$root = dirname(__DIR__);
$storage = $root . '/storage';
$lockPath = $storage . '/shared-install.lock';
$installedMarker = $storage . '/shared-install.installed';
$bootstrapPath = $storage . '/admin-bootstrap.php';
$envFile = $root . '/.env';

if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
    http_response_code(500);
    exit('Unable to create the MuchoCore storage directory.');
}

if (is_file($installedMarker)) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>MuchoCore Installer</title>
        <style>body{font-family:system-ui,sans-serif;background:#f4f6f8;margin:0;padding:40px;color:#222}.box{max-width:760px;margin:auto;background:white;padding:32px;border-radius:16px;box-shadow:0 8px 30px #0001}.ok{padding:14px;border-radius:10px;background:#e8f7ee;color:#145c2f}</style>
    </head>
    <body><div class="box">
        <h1>MuchoCore is already installed</h1>
        <div class="ok">The shared-hosting installer has been completed and locked.</div>
        <p>For security, delete <code>public/shared-install.php</code> from your hosting account.</p>
        <p>Then open <code>/health</code> and <code>/admin/</code> to verify the installation.</p>
    </div></body>
    </html>
    <?php
    exit;
}

$lockHandle = fopen($lockPath, 'c');
if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    http_response_code(409);
    exit('Another MuchoCore shared-hosting installation is already running. Refresh after it finishes.');
}

register_shutdown_function(static function () use ($lockHandle): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

$isHttps = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    http_response_code(500);
    exit('Unable to start the installer session.');
}

if (empty($_SESSION['mucho_install_csrf'])) {
    $_SESSION['mucho_install_csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pass(string $message): array {
    return ['ok' => true, 'message' => $message];
}

function fail(string $message): array {
    return ['ok' => false, 'message' => $message];
}

function atomicWrite(string $path, string $content, int $mode = 0600): void {
    $tmp = tempnam(dirname($path), '.muchocore-install-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create a temporary configuration file.');
    }
    try {
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the temporary configuration file.');
        }
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Unable to publish the configuration file.');
        }
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
}

function parseEnvValue(string $file, string $key): string {
    if (!is_file($file)) { return ''; }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) { continue; }
        if (str_starts_with($line, 'export ')) { $line = substr($line, 7); }
        $prefix = $key . '=';
        if (!str_starts_with($line, $prefix)) { continue; }
        $value = substr($line, strlen($prefix));
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }
    return '';
}

function databaseTableCount(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"')->fetchColumn();
}

function databasePreflight(PDO $pdo): string {
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $lower = strtolower($version);
    if (str_contains($lower, 'mariadb')) {
        $normalized = preg_replace('/[^0-9.].*$/', '', $version) ?: '';
        if ($normalized !== '' && version_compare($normalized, '10.4.0', '<')) {
            throw new RuntimeException('MariaDB 10.4 or newer is required. Your server reports ' . $version . '.');
        }
    } elseif (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $match)) {
        if (version_compare($match[1], '8.0.29', '<')) {
            throw new RuntimeException('MySQL 8.0.29 or newer is required. Your server reports ' . $version . '.');
        }
    } else {
        throw new RuntimeException('Unsupported MySQL/MariaDB server version: ' . $version . '.');
    }

    $probe = 'muchocore_install_probe_' . bin2hex(random_bytes(8));
    $quote = chr(96);
    $quotedProbe = $quote . str_replace($quote, $quote . $quote, $probe) . $quote;
    try {
        $pdo->exec('CREATE TABLE ' . $quotedProbe . ' (id INT NOT NULL PRIMARY KEY, value VARCHAR(64) NULL) ENGINE=InnoDB');
        $pdo->exec('ALTER TABLE ' . $quotedProbe . ' ADD COLUMN extra INT NULL');
        $pdo->exec('CREATE INDEX probe_value_idx ON ' . $quotedProbe . ' (value)');
        $stmt = $pdo->prepare('INSERT INTO ' . $quotedProbe . ' (id, value) VALUES (1, :value)');
        $stmt->execute(['value' => 'ok']);
        $pdo->prepare('UPDATE ' . $quotedProbe . ' SET extra = 1 WHERE id = 1')->execute();
        $pdo->exec('DELETE FROM ' . $quotedProbe . ' WHERE id = 1');
    } catch (Throwable $e) {
        throw new RuntimeException('The database user does not have all permissions required by MuchoCore migrations: ' . $e->getMessage(), 0, $e);
    } finally {
        try { $pdo->exec('DROP TABLE IF EXISTS ' . $quotedProbe); } catch (Throwable) {}
    }
    return $version;
}

function checkRequirements(string $root, string $storage): array {
    $checks = [];
    $checks[] = version_compare(PHP_VERSION, '8.3.0', '>=') ? pass('PHP ' . PHP_VERSION . ' is supported.') : fail('PHP 8.3 or newer is required.');
    foreach (['pdo','pdo_mysql','openssl','json','mbstring','session','zlib'] as $extension) {
        $checks[] = extension_loaded($extension) ? pass('PHP extension ' . $extension . ' is enabled.') : fail('PHP extension ' . $extension . ' is missing. Enable it in your hosting control panel.');
    }
    foreach ([$root . '/composer.json'=>'composer.json',$root . '/composer.lock'=>'composer.lock',$root . '/vendor/autoload.php'=>'Composer dependencies',$root . '/public/index.php'=>'public/index.php',$root . '/public/.htaccess'=>'public/.htaccess',$root . '/database/migrations'=>'database/migrations',$root . '/src'=>'src',$root . '/.htaccess'=>'root .htaccess'] as $path=>$label) {
        $checks[] = file_exists($path) ? pass($label . ' is present.') : fail($label . ' is missing. Upload the complete MuchoCore shared-hosting package.');
    }
    $checks[] = is_writable($root) ? pass('The MuchoCore project directory is writable.') : fail('The MuchoCore project directory is not writable by PHP.');
    $checks[] = is_writable($storage) ? pass('The storage directory is writable.') : fail('The storage directory is not writable by PHP.');
    $configDir = $root . '/config';
    if (!is_dir($configDir)) { @mkdir($configDir, 0750, true); }
    $checks[] = is_writable($configDir) ? pass('The config directory is writable.') : fail('The config directory is not writable.');
    return $checks;
}

$requirements = checkRequirements($root, $storage);
$canInstall = !in_array(false, array_column($requirements, 'ok'), true);
$defaultHost = parseEnvValue($envFile, 'DB_HOST') ?: 'localhost';
$defaultPort = parseEnvValue($envFile, 'DB_PORT') ?: '3306';
$defaultDb = parseEnvValue($envFile, 'DB_NAME') ?: 'muchocore';
$defaultUser = parseEnvValue($envFile, 'DB_USER');
$defaultUrl = parseEnvValue($envFile, 'MUCHO_ACCOUNT_URL');
$errors = [];
$success = false;
$backupInfo = null;
$envBackup = null;
$bootstrapBackup = null;
$cloudsaveCreated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['mucho_install_csrf']) || !hash_equals((string)$_SESSION['mucho_install_csrf'], $postedCsrf)) {
        $errors[] = 'This installation form expired. Refresh the page and try again.';
    }
    if (!$canInstall) { $errors[] = 'Fix every red check above before installing.'; }
    $dbHost = trim((string)($_POST['db_host'] ?? $defaultHost));
    $dbPort = trim((string)($_POST['db_port'] ?? $defaultPort));
    $dbName = trim((string)($_POST['db_name'] ?? $defaultDb));
    $dbUser = trim((string)($_POST['db_user'] ?? $defaultUser));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $accountUrl = rtrim(trim((string)($_POST['account_url'] ?? $defaultUrl)), '/');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $dbHost)) { $errors[] = 'Database host contains unsupported characters.'; }
    if (!preg_match('/^\d{1,5}$/', $dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) { $errors[] = 'Database port must be a number such as 3306.'; }
    if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $dbName) || strlen($dbName) > 128) { $errors[] = 'Database name contains unsupported characters.'; }
    if ($dbUser === '' || strlen($dbUser) > 128) { $errors[] = 'Database username is invalid.'; }
    $parts = parse_url($accountUrl);
    $validAccountUrl = is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']) && empty($parts['path']) && empty($parts['query']) && empty($parts['fragment']) && (filter_var($parts['host'], FILTER_VALIDATE_IP) !== false || filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
    if (!$validAccountUrl) { $errors[] = 'Server URL must be a full URL such as https://gdps.example.com with no /database path.'; }
    if (!$isHttps) { $errors[] = 'Open the installer over HTTPS before entering database and administrator passwords.'; }
    if (strlen($adminPass) < 12) { $errors[] = 'Admin password must contain at least 12 characters.'; }
    if ($adminPass !== $adminPass2) { $errors[] = 'The two admin passwords do not match.'; }

    if (!$errors) {
        $createdCloudsavePath = $root . '/config/cloudsave.key';
        $hadExistingEnv = is_file($envFile);
        $hadExistingBootstrap = is_file($bootstrapPath);
        $hadExistingCloudsave = is_file($createdCloudsavePath);
        try {
            $pdo = new PDO('mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $version = databasePreflight($pdo);
            $tableCount = databaseTableCount($pdo);
            if (!$hadExistingEnv && $tableCount > 0) { throw new RuntimeException('The selected database is not empty (' . $tableCount . ' tables). Use a new empty database for a first installation.'); }
            $controlDir = $storage . '/control';
            $backupDir = $storage . '/backups/database';
            foreach ([$controlDir,$backupDir] as $directory) {
                if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) { throw new RuntimeException('Unable to create required directory: ' . $directory); }
                if (!is_writable($directory)) { throw new RuntimeException('Required directory is not writable: ' . $directory); }
            }
            if ($hadExistingEnv) {
                $envBackup = $envFile . '.before-shared-install-' . gmdate('Ymd-His');
                if (!copy($envFile, $envBackup)) { throw new RuntimeException('Could not back up the existing .env file.'); }
                chmod($envBackup, 0600);
            }
            $env = implode(PHP_EOL, ['DB_HOST=' . $dbHost,'DB_PORT=' . $dbPort,'DB_NAME=' . $dbName,'DB_USER=' . $dbUser,'DB_PASS=' . $dbPass,'MUCHO_ACCOUNT_URL=' . $accountUrl,'MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net','MUCHO_ADMIN_BOOTSTRAP=' . str_replace('\\','/',$bootstrapPath),'MUCHO_CONTROL_DIR=' . str_replace('\\','/',$controlDir),'MUCHO_BACKUP_DIR=' . str_replace('\\','/',$backupDir),'MUCHO_SHARED_HOSTING=1','MUCHO_GD_VERSIONS=all','MUCHO_PROTECT_STORAGE=file','MUCHO_TRUSTED_PROXY_CIDRS=','MUCHO_CACHE_DRIVER=database','MUCHO_AUTO_UPDATE=0','TZ=UTC','']);
            atomicWrite($envFile, $env, 0600);
            $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
            if (!is_string($adminHash) || $adminHash === '') { throw new RuntimeException('Unable to hash the administrator password.'); }
            $bootstrap = "<?php\nreturn [\n    'username' => 'admin',\n    'password_hash' => " . var_export($adminHash,true) . ",\n];\n";
            if ($hadExistingBootstrap) {
                $bootstrapBackup = $bootstrapPath . '.before-shared-install-' . gmdate('Ymd-His');
                if (!copy($bootstrapPath, $bootstrapBackup)) { throw new RuntimeException('Could not back up the existing admin bootstrap file.'); }
                chmod($bootstrapBackup, 0600);
            }
            atomicWrite($bootstrapPath, $bootstrap, 0600);
            if (!$hadExistingCloudsave) { atomicWrite($createdCloudsavePath, base64_encode(random_bytes(32)) . PHP_EOL, 0600); $cloudsaveCreated = true; }
            require_once $root . '/vendor/autoload.php';
            Dotenv\Dotenv::createMutable($root)->safeLoad();
            $backupInfo = (new DatabaseBackupService($pdo,$backupDir))->create($dbName);
            (new Migrator($pdo,$root . '/database/migrations'))->migrate();
            $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL DEFAULT \'admin\', totp_secret VARCHAR(64) NULL, access_key_hash VARCHAR(255) NULL, access_key_created_at TIMESTAMP NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $adminStmt = $pdo->prepare('INSERT INTO admin_users (username,password_hash,role,is_active) VALUES (:username,:password_hash,"owner",1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role="owner", is_active=1');
            $adminStmt->execute(['username'=>'admin','password_hash'=>$adminHash]);
            atomicWrite($installedMarker, 'MuchoCore shared-hosting installation completed: ' . gmdate('c') . PHP_EOL . 'Database server: ' . $version . PHP_EOL . 'Target database: ' . $dbName . PHP_EOL, 0600);
            $_SESSION['mucho_install_csrf'] = bin2hex(random_bytes(32));
            $success = true;
            @unlink(__FILE__);
        } catch (Throwable $e) {
            $message = 'Installation failed: ' . $e->getMessage();
            if ($backupInfo !== null) { $message .= ' A target database backup was created before migrations: ' . $backupInfo['file']; }
            if ($envBackup !== null && is_file($envBackup)) { @copy($envBackup,$envFile); } elseif (!$hadExistingEnv && is_file($envFile)) { @unlink($envFile); }
            if ($bootstrapBackup !== null && is_file($bootstrapBackup)) { @copy($bootstrapBackup,$bootstrapPath); } elseif (!$hadExistingBootstrap && is_file($bootstrapPath)) { @unlink($bootstrapPath); }
            if ($cloudsaveCreated && is_file($createdCloudsavePath)) { @unlink($createdCloudsavePath); }
            $errors[] = $message;
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
        <p style="margin-top:24px;padding-top:14px;border-top:1px solid #d9dee5;text-align:center;color:#7a838f;font-size:12px">
            Powered by MuchoCore 🛡️ · <a href="https://github.com/IZKGMD/GMDmucho-core" target="_blank" rel="noopener noreferrer">GitHub</a>
        </p>
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
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f3f5f7;margin:0;color:#20242a;min-height:100vh;display:flex;flex-direction:column}
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
                    <input id="admin_pass" type="password" name="admin_pass" minlength="8" required>
                    <small>At least 8 characters.</small>
                </div>

                <div>
                    <label for="admin_pass2">Repeat admin password</label>
                    <input id="admin_pass2" type="password" name="admin_pass2" minlength="8" required>
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
<div style="max-width:920px;width:calc(100% - 32px);margin:auto auto 0;padding:16px 0 12px;border-top:1px solid #d9dee5;text-align:center;color:#7a838f;font-size:11px;line-height:1.7">
    Powered by MuchoCore · Copyright © <?= date('Y') ?> IZK ·
    <a href="https://github.com/IZKGMD" target="_blank" rel="noopener noreferrer" style="color:#687587;text-decoration:none">GitHub</a>
</div>
</body>
</html>
