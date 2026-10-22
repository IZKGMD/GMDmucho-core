<?php
/**
 * Experimental PHP7 native GDPS first-time database installer.
 *
 * Does not install the modern PHP8 admin, jobs or plugins. Requires a local
 * secret in config/php7-setup-key.txt before any online database operation.
 * Only accept HTTPS and a disposable, empty database.
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
$root = dirname(__DIR__);
$cfg = $root . '/config/php7-local.php';
$lock = $root . '/config/php7-installed.lock';
$keyFile = $root . '/config/php7-setup-key.txt';
$https = (isset($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
$allowed = PHP_VERSION_ID >= 70000 && extension_loaded('pdo_mysql')
    && $https && is_dir($root . '/config')
    && !is_file($cfg) && !is_file($lock)
    && is_file($keyFile) && is_readable($keyFile)
    && is_writable($root . '/config');
if (!$allowed) {
    http_response_code(503);
    echo 'Native PHP7 setup is disabled or the host does not satisfy requirements.';
    exit;
}
$key = trim((string)file_get_contents($keyFile));
if (strlen($key) < 32 || strlen($key) > 256) {
    http_response_code(503);
    echo 'Create a strong secret in private config/php7-setup-key.txt first.';
    exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('muchocore7_setup');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}
if (empty($_SESSION['mucho7_csrf'])) {
    $_SESSION['mucho7_csrf'] = bin2hex(random_bytes(24));
}
$error = '';
$done = false;
if ((isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET')
    === 'POST') {
    $submittedKey = isset($_POST['setup_key']) && is_string($_POST['setup_key'])
        ? $_POST['setup_key'] : '';
    $token = isset($_POST['csrf']) && is_string($_POST['csrf'])
        ? $_POST['csrf'] : '';
    $bodySize = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($bodySize > 8192 || !hash_equals($key, $submittedKey)
        || !hash_equals($_SESSION['mucho7_csrf'], $token)) {
        http_response_code(403);
        $error = 'Invalid setup authorization.';
    } else {
        $host = isset($_POST['host']) ? trim((string)$_POST['host']) : '';
        $db = isset($_POST['database']) ? trim((string)$_POST['database']) : '';
        $user = isset($_POST['username']) ? (string)$_POST['username'] : '';
        $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
        $port = isset($_POST['port']) ? (int)$_POST['port'] : 3306;
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $host)
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $db)
            || !preg_match('/^[^\x00-\x1f\x7f]{1,128}$/D', $user)
            || strlen($password) > 256 || $port < 1 || $port > 65535) {
            $error = 'Invalid database connection fields.';
        } else {
            // Single-process installer lock. DDL cannot be rolled back on MySQL,
            // so reject existing tables; never mutate a pre-existing GDPS.
            $lockHandle = @fopen($lock, 'x');
            if ($lockHandle === false) {
                http_response_code(409);
                $error = 'Setup lock already exists.';
            } else {
                @chmod($lock, 0600);
                try {
                    $pdo = new PDO(
                        'mysql:host=' . $host . ';port=' . $port . ';dbname='
                        . $db . ';charset=utf8mb4',
                        $user, $password,
                        array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                              PDO::ATTR_EMULATE_PREPARES=>false,
                              PDO::ATTR_TIMEOUT=>5)
                    );
                    require_once $root . '/compat/php7/schema.php';
                    mucho7_create_schema($pdo);
                    $options = array(
                        'host'=>$host, 'port'=>$port, 'database'=>$db,
                        'username'=>$user, 'password'=>$password,
                        'auth_pepper'=>bin2hex(random_bytes(32)),
                        // PHP7 is unsupported: account services remain
                        // disabled until explicitly enabled for isolated tests.
                        'enable_accounts'=>false,
                        'enable_registration'=>false,
                        'enable_writes'=>false,
                        'enable_cloudsave'=>false
                    );
                    $tmp = $cfg . '.tmp.' . bin2hex(random_bytes(8));
                    $serialized = "<?php\nreturn " . var_export($options, true) . ";\n";
                    if (file_put_contents($tmp, $serialized, LOCK_EX) === false) {
                        throw new RuntimeException('Unable to write private DB configuration.');
                    }
                    @chmod($tmp, 0600);
                    if (!rename($tmp, $cfg)) {
                        @unlink($tmp);
                        throw new RuntimeException('Unable to commit private DB configuration.');
                    }
                    @chmod($cfg, 0600);
                    fwrite($lockHandle, "Installed; keep this lock file private.\n");
                    fclose($lockHandle);
                    @unlink($keyFile);
                    $done = true;
                } catch (Exception $exception) {
                    fclose($lockHandle);
                    @unlink($lock);
                    $error = 'Database setup failed. Inspect PHP error logs. '
                        . 'A partial schema may remain; use a NEW empty database to retry.';
                    error_log('[MuchoCore PHP7 installer] Could not initialize disposable database.');
                }
            }
        }
    }
}
function mucho7_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MuchoCore Shared — PHP7 Experimental Install</title>
<style>body{background:#101426;color:#e8ecff;font:16px system-ui,sans-serif;margin:auto;max-width:740px;padding:30px}main{background:#1d2440;border-radius:16px;padding:28px}input{width:100%;background:#0d1223;border:1px solid #546081;border-radius:8px;padding:12px;color:white;margin:6px 0 18px}button{padding:12px 20px;border:0;border-radius:8px;cursor:pointer}p{line-height:1.55}strong{color:#ffd6a1}small{color:#b7c2e2}</style></head><body><main>
<h1>MuchoCore PHP7 — Experimental Setup</h1>
<p><strong>Unsupported PHP runtime. For isolated testing only.</strong>
This prepares native database tables on this host, not a complete MuchoCore server.
No VPS required; player accounts, admin and other capabilities are not certified.</p>
<?php if ($done): ?>
<p>Schema and private config created. Delete <code>public/php7-setup.php</code>.
The runtime remains disabled until explicitly enabled. Do not put public player credentials on PHP7.</p>
<?php else: ?>
<?php if ($error !== ''): ?><p><strong><?php echo mucho7_h($error); ?></strong></p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?php echo mucho7_h($_SESSION['mucho7_csrf']); ?>">
<label>One-time secret from private config/php7-setup-key.txt</label>
<input type="password" name="setup_key" required autocomplete="off" maxlength="256">
<label>MySQL hostname</label><input name="host" value="localhost" required maxlength="120">
<label>MySQL port</label><input name="port" type="number" value="3306" min="1" max="65535">
<label>Empty database name</label><input name="database" required maxlength="64">
<label>Database username</label><input name="username" required maxlength="128">
<label>Database password</label><input type="password" name="password" autocomplete="off">
<button type="submit">Initialize disposable database</button>
</form>
<?php endif; ?>
<small>The installer refuses any existing MuchoCore tables and writes no secrets to the response.</small>
</main></body></html>
