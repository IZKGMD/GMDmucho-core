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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
    http_response_code(500);
    exit('Unable to create the MuchoCore storage directory.');
}

$storageHtaccess = $storage . '/.htaccess';
if (!is_file($storageHtaccess)) {
    @file_put_contents(
        $storageHtaccess,
        "Options -Indexes\nRequire all denied\nDeny from all\n",
        LOCK_EX
    );
    @chmod($storageHtaccess, 0600);
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

$isHttps = (string)($_SERVER['HTTPS'] ?? '') === 'on'
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    http_response_code(500);
    exit('Unable to start the installer session.');
}

$sessionSavePath = session_save_path();
if ($sessionSavePath !== '' && !is_dir($sessionSavePath)) {
    $sessionSavePath = dirname($sessionSavePath);
}
if ($sessionSavePath !== '' && !is_writable($sessionSavePath)) {
    http_response_code(500);
    exit('The hosting account cannot write installer sessions. Enable PHP sessions in the hosting control panel.');
}

if (empty($_SESSION['mucho_install_csrf'])) {
    $_SESSION['mucho_install_csrf'] = bin2hex(random_bytes(32));
}

$autoConfig = null;
$autoConfigPath = null;
$autoToken = trim((string)($_GET['mucho_auto'] ?? ''));
if ($autoToken !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $autoToken)) {
        http_response_code(400);
        exit('Invalid MuchoCore browser finalization token.');
    }

    $candidate = $storage . '/.mucho-auto-' . $autoToken . '.json';
    if (!is_file($candidate)) {
        http_response_code(410);
        exit('This MuchoCore browser finalization link has expired or was already used.');
    }

    $decoded = json_decode((string)@file_get_contents($candidate), true);
    if (!is_array($decoded)
        || (string)($decoded['token'] ?? '') !== $autoToken
        || (int)($decoded['expires_at'] ?? 0) < time()
    ) {
        @unlink($candidate);
        http_response_code(410);
        exit('This MuchoCore browser finalization link has expired or is invalid.');
    }

    $configuredUrl = rtrim((string)($decoded['account_url'] ?? ''), '/');
    $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $configuredHost = strtolower((string)(parse_url($configuredUrl)['host'] ?? ''));
    if ($configuredHost === '' || $currentHost === '' || $configuredHost !== preg_replace('/:\\d+$/', '', $currentHost)) {
        http_response_code(400);
        exit('This browser finalization token belongs to a different GDPS address.');
    }

    $autoConfig = $decoded;
    $autoConfigPath = $candidate;
    $_POST = [
        'csrf' => (string)$_SESSION['mucho_install_csrf'],
        'db_host' => (string)($autoConfig['db_host'] ?? ''),
        'db_port' => (string)($autoConfig['db_port'] ?? '3306'),
        'db_name' => (string)($autoConfig['db_name'] ?? ''),
        'db_user' => (string)($autoConfig['db_user'] ?? ''),
        'db_pass' => (string)($autoConfig['db_pass'] ?? ''),
        'account_url' => $configuredUrl,
        'gdps_name' => (string)($autoConfig['gdps_name'] ?? ''),
        'admin_pass' => (string)($autoConfig['admin_pass'] ?? ''),
        'admin_pass2' => (string)($autoConfig['admin_pass'] ?? ''),
    ];
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

function dotenvLine(string $key, string $value): string {
    $escaped = str_replace(
        ['\\', '"', '$'],
        ['\\\\', '\\"', '\\$'],
        $value
    );

    return $key . '="' . $escaped . '"';
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


function browser_finalization_return_url(array $autoConfig, bool $ok): ?string {
    $controlUrl = rtrim((string)($autoConfig['control_url'] ?? ''), '/');
    $jobId = (string)($autoConfig['job_id'] ?? '');
    $token = (string)($autoConfig['token'] ?? '');

    if ($controlUrl === ''
        || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)
        || !preg_match('/^[a-f0-9]{64}$/', $token)
    ) {
        return null;
    }

    return $controlUrl
        . '/install/shared/go/?job=' . rawurlencode($jobId)
        . '&browser_finish=1'
        . '&token=' . rawurlencode($token)
        . '&ok=' . ($ok ? '1' : '0');
}

