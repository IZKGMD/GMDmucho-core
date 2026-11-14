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
$setupNonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-".$setupNonce."'; form-action 'self'; frame-ancestors 'none'");
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
        $ownerName = isset($_POST['admin_username']) ? trim((string)$_POST['admin_username']) : 'admin';
        $ownerPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
        $ownerConfirm = isset($_POST['admin_password_confirm']) ? (string)$_POST['admin_password_confirm'] : '';
        $ownerEmail = isset($_POST['admin_email']) ? trim((string)$_POST['admin_email']) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $ownerName)
            || strlen($ownerPassword) < 8 || strlen($ownerPassword) > 72
            || $ownerPassword !== $ownerConfirm
            || strlen($ownerEmail) > 254 || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Check the administrator username, email and matching passwords (8–72 bytes).';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $host)
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
                    require_once $root . '/compat/php7/admin.php';
                    // The private setup-key + database credentials already
                    // establish installation authority. Enroll the first
                    // owner now; don't ask for the same password again.
                    mucho7_admin_bootstrap($pdo, $options, array(
                        'db_password'=>$password,
                        'userName'=>$ownerName,
                        'password'=>$ownerPassword,
                        'email'=>$ownerEmail
                    ), true);
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
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Настройка MuchoCore на 5v.pl</title>
<style>body{background:#101426;color:#e8ecff;font:16px system-ui,sans-serif;margin:auto;max-width:740px;padding:30px}main{background:#1d2440;border-radius:16px;padding:28px}input{width:100%;background:#0d1223;border:1px solid #546081;border-radius:8px;padding:12px;color:white;margin:6px 0 18px}button{padding:12px 20px;border:0;border-radius:8px;cursor:pointer}p{line-height:1.55}strong{color:#ffd6a1}small{color:#b7c2e2}</style></head><body><main>
<h1>Настройка MuchoCore на 5v.pl</h1>
<p><strong>Это тестовая версия для PHP 7.</strong> Поддержка всех функций Geometry Dash ещё не подтверждена. Для установки не нужен VPS.</p>
<p>Шаг 1 из 3: создай <strong>пустую базу MySQL</strong> в панели 5v.pl. Затем заполни данные ниже и придумай пароль администратора. Далее мастер направит тебя через миграцию ядра и настройку клиента.</p>
<?php if ($done): ?>
<h2>Шаг 2 из 3: автоматическое включение ядра</h2>
<p>База и администратор созданы. Остался один вход в админку — MuchoCore сам выполнит оставшиеся шаги миграции.</p>
<ol><li>Открой <a href="php7-admin.php?page=system">админку MuchoCore</a> и войди под новым администратором.</li>
<li>Миграция запустится автоматически: с резервной копией, проверкой таблиц и продолжением шагов. Оставь вкладку открытой. Если соединение оборвётся или появится ошибка, войди снова — этапы безопасно возобновятся; повторная установка не нужна.</li>
<li>После завершения откроется <strong>Client patcher</strong>. Подготовь свой клиент Geometry Dash и проверь работу с тестовыми аккаунтами.</li></ol>
<p>Мастер создания базы теперь закрыт. PHP 7 остаётся экспериментальной версией; FTP-загрузка и миграция не гарантируют полную совместимость клиента.</p>
<?php else: ?>
<?php if ($error !== ''): ?><p><strong><?php echo mucho7_h($error); ?></strong></p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?php echo mucho7_h($_SESSION['mucho7_csrf']); ?>">
<div id="manualSetupKey"><label>Ключ установки (при ручной установке)</label>
<input type="password" name="setup_key" required autocomplete="off" maxlength="256">
<small>Если ты пришёл из установщика MuchoCore, ключ подставится автоматически.</small></div>
<label>Сервер MySQL (из панели 5v.pl)</label><input name="host" value="localhost" required maxlength="120">
<label>Порт MySQL</label><input name="port" type="number" value="3306" min="1" max="65535">
<label>Имя пустой базы</label><input name="database" required maxlength="64">
<label>Логин базы MySQL</label><input name="username" required maxlength="128">
<label>Пароль базы MySQL</label><input type="password" name="password" autocomplete="off">
<h2>Создай администратора</h2>
<p>Это твой логин для панели. После установки входи с ним, ничего повторно регистрировать не нужно.</p>
<label>Логин администратора</label><input name="admin_username" value="admin" required maxlength="20" autocomplete="username">
<label>Почта администратора</label><input name="admin_email" type="email" required maxlength="254" autocomplete="email">
<label>Пароль администратора</label><input name="admin_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
<label>Повтори пароль администратора</label><input name="admin_password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
<button type="submit">Завершить настройку базы и администратора</button>
</form>
<?php endif; ?>
<script nonce="<?php echo mucho7_h($setupNonce); ?>">
(function(){
  var input=document.querySelector('input[name="setup_key"]');
  if(!input){
    try{sessionStorage.removeItem('muchocore7InstallerKey');}catch(_){}
    <?php if ($done): ?>
    // The database and owner are ready. Continue directly to the admin
    // sign-in; after authentication the protected migration auto-starts.
    window.location.replace('php7-admin.php?page=system');
    <?php endif; ?>
    return;
  }
  var fragment=window.location.hash;
  var secret=fragment.slice(0,7)==='#setup='?fragment.slice(7):'';
  if(/^[a-f0-9]{64}$/.test(secret)){
    try{sessionStorage.setItem('muchocore7InstallerKey',secret);}catch(_){}
    if(window.history && history.replaceState){
      history.replaceState(null,document.title,window.location.pathname+window.location.search);
    }
  }else{
    try{secret=sessionStorage.getItem('muchocore7InstallerKey')||'';}catch(_){}
  }
  if(!/^[a-f0-9]{64}$/.test(secret))return;
  input.value=secret;
  var manual=document.getElementById('manualSetupKey');
  if(manual)manual.style.display='none';
})();
</script>
<small>Установщик работает только с пустой базой. Существующие аккаунты он не изменяет.</small>
</main></body></html>