function notify_browser_finalization(array $autoConfig, bool $ok): void {
    $controlUrl = rtrim((string)($autoConfig['control_url'] ?? ''), '/');
    $jobId = (string)($autoConfig['job_id'] ?? '');
    $token = (string)($autoConfig['token'] ?? '');
    if ($controlUrl === '' || $jobId === '' || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return;
    }

    $url = $controlUrl . '/api/deploy/browser-finish';
    $payload = json_encode([
        'job_id' => $jobId,
        'token' => $token,
        'ok' => $ok,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }

    $ch = @curl_init($url);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'MuchoCore-Shared-Browser-Finalizer/1.0',
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

function parseEnvValue(string $file, string $key): string {
    if (!is_file($file)) {
        return '';
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }

        $prefix = $key . '=';

        if (!str_starts_with($line, $prefix)) {
            continue;
        }

        $value = substr($line, strlen($prefix));
        $doubleQuoted = strlen($value) >= 2
            && $value[0] === '"'
            && $value[-1] === '"';
        $singleQuoted = strlen($value) >= 2
            && $value[0] === "'"
            && $value[-1] === "'";

        if ($doubleQuoted || $singleQuoted) {
            $value = substr($value, 1, -1);
        }

        if ($doubleQuoted) {
            $value = str_replace(
                ['\\$', '\\"', '\\\\'],
                ['$', '"', '\\'],
                $value
            );
        }

        return $value;
    }

    return '';
}
function databaseTableCount(PDO $pdo): int {
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_type = "BASE TABLE"'
    )->fetchColumn();
}

function databaseSizeBytes(PDO $pdo): int {
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(data_length + index_length), 0)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()'
    );
    $statement->execute();

    return max(0, (int)$statement->fetchColumn());
}

function isMuchoCoreDatabase(PDO $pdo): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN (
               "schema_migrations",
               "accounts",
               "profiles",
               "levels"
           )'
    );
    $statement->execute();

    return (int)$statement->fetchColumn() >= 3;
}

function verifyInstalledSchema(PDO $pdo): void {
    foreach ([
        'schema_migrations',
        'accounts',
        'profiles',
        'levels',
        'admin_users',
    ] as $table) {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table
             LIMIT 1'
        );
        $statement->execute(['table' => $table]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Installation verification failed: required table "' .
                $table . '" is missing.'
            );
        }
    }

    $pdo->query('SELECT 1')->fetchColumn();
}

function databasePreflight(PDO $pdo): string {
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $lower = strtolower($version);
    if (str_contains($lower, 'mariadb')) {
        $candidates = [];

        if (preg_match_all('/\d+\.\d+(?:\.\d+)?/', $version, $matches)) {
            $candidates = $matches[0];
        }

        $normalized = '';
        foreach ($candidates as $candidate) {
            if ($candidate === '5.5.5' && count($candidates) > 1) {
                continue;
            }
            $normalized = $candidate;
            break;
        }

        if ($normalized === '' || version_compare($normalized, '10.4.0', '<')) {
            throw new RuntimeException(
                'MariaDB 10.4 or newer is required. Your server reports ' . $version . '.'
            );
        }
    } elseif (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $match)) {
        if (version_compare($match[1], '8.0.29', '<')) {
            throw new RuntimeException(
                'MySQL 8.0.29 or newer is required. Your server reports ' . $version . '.'
            );
        }
    } else {
        throw new RuntimeException(
            'Unsupported MySQL/MariaDB server version: ' . $version . '.'
        );
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

    $checks[] = version_compare(PHP_VERSION, '8.3.0', '>=')
        ? pass('PHP ' . PHP_VERSION . ' is supported.')
        : fail('PHP 8.3 or newer is required.');

    foreach ([
        'pdo',
        'pdo_mysql',
        'openssl',
        'json',
        'mbstring',
        'session',
        'zlib',
    ] as $extension) {
        $checks[] = extension_loaded($extension)
            ? pass('PHP extension ' . $extension . ' is enabled.')
            : fail(
                'PHP extension ' . $extension .
                ' is missing. Enable it in your hosting control panel.'
            );
    }

    foreach ([
        'flock',
        'random_bytes',
        'password_hash',
        'gzopen',
        'gzwrite',
        'rename',
    ] as $function) {
        $checks[] = function_exists($function)
            ? pass('PHP function ' . $function . ' is available.')
            : fail(
                'Required PHP function ' . $function .
                ' is disabled by this hosting provider.'
            );
    }

    foreach ([
        $root . '/composer.json' => 'composer.json',
        $root . '/composer.lock' => 'composer.lock',
        $root . '/vendor/autoload.php' => 'Composer dependencies',
        $root . '/public/index.php' => 'public/index.php',
        $root . '/public/.htaccess' => 'public/.htaccess',
        $root . '/public/database' => 'public/database',
        $root . '/public/admin' => 'public/admin',
        $root . '/database/migrations' => 'database/migrations',
        $root . '/src' => 'src',
        $root . '/.htaccess' => 'root .htaccess',
    ] as $path => $label) {
        $checks[] = is_readable($path)
            ? pass($label . ' is present and readable.')
            : fail(
                $label .
                ' is missing or unreadable. Upload the complete MuchoCore shared-hosting package.'
            );
    }

    $memoryLimit = trim((string)ini_get('memory_limit'));
    $memoryBytes = -1;
    if ($memoryLimit !== '' && $memoryLimit !== '-1') {
        $unit = strtolower(substr($memoryLimit, -1));
        $number = (float)$memoryLimit;
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        $memoryBytes = (int)($number * $multiplier);
    }

    if ($memoryBytes > 0 && $memoryBytes < 128 * 1024 * 1024) {
        $checks[] = pass(
            'PHP memory_limit is ' . $memoryLimit .
            '; large database migrations may depend on the hosting provider limits.'
        );
    }

    $checks[] = is_writable($root)
        ? pass('The MuchoCore project directory is writable.')
        : fail('The MuchoCore project directory is not writable by PHP.');

    $checks[] = is_writable($storage)
        ? pass('The storage directory is writable.')
        : fail('The storage directory is not writable by PHP.');

    $configDir = $root . '/config';
    if (!is_dir($configDir)) {
        @mkdir($configDir, 0750, true);
    }

    $checks[] = is_writable($configDir)
        ? pass('The config directory is writable.')
        : fail('The config directory is not writable.');

    $backupDir = $storage . '/backups/database';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0750, true);
    }

    $checks[] = is_writable($backupDir)
        ? pass('The database backup directory is writable.')
        : fail('The database backup directory is not writable.');

    return $checks;
}

$requirements = checkRequirements($root, $storage);
$canInstall = !in_array(false, array_column($requirements, 'ok'), true);
$defaultHost = parseEnvValue($envFile, 'DB_HOST') ?: 'localhost';
$defaultPort = parseEnvValue($envFile, 'DB_PORT') ?: '3306';
$defaultDb = parseEnvValue($envFile, 'DB_NAME') ?: 'muchocore';
$defaultUser = parseEnvValue($envFile, 'DB_USER');
$defaultUrl = parseEnvValue($envFile, 'MUCHO_ACCOUNT_URL');
$existingMuchoInstall = parseEnvValue($envFile, 'MUCHO_SHARED_HOSTING') === '1';
$errors = [];
$success = false;
$backupInfo = null;
$envBackup = null;
$bootstrapBackup = null;
$cloudsaveCreated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $autoConfig !== null) {
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
    $gdpsName = trim((string)($_POST['gdps_name'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $dbHost)) { $errors[] = 'Database host contains unsupported characters.'; }
    if (!preg_match('/^\d{1,5}$/', $dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) { $errors[] = 'Database port must be a number such as 3306.'; }
    if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $dbName) || strlen($dbName) > 128) { $errors[] = 'Database name contains unsupported characters.'; }
    if ($dbUser === '' || strlen($dbUser) > 128) { $errors[] = 'Database username is invalid.'; }
    $parts = parse_url($accountUrl);
    $validAccountUrl = is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']) && empty($parts['path']) && empty($parts['query']) && empty($parts['fragment']) && (filter_var($parts['host'], FILTER_VALIDATE_IP) !== false || filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
    if (!$validAccountUrl) { $errors[] = 'Server URL must be a full URL such as https://gdps.example.com with no /database path.'; }
    if ($gdpsName === '' || mb_strlen($gdpsName, 'UTF-8') > 64 || preg_match('/[\x00-\x1F\x7F]/u', $gdpsName) === 1) { $errors[] = 'GDPS name must contain 1–64 characters and no control characters.'; }
    if (!$isHttps) { $errors[] = 'Open the installer over HTTPS before entering database and administrator passwords.'; }
    if (strlen($adminPass) < 12) { $errors[] = 'Admin password must contain at least 12 characters.'; }
    if ($adminPass !== $adminPass2) { $errors[] = 'The two admin passwords do not match.'; }
    if (preg_match('/[\x00-\x1F\x7F]/', $dbHost . $dbName . $dbUser . $accountUrl) === 1) {
        $errors[] = 'Database and server fields cannot contain control characters.';
    }

    if (!$errors) {
        $createdCloudsavePath = $root . '/config/cloudsave.key';
        $hadExistingEnv = is_file($envFile);
        $hadExistingBootstrap = is_file($bootstrapPath);
        $hadExistingCloudsave = is_file($createdCloudsavePath);
        try {
            $pdo = new PDO('mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $version = databasePreflight($pdo);
            $tableCount = databaseTableCount($pdo);
            $databaseSize = databaseSizeBytes($pdo);

            if ($tableCount > 0 && $existingMuchoInstall && !isMuchoCoreDatabase($pdo)) {
                throw new RuntimeException(
                    'The configured shared-hosting database does not look like a MuchoCore installation. ' .
                    'The installer will not risk changing this database.'
                );
            }

            if ($tableCount > 0 && !$existingMuchoInstall) {
                throw new RuntimeException(
                    'The selected database is not empty (' . $tableCount . ' tables). ' .
                    'Use a new empty database for a first installation. ' .
                    'The installer will not risk overwriting another application.'
                );
            }

            $freeBytes = @disk_free_space($storage);
            if (is_float($freeBytes) || is_int($freeBytes)) {
                $requiredBytes = max(16 * 1024 * 1024, (int)ceil($databaseSize * 1.50) + 8 * 1024 * 1024);
                if ((float)$freeBytes < $requiredBytes) {
                    throw new RuntimeException(
                        'Not enough free storage for a safe database backup. Required approximately ' .
                        number_format($requiredBytes / 1024 / 1024, 1) .
                        ' MB, available ' .
                        number_format((float)$freeBytes / 1024 / 1024, 1) . ' MB.'
                    );
                }
            }
            $controlDir = $storage . '/control';
            $backupDir = $storage . '/backups/database';
            $adminBackupDir = $storage . '/backups/admin-v2';
            foreach ([$controlDir,$backupDir,$adminBackupDir] as $directory) {
                if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) { throw new RuntimeException('Unable to create required directory: ' . $directory); }
                if (!is_writable($directory)) { throw new RuntimeException('Required directory is not writable: ' . $directory); }
            }
            if ($hadExistingEnv) {
                $envBackup = $envFile . '.before-shared-install-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
                if (!copy($envFile, $envBackup)) { throw new RuntimeException('Could not back up the existing .env file.'); }
                chmod($envBackup, 0600);
            }
            $env = implode(PHP_EOL, [
                dotenvLine('DB_HOST', $dbHost),
                dotenvLine('DB_PORT', $dbPort),
                dotenvLine('DB_NAME', $dbName),
                dotenvLine('DB_USER', $dbUser),
                dotenvLine('DB_PASS', $dbPass),
                dotenvLine('MUCHO_ACCOUNT_URL', $accountUrl),
                dotenvLine('MUCHO_SERVER_NAME', $gdpsName),
                dotenvLine('MUCHO_CUSTOM_CONTENT_URL', 'https://geometrydashfiles.b-cdn.net'),
                dotenvLine('MUCHO_ADMIN_BOOTSTRAP', str_replace('\\', '/', $bootstrapPath)),
                dotenvLine('MUCHO_CONTROL_DIR', str_replace('\\', '/', $controlDir)),
                dotenvLine('MUCHO_BACKUP_DIR', str_replace('\\', '/', $storage . '/backups/admin-v2')),
                dotenvLine('MUCHO_DB_BACKUP_DIR', str_replace('\\', '/', $backupDir)),
                dotenvLine('MUCHO_SHARED_HOSTING', '1'),
                dotenvLine('MUCHO_GD_VERSIONS', 'all'),
                dotenvLine('MUCHO_PROTECT_STORAGE', 'file'),
                dotenvLine('MUCHO_TRUSTED_PROXY_CIDRS', ''),
                dotenvLine('MUCHO_CACHE_DRIVER', 'database'),
                dotenvLine('MUCHO_AUTO_UPDATE', '0'),
                dotenvLine('TZ', 'UTC'),
                '',
            ]);
            atomicWrite($envFile, $env, 0600);
            $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
            if (!is_string($adminHash) || $adminHash === '') { throw new RuntimeException('Unable to hash the administrator password.'); }
            $bootstrap = "<?php\nreturn [\n    'username' => 'admin',\n    'password_hash' => " . var_export($adminHash,true) . ",\n];\n";
            if ($hadExistingBootstrap) {
                $bootstrapBackup = $bootstrapPath . '.before-shared-install-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
                if (!copy($bootstrapPath, $bootstrapBackup)) { throw new RuntimeException('Could not back up the existing admin bootstrap file.'); }
                chmod($bootstrapBackup, 0600);
            }
            atomicWrite($bootstrapPath, $bootstrap, 0600);
            if (!$hadExistingCloudsave) { atomicWrite($createdCloudsavePath, base64_encode(random_bytes(32)) . PHP_EOL, 0600); $cloudsaveCreated = true; }
            require_once $root . '/vendor/autoload.php';
            Dotenv\Dotenv::createMutable($root)->safeLoad();
            $backupInfo = (new DatabaseBackupService($pdo,$backupDir))->create($dbName);
            (new Migrator($pdo,$root . '/database/migrations'))->migrate();
            (new MuchoCoreBrandingBrandingService($pdo))->saveServerName($gdpsName);
            $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL DEFAULT \'admin\', totp_secret VARCHAR(64) NULL, access_key_hash VARCHAR(255) NULL, access_key_created_at TIMESTAMP NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $adminStmt = $pdo->prepare('INSERT INTO admin_users (username,password_hash,role,is_active) VALUES (:username,:password_hash,"owner",1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role="owner", is_active=1');
            $adminStmt->execute(['username'=>'admin','password_hash'=>$adminHash]);
            verifyInstalledSchema($pdo);
            atomicWrite($installedMarker, 'MuchoCore shared-hosting installation completed: ' . gmdate('c') . PHP_EOL . 'Database server: ' . $version . PHP_EOL . 'Target database: ' . $dbName . PHP_EOL, 0600);
            $_SESSION['mucho_install_csrf'] = bin2hex(random_bytes(32));
            $success = true;
            if ($autoConfig !== null) {
                notify_browser_finalization($autoConfig, true);
                if ($autoConfigPath !== null) {
                    @unlink($autoConfigPath);
                }
            }
            @unlink(__FILE__);
        } catch (Throwable $e) {
            if ($autoConfig !== null) {
                notify_browser_finalization($autoConfig, false);
            }
            $requestId = bin2hex(random_bytes(8));
            error_log(sprintf(
                '[MuchoCore Shared Installer] request=%s %s: %s | %s:%d',
                $requestId,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $message = 'Installation could not be completed. Request ID: ' . $requestId . '.';
            if ($backupInfo !== null && is_file((string)($backupInfo['file'] ?? ''))) {
                $message .= ' A verified target backup was created before the failed step.';
            }

            if ($envBackup !== null && is_file($envBackup)) {
                @copy($envBackup,$envFile);
            } elseif (!$hadExistingEnv && is_file($envFile)) {
                @unlink($envFile);
            }

            if ($bootstrapBackup !== null && is_file($bootstrapBackup)) {
                @copy($bootstrapBackup,$bootstrapPath);
            } elseif (!$hadExistingBootstrap && is_file($bootstrapPath)) {
                @unlink($bootstrapPath);
            }

            if ($cloudsaveCreated && is_file($createdCloudsavePath)) {
                @unlink($createdCloudsavePath);
            }

            $errors[] = $message;
        }
    }
}
if ($success) {
    if ($autoConfig !== null) {
        $returnUrl = browser_finalization_return_url($autoConfig, true);
        if ($returnUrl !== null) {
            header('Location: ' . $returnUrl, true, 303);
            exit;
        }
    }
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
        <p><a href="/admin/?page=migration">Migrate an existing GDPS database</a></p>

        <h2>Important</h2>
        <p>Delete <code>public/shared-install.php</code> from your hosting account if the file still exists.</p>
        <p>The target database was backed up before migrations. Keep that backup until you have tested the new server.</p>
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

if ($autoConfig !== null) {
    $returnUrl = browser_finalization_return_url($autoConfig, false);
    if ($returnUrl !== null) {
        header('Location: ' . $returnUrl, true, 303);
        exit;
    }
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
            <strong>Shared-hosting mode:</strong>
            this installer does not need Docker, sudo, or a hosting Terminal when you use the self-contained shared-hosting package.
            Composer dependencies are included in that package.
        </div>
        <?php if (!$isHttps): ?>
            <div class="err" style="margin-top:12px">
                <strong>HTTPS is required.</strong>
                Open this installer through <code>https://</code> before entering database or administrator credentials.
            </div>
        <?php endif; ?>
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
                    <input id="db_host" name="db_host" value="<?= e((string)($_POST['db_host'] ?? $defaultHost)) ?>" required>
                    <small>Often <code>localhost</code>, but use the value your host gives you.</small>
                </div>

                <div>
                    <label for="db_port">Database port</label>
                    <input id="db_port" name="db_port" value="<?= e((string)($_POST['db_port'] ?? $defaultPort)) ?>" required>
                </div>

                <div>
                    <label for="db_name">Database name</label>
                    <input id="db_name" name="db_name" value="<?= e((string)($_POST['db_name'] ?? $defaultDb)) ?>" required>
                </div>

                <div>
                    <label for="db_user">Database username</label>
                    <input id="db_user" name="db_user" value="<?= e((string)($_POST['db_user'] ?? $defaultUser)) ?>" required>
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
        <p><strong>Tip:</strong> with the official shared-hosting ZIP, <code>vendor/</code> is already included. You only need FTP/file-manager access and a MySQL/MariaDB database.</p>
        <p><strong>Backup safety:</strong> the installer creates and verifies a compressed backup before applying migrations. It will stop rather than overwrite a non-MuchoCore database.</p>
    </div>
</div>
<div style="max-width:920px;width:calc(100% - 32px);margin:auto auto 0;padding:16px 0 12px;border-top:1px solid #d9dee5;text-align:center;color:#7a838f;font-size:11px;line-height:1.7">
    Powered by MuchoCore · Copyright © <?= date('Y') ?> IZK ·
    <a href="https://github.com/IZKGMD" target="_blank" rel="noopener noreferrer" style="color:#687587;text-decoration:none">GitHub</a>
</div>
</body>
</html>
