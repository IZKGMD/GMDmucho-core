<?php
declare(strict_types=1);

use MuchoCore\Branding\BrandingService;
use MuchoCore\Database\Database;

require dirname(__DIR__,2).'/vendor/autoload.php';

$__muchoAdminRequestId = bin2hex(random_bytes(8));

/**
 * Never expose internal exception details through the administrator UI.
 * Keep the full diagnostic server-side and return a stable generic response.
 */
function muchoAdminHandleException(Throwable $e): void
{
    global $__muchoAdminRequestId;

    error_log(sprintf(
        '[MuchoCore Admin] request=%s %s: %s | %s:%d',
        $__muchoAdminRequestId,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Request-ID: ' . $__muchoAdminRequestId);
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>MuchoCore Admin</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#07090f;color:#f4f7ff;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:560px;padding:32px;border:1px solid #263246;border-radius:16px;background:#0f1520;text-align:center}p{color:#8e9bb0;line-height:1.6}.id{font:12px ui-monospace,monospace;color:#69758a;word-break:break-all}</style>'
        . '</head><body><main class="box"><h1>Something went wrong</h1>'
        . '<p>The administrator operation could not be completed. Try again later.</p>'
        . '<p class="id">Request ID: ' . htmlspecialchars(
            $__muchoAdminRequestId,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . '</p></main></body></html>';
    exit;
}

set_exception_handler('muchoAdminHandleException');
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $exception = new Error($error['message']);
    muchoAdminHandleException($exception);
});

try {
    $db=(new Database())->connection();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log('[MuchoCore Admin] database unavailable: '.$e->getMessage());

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MuchoCore Admin</title><style>body{font-family:system-ui,sans-serif;background:#07090f;color:#f4f7ff;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:520px;padding:32px;border:1px solid #263246;border-radius:16px;background:#0f1520;text-align:center}p{color:#8e9bb0;line-height:1.6}</style></head><body><main class="box"><h1>MuchoCore Admin</h1><p>The database is temporarily unavailable. Please try again shortly.</p><p style="margin:24px 0 0;padding-top:14px;border-top:1px solid #263246;color:#69758a;font-size:12px">Powered by <a href="https://github.com/IZKGMD/GMDmucho-core" target="_blank" rel="noopener noreferrer" style="color:#8f9cff;text-decoration:none">MuchoCore 🛡️</a></p></main></body></html>';
    exit;
}

$brandingService=new BrandingService($db);
$branding=$brandingService->get();
$brandInitial=mb_substr($branding['server_name'],0,1,'UTF-8');
if ($brandInitial==='' || !preg_match('/^[\pL\pN]$/u',$brandInitial)) {
    $brandInitial='S';
}
$brandInitial=mb_strtoupper($brandInitial,'UTF-8');

$rootDir=dirname(__DIR__,2);

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR',$rootDir);
}

if (!defined('CONTROL_DIR')) {
    define(
        'CONTROL_DIR',
        (string)(
            $_ENV['MUCHO_CONTROL_DIR']
            ?? getenv('MUCHO_CONTROL_DIR')
            ?: $rootDir.'/storage/control'
        )
    );
}

if (!defined('BACKUP_DIR')) {
    define(
        'BACKUP_DIR',
        (string)(
            $_ENV['MUCHO_BACKUP_DIR']
            ?? getenv('MUCHO_BACKUP_DIR')
            ?: $rootDir.'/storage/backups/admin-v2'
        )
    );
}

@mkdir(CONTROL_DIR,0770,true);
@mkdir(BACKUP_DIR,0770,true);

$__muchoIsHttps =
    (($_SERVER['HTTPS'] ?? '') === 'on') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_secure', $__muchoIsHttps ? '1' : '0');
ini_set('session.cookie_samesite','Strict');

session_name('MUCHO_ADMIN');
session_start();

const MUCHO_ADMIN_IDLE_TIMEOUT = 1800;
const MUCHO_ADMIN_MAX_SESSION = 86400;

if (isset($_SESSION['admin']) && is_array($_SESSION['admin'])) {
    $now = time();
    $loginAt = (int)($_SESSION['admin_login_at'] ?? 0);
    $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);

    if (
        $loginAt <= 0 ||
        $lastActivity <= 0 ||
        ($now - $loginAt) > MUCHO_ADMIN_MAX_SESSION ||
        ($now - $lastActivity) > MUCHO_ADMIN_IDLE_TIMEOUT
    ) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                (string)($params['path'] ?? '/'),
                (string)($params['domain'] ?? ''),
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();
        header('Location:/admin/');
        exit;
    }

    $_SESSION['admin_last_activity'] = $now;
}

/* =========================================================
   ADMIN TABLES
========================================================= */

$db->exec("
CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'admin',
    totp_secret VARCHAR(64) NULL,
    access_key_hash VARCHAR(255) NULL,
    access_key_created_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$db->exec("
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NULL,
    username VARCHAR(64) NOT NULL,
    action VARCHAR(128) NOT NULL,
    target VARCHAR(255) NULL,
    metadata TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_audit_time(created_at),
    KEY idx_admin_audit_action(action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Bootstrap first admin */
$count=(int)$db->query(
    'SELECT COUNT(*) FROM admin_users'
)->fetchColumn();

if ($count===0) {
    $bootstrapPath=(string)(
        $_ENV['MUCHO_ADMIN_BOOTSTRAP']
        ?? getenv('MUCHO_ADMIN_BOOTSTRAP')
        ?: $rootDir.'/storage/admin-bootstrap.php'
    );

    if (!is_file($bootstrapPath)) {
        throw new RuntimeException(
            'Admin bootstrap file is missing: '.$bootstrapPath
        );
    }

    $cfg=require $bootstrapPath;

    $q=$db->prepare(
        'INSERT INTO admin_users
         (username,password_hash,role)
         VALUES (:u,:p,"owner")'
    );

    $q->execute([
        'u'=>$cfg['username'],
        'p'=>$cfg['password_hash']
    ]);
}

/* =========================================================
   HELPERS
========================================================= */

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES|ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function checkCsrf(): void
{
    if (
        empty($_POST['csrf']) ||
        !hash_equals(csrf(),(string)$_POST['csrf'])
    ) {
        http_response_code(403);
        exit('CSRF rejected');
    }
}

function admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function rank(string $role): int
{
    return match($role) {
        'owner'=>40,
        'admin'=>30,
        'moderator'=>20,
        'viewer'=>10,
        default=>0
    };
}

function requireRank(int $rank): void
{
    $a=admin();

    if (!$a || rank($a['role'])<$rank) {
        throw new RuntimeException('Insufficient permissions.');
    }
}

function flash(string $text,string $type='ok'): void
{
    $_SESSION['flash']=[
        'text'=>$text,
        'type'=>$type
    ];
}

function audit(
    PDO $db,
    string $action,
    ?string $target=null,
    mixed $metadata=null
): void {
    $a=admin();

    $q=$db->prepare(
        'INSERT INTO admin_audit_logs
         (admin_user_id,username,action,target,metadata,ip)
         VALUES (:id,:user,:action,:target,:meta,:ip)'
    );

    $q->execute([
        'id'=>$a['id'] ?? null,
        'user'=>$a['username'] ?? 'unknown',
        'action'=>$action,
        'target'=>$target,
        'meta'=>$metadata===null
            ? null
            : json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE|
                JSON_UNESCAPED_SLASHES
            ),
        'ip'=>\MuchoCore\Http\ClientIp::resolve($_SERVER)
    ]);
}

function rootOp(string $op): string
{
    $allowed=[
        'status',
        'nginx-test',
        'nginx-reload',
        'php-restart',
        'cloudflare-restart',
        'composer',
        'migrate',
        'backup-code',
        'backup-db',
        'error-log',
        'access-log',
        'php-log',
        'cloudflare-log'
    ];

    if (!in_array($op,$allowed,true)) {
        throw new RuntimeException('Operation denied.');
    }

    $ops='/usr/local/sbin/mucho-admin-ops';

    if (!is_executable($ops)) {
        return 'This system operation is available on VPS installs only.';
    }

    return (string)shell_exec(
        'sudo '.$ops.' '.
        escapeshellarg($op).
        ' 2>&1'
    );
}

function tableExists(PDO $db,string $table): bool
{
    try {
        $q=$db->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name=:table
             LIMIT 1'
        );

        $q->execute(['table'=>$table]);

        return (bool)$q->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function countTable(PDO $db,string $table): int
{
    if (!tableExists($db,$table)) {
        return 0;
    }

    return (int)$db->query(
        "SELECT COUNT(*) FROM `$table`"
    )->fetchColumn();
}

function postLocal(string $path,array $data): string
{
    if (
        !preg_match(
            '~^/[A-Za-z0-9_./-]+\.php$~',
            $path
        )
    ) {
        throw new RuntimeException('Invalid endpoint.');
    }

    $accountUrl=(string)(
        getenv('MUCHO_ACCOUNT_URL')
        ?: 'https://localhost'
    );

    $publicHost=(string)(
        parse_url($accountUrl,PHP_URL_HOST)
        ?: 'localhost'
    );

    $ctx=stream_context_create([
        'http'=>[
            'method'=>'POST',
            'header'=>
                "Content-Type: application/x-www-form-urlencoded\r\n".
                "Host: ".$publicHost."\r\n",
            'content'=>http_build_query($data),
            'timeout'=>10,
            'ignore_errors'=>true
        ]
    ]);

    // The app and Caddy run in separate Docker containers. Reach Caddy by
    // service name instead of using the app container's 127.0.0.1.
    $r=@file_get_contents(
        'http://caddy'.$path,
        false,
        $ctx
    );

    return $r===false ? 'REQUEST_FAILED' : $r;
}

/* =========================================================
   TOTP
========================================================= */

function b32decode(string $secret): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret=strtoupper(
        preg_replace('/[^A-Z2-7]/','',$secret)
    );

    $bits='';

    foreach(str_split($secret) as $c) {
        $p=strpos($alphabet,$c);

        if ($p===false) continue;

        $bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);
    }

    $out='';

    foreach(str_split($bits,8) as $byte) {
        if (strlen($byte)===8) {
            $out.=chr(bindec($byte));
        }
    }

    return $out;
}

function totp(string $secret,int $offset=0): string
{
    $counter=intdiv(time(),30)+$offset;
    $bin=pack('N*',0).pack('N*',$counter);

    $hash=hash_hmac(
        'sha1',
        $bin,
        b32decode($secret),
        true
    );

    $o=ord(substr($hash,-1))&15;

    $code=(
        ((ord($hash[$o])&127)<<24) |
        ((ord($hash[$o+1])&255)<<16) |
        ((ord($hash[$o+2])&255)<<8) |
        (ord($hash[$o+3])&255)
    ) % 1000000;

    return str_pad((string)$code,6,'0',STR_PAD_LEFT);
}

function verifyTotp(string $secret,string $code): bool
{
    if (!preg_match('/^\d{6}$/',$code)) {
        return false;
    }

    return hash_equals(totp($secret,-1),$code)
        || hash_equals(totp($secret,0),$code)
        || hash_equals(totp($secret,1),$code);
}

function newTotpSecret(): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out='';

    for($i=0;$i<32;$i++) {
        $out.=$alphabet[random_int(0,31)];
    }

    return $out;
}

function newAccessKey(): string
{
    return 'MUCHO-'.strtoupper(bin2hex(random_bytes(24)));
}

function totpProvisioningUri(
    string $secret,
    string $account,
    string $issuer
): string {
    $issuer=trim($issuer) !== ''
        ? trim($issuer)
        : 'MuchoCore';

    $account=trim($account) !== ''
        ? trim($account)
        : 'admin';

    return 'otpauth://totp/'.
        rawurlencode($issuer).':'.rawurlencode($account).
        '?secret='.rawurlencode($secret).
        '&issuer='.rawurlencode($issuer).
        '&algorithm=SHA1'.
        '&digits=6'.
        '&period=30';
}

/* =========================================================
   LOGIN
========================================================= */

if (isset($_POST['login'])) {
    $user=trim((string)($_POST['username'] ?? ''));
    $password=(string)($_POST['password'] ?? '');
    $accessKey=trim((string)($_POST['access_key'] ?? ''));
    $otp=trim((string)($_POST['otp'] ?? ''));

    $ip=\MuchoCore\Http\ClientIp::resolve($_SERVER);
    $rate='/tmp/mucho-admin-'.hash('sha256',$ip);

    $state=[
        'start'=>time(),
        'tries'=>0
    ];

    if (is_file($rate)) {
        $x=json_decode(
            (string)file_get_contents($rate),
            true
        );

        if (is_array($x)) $state=$x;
    }

    if (time()-(int)$state['start']>900) {
        $state=[
            'start'=>time(),
            'tries'=>0
        ];
    }

    if ((int)$state['tries']>=8) {
        $loginError='Too many attempts.';
    } else {
        $q=$db->prepare(
            'SELECT *
             FROM admin_users
             WHERE username=:u
               AND is_active=1
             LIMIT 1'
        );

        $q->execute(['u'=>$user]);
        $row=$q->fetch(PDO::FETCH_ASSOC);

        $passwordOk=$row
            && $password!==''
            && password_verify(
                $password,
                $row['password_hash']
            );

        $accessKeyOk=$row
            && $accessKey!==''
            && !empty($row['access_key_hash'])
            && password_verify(
                $accessKey,
                $row['access_key_hash']
            );

        $ok=$passwordOk || $accessKeyOk;

        if (
            $ok &&
            !empty($row['totp_secret'])
        ) {
            $ok=verifyTotp(
                $row['totp_secret'],
                $otp
            );
        }

        if ($ok) {
            session_regenerate_id(true);

            $_SESSION['admin']=[
                'id'=>(int)$row['id'],
                'username'=>$row['username'],
                'role'=>$row['role']
            ];

            $_SESSION['admin_login_at']=time();
            $_SESSION['admin_last_activity']=time();

            $_SESSION['csrf']=bin2hex(random_bytes(32));

            @unlink($rate);

            audit($db,$accessKeyOk && !$passwordOk ? 'login.access_key' : 'login');

            header('Location:/admin/');
            exit;
        }

        $state['tries']++;

        file_put_contents(
            $rate,
            json_encode($state),
            LOCK_EX
        );

        $loginError='Invalid credentials.';
    }
}

if (isset($_GET['logout'])) {
    if (admin()) {
        audit($db,'logout');
    }

    $_SESSION=[];
    session_destroy();

    header('Location:/admin/');
    exit;
}

/* =========================================================
   WEB CLIENT PATCHER DOWNLOAD
========================================================= */

if (admin() && isset($_GET['client_download'])) {
    handleClientPatcherDownload($rootDir);
}

if (admin() && isset($_GET['android_download'])) {
    handleAndroidPatcherDownload($rootDir);
}

/* =========================================================
   BACKUP DOWNLOAD
========================================================= */

if (admin() && isset($_GET['download'])) {
    requireRank(40);

    $name=basename((string)$_GET['download']);

    if (!preg_match('/^[A-Za-z0-9._-]+$/',$name)) {
        exit('Invalid file');
    }

    $file=BACKUP_DIR.'/'.$name;

    if (!is_file($file)) {
        http_response_code(404);
        exit('Not found');
    }

    audit($db,'backup.download',$name);

    header('Content-Type: application/octet-stream');
    header(
        'Content-Disposition: attachment; filename="'.
        str_replace('"','',$name).'"'
    );
    header('Content-Length: '.filesize($file));

    readfile($file);
    exit;
}

/* =========================================================
   LOGIN PAGE
========================================================= */

if (!admin()):
?>
<!doctype html>
<html lang="ru">
<head>
<link rel="icon" href="/assets/muchocore-icon.png" type="image/jpeg">
<link rel="apple-touch-icon" href="/assets/muchocore-icon.png">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($branding['server_name'])?> Control</title>
<style>
:root{
--bg:#070910;
--sidebar:#0b0f16;
--panel:#111620;
--panel2:#151b27;
--panel3:#1a2130;
--border:#242d3c;
--border2:#303a4d;
--text:#f2f5ff;
--muted:#8995aa;
--accent:#7866ff;
--accent2:#9688ff;
--green:#40dc9c;
--red:#ff6477;
--yellow:#ffc95f;
--shadow:0 18px 60px rgba(0,0,0,.25);
--sidebar-width:245px
}

*{box-sizing:border-box}

html{scroll-behavior:smooth}

body{
margin:0;
background:
radial-gradient(circle at 85% -10%,rgba(120,102,255,.09),transparent 34%),
var(--bg);
color:var(--text);
font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
font-size:14px
}

a{color:inherit;text-decoration:none}

button,input,select,textarea{font:inherit}

.shell{display:flex;min-height:100vh}

/* SIDEBAR */

aside{
position:fixed;
inset:0 auto 0 0;
z-index:50;
width:var(--sidebar-width);
background:rgba(11,15,22,.96);
backdrop-filter:blur(18px);
border-right:1px solid var(--border);
padding:14px 11px;
overflow-y:auto;
transition:.22s ease
}

.brandrow{
display:flex;
align-items:center;
justify-content:space-between;
gap:8px;
padding:5px 7px 17px
}

.logo{
font-size:21px;
font-weight:900;
letter-spacing:-.7px
}

.logo b{color:var(--accent2)}

.sidebar-toggle{
width:34px;
height:34px;
padding:0;
display:grid;
place-items:center;
background:var(--panel2);
border:1px solid var(--border);
border-radius:9px
}

.nav-title{
font-size:10px;
font-weight:800;
letter-spacing:.11em;
text-transform:uppercase;
color:#596579;
padding:16px 11px 6px
}

nav a{
display:flex;
align-items:center;
min-height:39px;
padding:9px 11px;
margin:2px 0;
border-radius:9px;
color:#9ca8ba;
font-size:13px;
font-weight:570;
transition:.13s ease
}

nav a:hover{
background:#161c27;
color:#fff
}

nav a.on{
background:linear-gradient(135deg,#28234a,#1d2031);
box-shadow:inset 0 0 0 1px #41396a;
color:#fff
}

.logout{
margin-top:14px!important;
color:#ff8996!important
}

/* COLLAPSED */

body.sidebar-collapsed aside{
width:74px
}

body.sidebar-collapsed main{
margin-left:74px;
width:calc(100% - 74px)
}

body.sidebar-collapsed .logo{
font-size:0
}

body.sidebar-collapsed  .logo:after{
content:var(--brand-initial,"M");
font-size:22px;
font-weight:900;
color:var(--accent2)
}

body.sidebar-collapsed .nav-title{
display:none
}

body.sidebar-collapsed nav a{
font-size:0;
justify-content:center
}

body.sidebar-collapsed nav a:after{
content:"•";
font-size:19px
}

/* MAIN */

main{
margin-left:var(--sidebar-width);
width:calc(100% - var(--sidebar-width));
min-height:100vh;
display:flex;
flex-direction:column;
padding:24px 28px 60px;
transition:.22s ease;
min-width:0
}

.head{
position:sticky;
top:0;
z-index:35;
display:flex;
align-items:center;
gap:18px;
justify-content:space-between;
margin:-24px -28px 20px;
padding:17px 28px;
background:rgba(7,9,16,.88);
backdrop-filter:blur(18px);
border-bottom:1px solid rgba(36,45,60,.75)
}

.head-title{
min-width:180px
}

h1{
margin:0;
font-size:24px;
letter-spacing:-.6px
}

h2{
font-size:17px;
margin:25px 0 11px
}

h3{margin:0 0 12px}

small,.muted{color:var(--muted)}

.user-pill{
display:flex;
align-items:center;
gap:8px;
background:var(--panel);
border:1px solid var(--border);
border-radius:999px;
padding:7px 11px;
white-space:nowrap
}

.avatar{
width:27px;height:27px;
display:grid;place-items:center;
border-radius:50%;
background:linear-gradient(145deg,var(--accent),#4b3db9);
font-weight:850
}

/* GLOBAL SEARCH */

.global-search{
flex:1;
max-width:630px;
display:flex;
align-items:center;
background:#0c1119;
border:1px solid var(--border);
border-radius:11px;
overflow:hidden;
transition:.15s
}

.global-search:focus-within{
border-color:#6055a4;
box-shadow:0 0 0 3px rgba(120,102,255,.1)
}

.global-search select,
.global-search input{
border:0!important;
border-radius:0!important;
background:transparent!important;
min-height:40px
}

.global-search select{
width:105px;
border-right:1px solid var(--border)!important
}

.global-search input{
flex:1;
min-width:80px
}

.global-search button{
margin:4px;
padding:8px 13px
}

/* CARDS */

.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(165px,1fr));
gap:12px
}

.boxgrid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(290px,1fr));
gap:12px
}

.card{
background:linear-gradient(145deg,var(--panel),#0f141d);
border:1px solid var(--border);
border-radius:13px;
padding:16px;
box-shadow:0 8px 28px rgba(0,0,0,.08)
}

.card:hover{
border-color:#303a4d
}

.value{
font-size:28px;
font-weight:850;
letter-spacing:-1px;
margin-top:5px
}

/* TABLE */

.table{
position:relative;
background:var(--panel);
border:1px solid var(--border);
border-radius:13px;
overflow:auto;
margin:12px 0;
box-shadow:0 8px 30px rgba(0,0,0,.08)
}

table{
width:100%;
border-collapse:separate;
border-spacing:0;
min-width:820px
}

th,td{
font-size:12.5px;
text-align:left;
padding:10px 11px;
border-bottom:1px solid #202836;
vertical-align:middle
}

th{
position:sticky;
top:0;
z-index:3;
background:#0e131c;
color:#8793a8;
font-size:11px;
font-weight:750;
letter-spacing:.025em
}

tr:last-child td{border-bottom:0}

tbody tr:hover td{
background:#151b25
}

/* INPUTS */

input,select,textarea{
background:#090d14;
color:#f3f6ff;
border:1px solid #293448;
border-radius:8px;
padding:8px 9px;
outline:none;
transition:.13s
}

input:focus,select:focus,textarea:focus{
border-color:#6659ae;
box-shadow:0 0 0 3px rgba(120,102,255,.1)
}

textarea{
width:100%;
min-height:115px;
resize:vertical
}

input[type="checkbox"]{
accent-color:var(--accent);
width:16px;
height:16px
}

/* BUTTONS */

button,.btn{
display:inline-flex;
align-items:center;
justify-content:center;
gap:6px;
border:0;
border-radius:8px;
padding:8px 11px;
background:linear-gradient(135deg,var(--accent),#6755df);
color:#fff;
font-weight:720;
cursor:pointer;
transition:.13s;
white-space:nowrap
}

button:hover,.btn:hover{
transform:translateY(-1px);
filter:brightness(1.08)
}

button:active,.btn:active{
transform:translateY(0)
}

.gray{
background:#252e3e!important;
color:#d7deeb!important
}

.red{
background:#472029!important;
color:#ff9daa!important
}

.green{
background:#174432!important;
color:#78e9b2!important
}

/* SEARCH */

.search{
display:flex;
gap:8px;
align-items:center;
flex-wrap:wrap;
margin:12px 0
}

.search input{
width:min(440px,100%);
min-height:39px
}

/* UI */

.row{
display:flex;
gap:9px;
flex-wrap:wrap;
align-items:center
}

.badge{
display:inline-flex;
align-items:center;
padding:4px 8px;
border-radius:999px;
background:#222a38;
border:1px solid #2b3444;
color:#afb9ca;
font-size:11px
}

.ok{color:var(--green)}
.bad{color:var(--red)}

.flash{
position:relative;
padding:12px 14px;
border-radius:10px;
background:#153126;
border:1px solid #25553f;
color:#82e6b3;
margin-bottom:15px;
box-shadow:var(--shadow)
}

.flash.error{
background:#35171d;
border-color:#56232d;
color:#ffa4af
}

pre{
background:#06090e;
border:1px solid var(--border);
border-radius:10px;
padding:14px;
white-space:pre-wrap;
overflow:auto;
max-height:570px;
font-size:12px;
line-height:1.55
}

.bar{
height:7px;
background:#252d3c;
border-radius:20px;
overflow:hidden;
margin-top:6px
}

.bar>i{
height:100%;
display:block;
background:linear-gradient(90deg,var(--accent),#a494ff);
border-radius:20px
}

.section-actions{
display:flex;
gap:8px;
flex-wrap:wrap;
margin-bottom:13px
}

.quick-grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
gap:10px;
margin-bottom:17px
}

.quick-link{
padding:14px;
border:1px solid var(--border);
background:var(--panel);
border-radius:11px;
transition:.15s
}

.quick-link:hover{
border-color:#5d51a0;
background:#151b27;
transform:translateY(-1px)
}

/* MOBILE */

@media(max-width:950px){
.head{
flex-wrap:wrap
}
.global-search{
order:3;
max-width:none;
width:100%
}
}

@media(max-width:800px){
.shell{display:block}

aside{
position:static;
width:100%!important;
height:auto;
overflow:visible;
padding:10px
}

.brandrow{
padding-bottom:8px
}

.sidebar-toggle{display:none}

nav{
display:flex;
overflow-x:auto;
gap:4px;
padding-bottom:3px;
scrollbar-width:none
}

nav::-webkit-scrollbar{display:none}

.nav-title{display:none}

nav a,
body.sidebar-collapsed nav a{
font-size:12px;
white-space:nowrap;
justify-content:flex-start;
padding:9px 11px
}

body.sidebar-collapsed nav a:after{
display:none
}

.logout{margin-top:2px!important}

main,
body.sidebar-collapsed main{
margin:0;
width:100%;
padding:14px
}

.head{
position:static;
margin:-14px -14px 16px;
padding:14px;
background:#090c13
}

.head-title{
min-width:auto
}

h1{font-size:21px}

.user-pill{
padding:6px 9px
}

.global-search select{
width:95px
}

.card{
padding:14px
}

.table{
margin-left:-2px;
margin-right:-2px
}
}

@media(max-width:520px){
.user-pill .user-text{
display:none
}

.global-search button{
padding:8px 10px
}

.grid{
grid-template-columns:repeat(2,minmax(0,1fr))
}

.value{
font-size:23px
}

input,select{
max-width:100%
}
}

/* ==========================================================
   MUCHO MOBILE UX V4
========================================================== */

.mobile-menu-btn,
.mobile-overlay{
    display:none;
}

@media(max-width:800px){

    html,body{
        overflow-x:hidden;
    }

    body{
        background:#080a10;
    }

    /* OFF-CANVAS SIDEBAR */

    aside{
        position:fixed !important;
        z-index:1000;
        top:0;
        bottom:0;
        left:-290px;
        width:275px !important;
        height:100dvh;
        padding:14px 12px 28px;
        background:#0b0f16;
        border-right:1px solid #293143;
        overflow-y:auto;
        transition:left .22s ease;
        box-shadow:18px 0 55px rgba(0,0,0,.55);
    }

    body.mobile-menu-open aside{
        left:0;
    }

    .mobile-overlay{
        display:block;
        position:fixed;
        z-index:999;
        inset:0;
        background:rgba(0,0,0,.62);
        backdrop-filter:blur(2px);
        opacity:0;
        pointer-events:none;
        transition:opacity .2s ease;
    }

    body.mobile-menu-open .mobile-overlay{
        opacity:1;
        pointer-events:auto;
    }

    .brandrow{
        padding:5px 5px 15px;
    }

    .sidebar-toggle{
        display:none !important;
    }

    .nav-title{
        display:block !important;
        padding:16px 10px 5px;
        font-size:10px;
    }

    nav{
        display:block !important;
        overflow:visible !important;
    }

    nav a,
    body.sidebar-collapsed nav a{
        display:flex;
        justify-content:flex-start;
        width:100%;
        font-size:14px !important;
        padding:11px 12px;
        margin:3px 0;
        white-space:normal;
    }

    body.sidebar-collapsed nav a:after{
        display:none !important;
    }

    body.sidebar-collapsed .logo{
        font-size:21px !important;
    }

    body.sidebar-collapsed .logo:after{
        display:none;
    }

    /* MAIN */

    main,
    body.sidebar-collapsed main{
        margin-left:0 !important;
        width:100% !important;
        padding:14px 14px 55px;
    }

    /* HEADER */

    .head{
        position:sticky !important;
        top:0;
        z-index:80;
        display:grid !important;
        grid-template-columns:44px minmax(0,1fr) auto;
        gap:9px;
        align-items:center;
        margin:-14px -14px 17px !important;
        padding:11px 14px 12px !important;
        background:rgba(8,10,16,.96);
        border-bottom:1px solid #202736;
        backdrop-filter:blur(15px);
    }

    .mobile-menu-btn{
        display:grid;
        place-items:center;
        width:42px;
        height:42px;
        padding:0;
        border-radius:11px;
        font-size:20px;
        background:#171d29;
        border:1px solid #293347;
    }

    .head-title{
        min-width:0 !important;
    }

    .head-title h1{
        font-size:20px;
        line-height:1.15;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .head-title small{
        display:block;
        margin-top:3px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .user-pill{
        width:40px;
        height:40px;
        padding:5px !important;
        justify-content:center;
    }

    .user-pill .avatar{
        width:28px;
        height:28px;
    }

    .user-text{
        display:none !important;
    }

    /* GLOBAL SEARCH */

    .global-search{
        grid-column:1 / -1;
        order:10;
        width:100% !important;
        max-width:none !important;
        min-width:0;
        display:grid;
        grid-template-columns:105px minmax(0,1fr) auto;
        gap:0;
        border-radius:11px;
        overflow:hidden;
    }

    .global-search select{
        width:105px !important;
        min-width:0;
    }

    .global-search input{
        width:100%;
        min-width:0;
    }

    .global-search button{
        margin:4px;
        padding:8px 11px;
    }

    /* CARDS */

    .grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
        gap:9px;
    }

    .card{
        border-radius:13px;
        padding:14px;
    }

    .value{
        font-size:24px;
    }

    /* PLAYER CARDS */

    body[data-page="players"] .card{
        margin:10px 0 !important;
        overflow:hidden;
    }

    body[data-page="players"] .card > .row:first-child{
        margin-bottom:12px;
    }

    body[data-page="players"] .card form.row{
        display:grid !important;
        grid-template-columns:1fr 1fr;
        align-items:end;
        gap:9px;
        width:100%;
    }

    body[data-page="players"] .card form.row > input:not([type="hidden"]),
    body[data-page="players"] .card form.row > select{
        width:100% !important;
        max-width:none !important;
    }

    body[data-page="players"] .card form.row > input[name="username"],
    body[data-page="players"] .card form.row > input[name="email"]{
        grid-column:1 / -1;
    }

    body[data-page="players"] .card form.row > button{
        min-height:41px;
    }

    body[data-page="players"] .card form.row label{
        min-width:0;
    }

    body[data-page="players"] .card form.row label input{
        width:100% !important;
    }

    body[data-page="players"] .search{
        display:grid !important;
        grid-template-columns:minmax(0,1fr) auto;
        gap:8px;
    }

    body[data-page="players"] .search input{
        width:100% !important;
        min-width:0 !important;
    }

    /* TABLES */

    .table{
        width:calc(100vw - 28px);
        max-width:100%;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }

    table{
        min-width:720px;
    }

    th,td{
        white-space:nowrap;
    }

    /* FORMS */

    textarea{
        min-height:100px;
    }

    button,.btn{
        min-height:38px;
    }

    /* QUICK CARDS */

    .quick-grid{
        grid-template-columns:1fr 1fr !important;
        gap:9px;
    }

    .quick-link{
        padding:13px;
        min-width:0;
    }

    .quick-link small{
        display:block;
        margin-top:4px;
        line-height:1.35;
    }
}

@media(max-width:430px){

    .global-search{
        grid-template-columns:95px minmax(0,1fr);
    }

    .global-search button{
        grid-column:1 / -1;
        width:calc(100% - 8px);
    }

    body[data-page="players"] .card form.row{
        grid-template-columns:1fr 1fr;
    }

    body[data-page="players"] .card form.row > button{
        grid-column:auto;
    }

    .quick-grid{
        grid-template-columns:1fr !important;
    }
}

</style>
<link rel="stylesheet" href="/muchocore-theme.css?v=3">
<script src="/muchocore-theme.js?v=3" defer></script>
<?php if ($page==='admins'): ?>
<script src="/admin/assets/qrcode.min.js?v=20260925" id="muchoQrRenderer"></script>
<?php endif; ?>
</head>
<body class="admin-login" data-page="">
<form method="post" class="box">
<div class="logo"><img class="mucho-brand-logo" src="/assets/muchocore-logo.jpg" alt="MuchoCore" width="42" height="42" decoding="async"><span class="mucho-brand-name"><?=h($branding['server_name'])?></span><span>Control</span></div>
<div class="sub">GDPS Administration</div>

<?php if (!empty($loginError)): ?>
<div class="err"><?=h($loginError)?></div>
<?php endif ?>

<input
 name="username"
 autocomplete="username"
 placeholder="Logs"
>

<input
 type="password"
 name="password"
 autocomplete="current-password"
 placeholder="Password"
>

<input
 name="access_key"
 autocomplete="off"
 spellcheck="false"
 placeholder="Access Key (optional)"
>

<input
 name="otp"
 inputmode="numeric"
 autocomplete="one-time-code"
 placeholder="2FA code, if enabled"
>

<button name="login" value="1">Sign in</button>
<div style="margin-top:16px;color:#7f8aa0;font-size:12px;text-align:center"><?=h($branding['server_name'])?><?php if (!empty($branding['server_by_name']) && !empty($branding['social_url'])): ?> · Server by <a href="<?=h($branding['social_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($branding['server_by_name'])?></a><?php endif; ?> · Powered by MuchoCore · Copyright © <?=date('Y')?> IZK · <a href="https://github.com/IZKGMD" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:4px;color:#7d8ba3;text-decoration:none;vertical-align:middle"><svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5a12 12 0 0 0-3.79 23.39c.6.11.82-.26.82-.58v-2.24c-3.34.73-4.04-1.61-4.04-1.61-.55-1.4-1.34-1.77-1.34-1.77-1.09-.75.08-.74.08-.74 1.2.08 1.83 1.23 1.83 1.23 1.07 1.83 2.8 1.3 3.48.99.11-.77.42-1.3.76-1.6-2.67-.3-5.47-1.34-5.47-5.94 0-1.31.47-2.38 1.24-3.22-.12-.3-.54-1.52.12-3.17 0 0 1.01-.32 3.3 1.23a11.5 11.5 0 0 1 6 0c2.29-1.55 3.3-1.23 3.3-1.23.66 1.65.24 2.87.12 3.17.77.84 1.24 1.91 1.24 3.22 0 4.61-2.8 5.63-5.48 5.93.43.37.81 1.1.81 2.22v3.29c0 .32.22.69.83.57A12 12 0 0 0 12 .5"/></svg><span>GitHub</span></a></div>
</form>

<script>
(() => {
    const body = document.body;
    const toggle = document.getElementById('sidebarToggle');

    if (localStorage.getItem('mucho.sidebar') === 'collapsed') {
        body.classList.add('sidebar-collapsed');
    }

    toggle?.addEventListener('click', () => {
        body.classList.toggle('sidebar-collapsed');

        localStorage.setItem(
            'mucho.sidebar',
            body.classList.contains('sidebar-collapsed')
                ? 'collapsed'
                : 'open'
        );
    });

    const search = document.getElementById('globalSearchInput');

    document.addEventListener('keydown', e => {
        const tag = document.activeElement?.tagName;

        if (
            (e.key === '/' || (e.ctrlKey && e.key.toLowerCase() === 'k')) &&
            !['INPUT','TEXTAREA','SELECT'].includes(tag)
        ) {
            e.preventDefault();
            search?.focus();
            search?.select();
        }

        if (e.key === 'Escape') {
            document.activeElement?.blur();
        }
    });

    const dangerous = new Set([
        'account-delete',
        'comment-delete',
        'message-delete',
        'relation-delete'
    ]);

    document.querySelectorAll('form').forEach(form => {
        if (form.hasAttribute('onsubmit')) return;

        const action =
            form.querySelector('input[name="action"]')?.value;

        if (!dangerous.has(action)) return;

        form.addEventListener('submit', e => {
            if (!confirm('Confirm this action?')) {
                e.preventDefault();
            }
        });
    });

    document.querySelectorAll('.table td').forEach(td => {
        const text = td.textContent.trim();

        if (text.length > 60 && !td.title) {
            td.title = text;
        }
    });

    document.querySelectorAll('nav a').forEach(link => {
        if (!link.title) {
            link.title = link.textContent.trim();
        }
    });
})();
</script>


<script>
(() => {
    const body = document.body;
    const open = document.getElementById('mobileMenuButton');
    const overlay = document.getElementById('mobileOverlay');

    const closeMenu = () => {
        body.classList.remove('mobile-menu-open');
    };

    open?.addEventListener('click', () => {
        body.classList.toggle('mobile-menu-open');
    });

    overlay?.addEventListener('click', closeMenu);

    document.querySelectorAll('aside nav a').forEach(link => {
        link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeMenu();
        }
    });
})();
</script>

</body>
</html>
<?php
exit;
endif;

/* =========================================================
   ACTIONS
========================================================= */


require_once __DIR__.'/client-features-module.php';
require_once __DIR__.'/client-patcher-module.php';
require_once __DIR__.'/android-client-patcher-module.php';
require_once __DIR__.'/client-release-upload-module.php';

require_once __DIR__.'/security-monitoring-module.php';
require_once __DIR__.'/db-backup-center-module.php';
require_once __DIR__.'/core/AdminRouter.php';
require_once __DIR__.'/pages/dashboard.php';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    checkCsrf();

    $action=(string)($_POST['action'] ?? '');

    if (str_starts_with($action,'client-patcher-')) {
        handleClientPatcherAction($db,$rootDir,$action);
    }

    if (str_starts_with($action,'android-patcher-')) {
        handleAndroidPatcherAction($db,$rootDir,$action);
    }

    if (str_starts_with($action,'v4-')) {
        require __DIR__.'/advanced-actions.php';
    }


    try {

        /* ACCOUNTS */

        if ($action==='account-save') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $role=trim((string)$_POST['role']);

            $roleQuery=$db->prepare(
                'SELECT id
                 FROM roles
                 WHERE code=:role
                 LIMIT 1'
            );

            $roleQuery->execute([
                'role'=>$role
            ]);

            $roleId=$roleQuery->fetchColumn();

            if($roleId===false) {
                throw new RuntimeException('Bad role');
            }

            $q=$db->prepare(
                'UPDATE accounts SET
                    username=:username,
                    email=:email,
                    role_id=:role_id,
                    is_active=:active,
                    is_banned=:banned
                 WHERE account_id=:id'
            );

            $q->execute([
                'username'=>substr(
                    trim((string)$_POST['username']),
                    0,
                    20
                ),
                'email'=>substr(
                    trim((string)$_POST['email']),
                    0,
                    254
                ),
                'role_id'=>(int)$roleId,
                'active'=>isset($_POST['active'])?1:0,
                'banned'=>isset($_POST['banned'])?1:0,
                'id'=>$id
            ]);

            audit($db,'account.save',(string)$id);
            flash('Account saved.');
        }

        elseif ($action==='music-upload') {
            requireRank(30);

            $title=trim((string)($_POST['title'] ?? ''));
            $artist=trim((string)($_POST['artist'] ?? ''));

            if ($title==='' || mb_strlen($title,'UTF-8')>128) {
                throw new RuntimeException('Invalid song title.');
            }

            if ($artist==='' || mb_strlen($artist,'UTF-8')>128) {
                throw new RuntimeException('Invalid artist.');
            }

            if (
                !isset($_FILES['music_file']) ||
                ($_FILES['music_file']['error'] ?? -1)!==UPLOAD_ERR_OK
            ) {
                throw new RuntimeException('MP3 upload failed.');
            }

            $file=$_FILES['music_file'];
            $size=(int)($file['size'] ?? 0);

            if ($size<=0 || $size>(20*1024*1024)) {
                throw new RuntimeException('MP3 must be between 1 byte and 20 MB.');
            }

            $tmp=(string)($file['tmp_name'] ?? '');

            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('Invalid upload.');
            }

            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);

            if (!in_array($mime,['audio/mpeg','audio/mp3','audio/x-mpeg'],true)) {
                throw new RuntimeException('Only MP3 files are allowed.');
            }

            $musicDir=$rootDir.'/storage/music-public';

            if (
                !is_dir($musicDir) &&
                !mkdir($musicDir,0770,true) &&
                !is_dir($musicDir)
            ) {
                throw new RuntimeException('Cannot create music directory.');
            }

            $stored=bin2hex(random_bytes(20)).'.mp3';
            $target=$musicDir.'/'.$stored;

            if (!move_uploaded_file($tmp,$target)) {
                throw new RuntimeException('Cannot store MP3.');
            }

            @chmod($target,0640);

            $baseUrl=rtrim(
                (string)(
                    getenv('MUCHO_ACCOUNT_URL')
                    ?: (
                        'https://'.
                        (string)($_SERVER['HTTP_HOST'] ?? 'localhost')
                    )
                ),
                '/'
            );

            $download=$baseUrl.'/music/'.rawurlencode($stored);

            try {
                $q=$db->prepare(
                    'INSERT INTO songs
                     (name,author_id,author_name,size,download_url,is_verified)
                     VALUES (:name,0,:author,:size,:url,1)'
                );

                $q->execute([
                    'name'=>$title,
                    'author'=>$artist,
                    'size'=>round($size/1024/1024,2),
                    'url'=>$download
                ]);
            } catch(Throwable $e) {
                @unlink($target);
                throw $e;
            }

            audit(
                $db,
                'music.upload',
                (string)$db->lastInsertId(),
                [
                    'title'=>$title,
                    'artist'=>$artist,
                    'size'=>$size
                ]
            );

            flash('Music uploaded successfully.');
        }


        elseif ($action==='music-verify') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid song ID.');
            }

            $q=$db->prepare(
                'UPDATE songs
                 SET is_verified=1
                 WHERE id=:id'
            );

            $q->execute([
                'id'=>$id
            ]);

            audit(
                $db,
                'music.verify',
                (string)$id
            );

            flash('Song verified.');
        }

        elseif ($action==='music-unverify') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid song ID.');
            }

            $q=$db->prepare(
                'UPDATE songs
                 SET is_verified=0
                 WHERE id=:id'
            );

            $q->execute([
                'id'=>$id
            ]);

            audit(
                $db,
                'music.unverify',
                (string)$id
            );

            flash('Song hidden from public music.');
        }

        elseif ($action==='music-delete') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid song ID.');
            }

            $q=$db->prepare(
                'SELECT download_url
                 FROM songs
                 WHERE id=:id
                 LIMIT 1'
            );

            $q->execute([
                'id'=>$id
            ]);

            $song=$q->fetch(PDO::FETCH_ASSOC);

            if (!$song) {
                throw new RuntimeException('Song not found.');
            }

            $pathPart=(string)(
                parse_url(
                    (string)$song['download_url'],
                    PHP_URL_PATH
                ) ?? ''
            );

            $file=basename($pathPart);

            if (preg_match('/^[a-f0-9]{40}\\.mp3$/i',$file)===1) {
                $local=$rootDir.'/storage/music-public/'.$file;

                if (is_file($local)) {
                    @unlink($local);
                }
            }

            $q=$db->prepare(
                'DELETE FROM songs
                 WHERE id=:id'
            );

            $q->execute([
                'id'=>$id
            ]);

            audit(
                $db,
                'music.delete',
                (string)$id
            );

            flash('Song deleted.');
        }

        elseif ($action==='profile-save') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $fields=[
                'stars',
                'moons',
                'diamonds',
                'secret_coins',
                'user_coins',
                'demons',
                'creator_points'
            ];

            $set=[];
            $args=['id'=>$id];

            foreach($fields as $f) {
                $set[]="`$f`=:$f";
                $args[$f]=max(
                    0,
                    (int)($_POST[$f] ?? 0)
                );
            }

            $q=$db->prepare(
                'UPDATE profiles SET '.
                implode(',',$set).
                ' WHERE account_id=:id'
            );

            $q->execute($args);

            audit($db,'profile.save',(string)$id);
            flash('Statistics saved.');
        }


        /* MUCHO_PROFILE_ADMIN_V1_ACTIONS */

        elseif ($action==='muchoprofile-save') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid Account ID.');
            }

            $check=$db->prepare(
                'SELECT account_id FROM accounts
                 WHERE account_id=:id LIMIT 1'
            );
            $check->execute(['id'=>$id]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Account not found.');
            }

            $db->exec("
                CREATE TABLE IF NOT EXISTS mucho_profile_customization (
                    account_id BIGINT PRIMARY KEY,
                    display_name VARCHAR(32) NOT NULL DEFAULT '',
                    status VARCHAR(48) NOT NULL DEFAULT '',
                    bio VARCHAR(240) NOT NULL DEFAULT '',
                    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
                    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
                    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
                    title VARCHAR(48) NOT NULL DEFAULT '',
                    badges TEXT NOT NULL,
                    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
                    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
                    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
                    online_visible TINYINT NOT NULL DEFAULT 1,
                    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $ensure=$db->prepare("
                INSERT INTO mucho_profile_customization
                (
                    account_id,
                    badges
                )
                VALUES
                (
                    :id,
                    '[]'
                )
                ON DUPLICATE KEY UPDATE
                    account_id=VALUES(account_id)
            ");
            $ensure->execute(['id'=>$id]);

            $cut=static function(string $v,int $max): string {
                $v=trim(str_replace(["\0","\r"],'',$v));

                return function_exists('mb_substr')
                    ? mb_substr($v,0,$max,'UTF-8')
                    : substr($v,0,$max);
            };

            $displayName=$cut(
                (string)($_POST['display_name'] ?? ''),
                32
            );

            $status=$cut(
                (string)($_POST['status'] ?? ''),
                48
            );

            $bio=$cut(
                (string)($_POST['bio'] ?? ''),
                240
            );

            $title=$cut(
                (string)($_POST['title'] ?? ''),
                48
            );

            $primary=strtoupper(
                trim((string)($_POST['theme_primary'] ?? ''))
            );

            if (!preg_match('/^#[0-9A-F]{6}$/',$primary)) {
                $primary='#42D9CF';
            }

            $secondary=strtoupper(
                trim((string)($_POST['theme_secondary'] ?? ''))
            );

            if (!preg_match('/^#[0-9A-F]{6}$/',$secondary)) {
                $secondary='#806EFF';
            }

            $banner=trim(
                (string)($_POST['banner'] ?? 'gradient_01')
            );

            if (!preg_match('/^[A-Za-z0-9_-]{0,64}$/',$banner)) {
                $banner='gradient_01';
            }

            $favorite=$cut(
                (string)($_POST['favorite_difficulty'] ?? ''),
                32
            );

            $showcase=$cut(
                (string)($_POST['showcase'] ?? ''),
                200
            );

            /*
             * Badges are controlled by administrators only.
             * Accept:
             * OWNER, IZK
             * or JSON ["OWNER","IZK"]
             */
            $badgeInput=trim(
                (string)($_POST['badges'] ?? '')
            );

            $badges=[];

            if ($badgeInput!=='') {
                if (str_starts_with($badgeInput,'[')) {
                    $decoded=json_decode($badgeInput,true);

                    if (is_array($decoded)) {
                        $badges=$decoded;
                    }
                } else {
                    $badges=preg_split(
                        '/[,\r\n]+/',
                        $badgeInput
                    ) ?: [];
                }
            }

            $cleanBadges=[];

            foreach($badges as $badge) {
                $badge=$cut((string)$badge,32);

                if ($badge==='') {
                    continue;
                }

                if (!in_array($badge,$cleanBadges,true)) {
                    $cleanBadges[]=$badge;
                }

                if (count($cleanBadges)>=8) {
                    break;
                }
            }

            $badgeJson=json_encode(
                $cleanBadges,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $pinned=[];

            foreach(
                preg_split(
                    '/[,\s]+/',
                    trim((string)($_POST['pinned_levels'] ?? ''))
                ) ?: []
                as $levelID
            ) {
                if (
                    $levelID!=='' &&
                    ctype_digit($levelID) &&
                    (int)$levelID>0
                ) {
                    $pinned[]=(string)(int)$levelID;
                }

                if (count($pinned)>=3) {
                    break;
                }
            }

            $q=$db->prepare("
                UPDATE mucho_profile_customization SET
                    display_name=:display_name,
                    status=:status,
                    bio=:bio,
                    theme_primary=:primary,
                    theme_secondary=:secondary,
                    banner=:banner,
                    title=:title,
                    badges=:badges,
                    pinned_levels=:pinned,
                    showcase=:showcase,
                    favorite_difficulty=:favorite,
                    online_visible=:online
                WHERE account_id=:id
            ");

            $q->execute([
                'display_name'=>$displayName,
                'status'=>$status,
                'bio'=>$bio,
                'primary'=>$primary,
                'secondary'=>$secondary,
                'banner'=>$banner,
                'title'=>$title,
                'badges'=>$badgeJson,
                'pinned'=>implode(',',$pinned),
                'showcase'=>$showcase,
                'favorite'=>$favorite,
                'online'=>isset($_POST['online_visible']) ? 1 : 0,
                'id'=>$id
            ]);

            audit(
                $db,
                'muchoprofile.save',
                (string)$id,
                [
                    'title'=>$title,
                    'badges'=>$cleanBadges
                ]
            );

            flash('Mucho Profile saved.');
        }

        elseif ($action==='muchoprofile-token') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid Account ID.');
            }

            $check=$db->prepare(
                'SELECT account_id FROM accounts
                 WHERE account_id=:id LIMIT 1'
            );
            $check->execute(['id'=>$id]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Account not found.');
            }

            $db->exec("
                CREATE TABLE IF NOT EXISTS mucho_profile_customization (
                    account_id BIGINT PRIMARY KEY,
                    display_name VARCHAR(32) NOT NULL DEFAULT '',
                    status VARCHAR(48) NOT NULL DEFAULT '',
                    bio VARCHAR(240) NOT NULL DEFAULT '',
                    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
                    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
                    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
                    title VARCHAR(48) NOT NULL DEFAULT '',
                    badges TEXT NOT NULL,
                    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
                    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
                    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
                    online_visible TINYINT NOT NULL DEFAULT 1,
                    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $ensure=$db->prepare("
                INSERT INTO mucho_profile_customization
                (account_id,badges)
                VALUES (:id,'[]')
                ON DUPLICATE KEY UPDATE
                    account_id=VALUES(account_id)
            ");
            $ensure->execute(['id'=>$id]);

            $token=rtrim(
                strtr(
                    base64_encode(random_bytes(24)),
                    '+/',
                    '-_'
                ),
                '='
            );

            $q=$db->prepare("
                UPDATE mucho_profile_customization
                SET edit_token_hash=:hash
                WHERE account_id=:id
            ");

            $q->execute([
                'hash'=>hash('sha256',$token),
                'id'=>$id
            ]);

            $_SESSION['muchoprofile_issued_token']=[
                'id'=>$id,
                'token'=>$token
            ];

            audit(
                $db,
                'muchoprofile.token.regenerate',
                (string)$id
            );

            flash(
                'New Mucho Profile edit token generated.'
            );
        }

        elseif ($action==='password-reset') {
            requireRank(30);

            $id=(int)$_POST['id'];
            $password=(string)$_POST['new_password'];

            if (strlen($password)<8) {
                throw new RuntimeException(
                    'Password must be at least 8 characters.'
                );
            }

            $gjp2=sha1(
                $password.'mI29fmAnxgTs'
            );

            $q=$db->prepare(
                'UPDATE accounts SET
                    password_hash=:p,
                    gjp2_hash=:g
                 WHERE account_id=:id'
            );

            $q->execute([
                'p'=>password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'g'=>password_hash(
                    $gjp2,
                    PASSWORD_DEFAULT
                ),
                'id'=>$id
            ]);

            audit($db,'account.password.reset',(string)$id);
            flash('Password changed.');
        }

        elseif ($action==='account-delete') {
            requireRank(40);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid account.');
            }

            $q=$db->prepare(
                'SELECT * FROM accounts
                 WHERE account_id=:id LIMIT 1'
            );
            $q->execute(['id'=>$id]);
            $account=$q->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                throw new RuntimeException(
                    'Account not found.'
                );
            }

            $q=$db->prepare(
                'SELECT * FROM profiles
                 WHERE account_id=:id LIMIT 1'
            );
            $q->execute(['id'=>$id]);
            $profile=$q->fetch(PDO::FETCH_ASSOC);

            $db->exec("
                CREATE TABLE IF NOT EXISTS admin_deleted_accounts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    account_id BIGINT UNSIGNED NOT NULL,
                    username VARCHAR(64) NULL,
                    snapshot LONGTEXT NOT NULL,
                    deleted_by VARCHAR(64) NOT NULL,
                    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_deleted_account(account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $snapshot=json_encode(
                [
                    'account'=>$account,
                    'profile'=>$profile
                ],
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $db->beginTransaction();

            try {
                $q=$db->prepare(
                    'INSERT INTO admin_deleted_accounts
                     (account_id,username,snapshot,deleted_by)
                     VALUES (:id,:username,:snapshot,:by)'
                );

                $q->execute([
                    'id'=>$id,
                    'username'=>$account['username'] ?? null,
                    'snapshot'=>$snapshot,
                    'by'=>admin()['username']
                ]);

                /* User level IDs */
                $q=$db->prepare(
                    'SELECT level_id FROM levels
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                $levelIds=array_map(
                    'intval',
                    $q->fetchAll(PDO::FETCH_COLUMN)
                );

                /* User comments + comments on user's levels */
                $commentIds=[];

                if (tableExists($db,'comments')) {
                    if ($levelIds) {
                        $in=implode(',',$levelIds);

                        $q=$db->prepare(
                            "SELECT id FROM comments
                             WHERE account_id=:id
                                OR level_id IN ($in)"
                        );
                    } else {
                        $q=$db->prepare(
                            'SELECT id FROM comments
                             WHERE account_id=:id'
                        );
                    }

                    $q->execute(['id'=>$id]);

                    $commentIds=array_map(
                        'intval',
                        $q->fetchAll(PDO::FETCH_COLUMN)
                    );
                }

                /* Likes */
                if (tableExists($db,'likes')) {
                    $q=$db->prepare(
                        'DELETE FROM likes
                         WHERE account_id=:id'
                    );
                    $q->execute(['id'=>$id]);

                    if ($levelIds) {
                        $db->exec(
                            'DELETE FROM likes
                             WHERE type=1
                               AND item_id IN ('.
                            implode(',',$levelIds).
                            ')'
                        );
                    }

                    if ($commentIds) {
                        $db->exec(
                            'DELETE FROM likes
                             WHERE type=2
                               AND item_id IN ('.
                            implode(',',$commentIds).
                            ')'
                        );
                    }
                }

                /* Messages */
                if (tableExists($db,'messages')) {
                    $q=$db->prepare(
                        'DELETE FROM messages
                         WHERE account_id=:a
                            OR to_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Friend requests */
                if (tableExists($db,'friend_requests')) {
                    $q=$db->prepare(
                        'DELETE FROM friend_requests
                         WHERE account_id=:a
                            OR to_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Friends */
                if (tableExists($db,'friends')) {
                    $q=$db->prepare(
                        'DELETE FROM friends
                         WHERE account_id=:a
                            OR friend_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Blocks */
                if (tableExists($db,'blocks')) {
                    $q=$db->prepare(
                        'DELETE FROM blocks
                         WHERE account_id=:a
                            OR blocked_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Comments */
                if (tableExists($db,'comments')) {
                    if ($levelIds) {
                        $in=implode(',',$levelIds);

                        $q=$db->prepare(
                            "DELETE FROM comments
                             WHERE account_id=:id
                                OR level_id IN ($in)"
                        );
                    } else {
                        $q=$db->prepare(
                            'DELETE FROM comments
                             WHERE account_id=:id'
                        );
                    }

                    $q->execute(['id'=>$id]);
                }

                if (tableExists($db,'account_comments')) {
                    $q=$db->prepare(
                        'DELETE FROM account_comments
                         WHERE account_id=:id'
                    );
                    $q->execute(['id'=>$id]);
                }

                /* Levels */
                $q=$db->prepare(
                    'DELETE FROM levels
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                /* Profile */
                $q=$db->prepare(
                    'DELETE FROM profiles
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                /* Account */
                $q=$db->prepare(
                    'DELETE FROM accounts
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                if ($q->rowCount()!==1) {
                    throw new RuntimeException(
                        'Failed to delete account.'
                    );
                }

                $db->commit();

                audit(
                    $db,
                    'account.delete',
                    (string)$id,
                    [
                        'username'=>$account['username']
                    ]
                );

                flash(
                    'Account #'.$id.' permanently deleted.'
                );

            } catch(Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $e;
            }
        }

        /* LEVELS */

        elseif ($action==='level-save') {
            requireRank(20);

            $id=(int)$_POST['id'];

            $q=$db->prepare(
                'UPDATE levels SET
                    name=:name,
                    stars=:stars,
                    difficulty=:difficulty,
                    demon=:demon,
                    demon_difficulty=:dd,
                    featured=:featured,
                    epic=:epic,
                    requested_stars=:requested,
                    downloads=:downloads,
                    likes=:likes,
                    is_deleted=:deleted
                 WHERE level_id=:id'
            );

            $q->execute([
                'name'=>substr(
                    (string)$_POST['name'],
                    0,
                    100
                ),
                'stars'=>max(
                    0,
                    min(10,(int)$_POST['stars'])
                ),
                'difficulty'=>max(
                    0,
                    (int)$_POST['difficulty']
                ),
                'demon'=>isset($_POST['demon'])?1:0,
                'dd'=>max(
                    0,
                    min(8,(int)($_POST['demon_difficulty'] ?? 0))
                ),
                'featured'=>isset($_POST['featured'])?1:0,
                'epic'=>max(0,min(3,(int)$_POST['epic'])),
                'requested'=>max(
                    0,
                    min(10,(int)$_POST['requested_stars'])
                ),
                'downloads'=>max(
                    0,
                    (int)$_POST['downloads']
                ),
                'likes'=>max(0,(int)$_POST['likes']),
                'deleted'=>isset($_POST['deleted'])?1:0,
                'id'=>$id
            ]);

            $creatorQuery=$db->prepare(
                'SELECT account_id FROM levels
                 WHERE level_id=:id
                 LIMIT 1'
            );
            $creatorQuery->execute(['id'=>$id]);
            $creatorId=(int)$creatorQuery->fetchColumn();

            if ($creatorId>0) {
                $cpQuery=$db->prepare(
                    'SELECT COALESCE(
                        SUM(
                            CASE WHEN stars>0 THEN 1 ELSE 0 END
                            + CASE WHEN featured>0 THEN 1 ELSE 0 END
                            + epic
                        ),
                        0
                    )
                    FROM levels
                    WHERE account_id=:account_id
                      AND is_deleted=0'
                );
                $cpQuery->execute(['account_id'=>$creatorId]);
                $creatorPoints=(int)$cpQuery->fetchColumn();

                $db->prepare(
                    'UPDATE profiles
                     SET creator_points=:cp
                     WHERE account_id=:account_id'
                )->execute([
                    'cp'=>$creatorPoints,
                    'account_id'=>$creatorId
                ]);
            }

            audit(
                $db,
                'level.save',
                (string)$id,
                [
                    'creator_account_id'=>$creatorId,
                    'creator_points'=>$creatorPoints ?? null
                ]
            );
            flash('Level saved and creator statistics synchronized.');
        }

        elseif ($action==='level-rate-save') {
            requireRank(20);

            $id=(int)($_POST['id'] ?? 0);
            if ($id<=0) {
                throw new RuntimeException('Invalid level ID.');
            }

            $stars=max(0,min(10,(int)($_POST['stars'] ?? 0)));
            $feature=max(0,min(4,(int)($_POST['feature'] ?? 0)));

            /*
             * One canonical difficulty selector for the admin UI.
             * Keep the old hidden-field fallback for compatibility with older
             * clients or bookmarks, but prefer the canonical profile value.
             */
            $difficultyProfiles=[
                'unrated'=>[
                    'difficulty'=>0,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>0,
                ],
                'auto'=>[
                    'difficulty'=>1,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>1,
                ],
                'easy'=>[
                    'difficulty'=>2,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>0,
                ],
                'normal'=>[
                    'difficulty'=>3,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>0,
                ],
                'hard'=>[
                    'difficulty'=>4,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>0,
                ],
                'harder'=>[
                    'difficulty'=>5,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>0,
                ],
                'insane'=>[
                    'difficulty'=>6,
                    'demon'=>0,
                    'demon_difficulty'=>0,
                    'auto_level'=>0,
                ],
                'easy-demon'=>[
                    'difficulty'=>6,
                    'demon'=>1,
                    'demon_difficulty'=>1,
                    'auto_level'=>0,
                ],
                'medium-demon'=>[
                    'difficulty'=>6,
                    'demon'=>1,
                    'demon_difficulty'=>2,
                    'auto_level'=>0,
                ],
                'hard-demon'=>[
                    'difficulty'=>6,
                    'demon'=>1,
                    'demon_difficulty'=>3,
                    'auto_level'=>0,
                ],
                'insane-demon'=>[
                    'difficulty'=>6,
                    'demon'=>1,
                    'demon_difficulty'=>4,
                    'auto_level'=>0,
                ],
                'extreme-demon'=>[
                    'difficulty'=>6,
                    'demon'=>1,
                    'demon_difficulty'=>5,
                    'auto_level'=>0,
                ],
            ];

            $difficultyProfile=trim((string)($_POST['difficulty_profile'] ?? ''));

            if ($difficultyProfile!=='') {
                if (!isset($difficultyProfiles[$difficultyProfile])) {
                    throw new RuntimeException('Invalid difficulty profile.');
                }

                $difficulty=(int)$difficultyProfiles[$difficultyProfile]['difficulty'];
                $demon=(int)$difficultyProfiles[$difficultyProfile]['demon'];
                $demonDifficulty=(int)$difficultyProfiles[$difficultyProfile]['demon_difficulty'];
                $autoLevel=(int)$difficultyProfiles[$difficultyProfile]['auto_level'];
            } else {
                /* Backward-compatible fallback for older admin forms. */
                $difficulty=max(0,min(6,(int)($_POST['difficulty'] ?? 0)));
                $demon=isset($_POST['demon']) ? 1 : 0;
                $autoLevel=isset($_POST['auto_level']) ? 1 : 0;
                $demonDifficulty=max(0,min(8,(int)($_POST['demon_difficulty'] ?? 0)));

                if ($demon && $autoLevel) {
                    throw new RuntimeException('A level cannot be Auto and Demon at the same time.');
                }

                if ($autoLevel) {
                    $difficulty=1;
                    $demon=0;
                    $demonDifficulty=0;
                } elseif ($demon) {
                    $difficulty=6;
                    if ($demonDifficulty<1) {
                        throw new RuntimeException('Select a demon difficulty.');
                    }
                } else {
                    $demonDifficulty=0;
                }
            }

            $featured=$feature>0 ? 1 : 0;
            $epic=match($feature) {
                2=>1,
                3=>2,
                4=>3,
                default=>0
            };

            $beforeQuery=$db->prepare(
                'SELECT level_id,account_id,name,stars,difficulty,demon,
                        demon_difficulty,auto_level,featured,epic,
                        requested_stars,is_deleted
                 FROM levels
                 WHERE level_id=:id
                 LIMIT 1'
            );
            $beforeQuery->execute(['id'=>$id]);
            $before=$beforeQuery->fetch(PDO::FETCH_ASSOC);

            if (!$before) {
                throw new RuntimeException('Level not found.');
            }

            $db->beginTransaction();

            try {
                $q=$db->prepare(
                    'UPDATE levels SET
                        stars=:stars,
                        difficulty=:difficulty,
                        demon=:demon,
                        demon_difficulty=:demon_difficulty,
                        auto_level=:auto_level,
                        featured=:featured,
                        epic=:epic,
                        requested_stars=0,
                        updated_at=NOW()
                     WHERE level_id=:id'
                );

                $q->execute([
                    'stars'=>$stars,
                    'difficulty'=>$difficulty,
                    'demon'=>$demon,
                    'demon_difficulty'=>$demonDifficulty,
                    'auto_level'=>$autoLevel,
                    'featured'=>$featured,
                    'epic'=>$epic,
                    'id'=>$id
                ]);

                $creatorId=(int)$before['account_id'];

                $cpQuery=$db->prepare(
                    'SELECT COALESCE(
                        SUM(
                            CASE WHEN stars>0 THEN 1 ELSE 0 END
                            + CASE WHEN featured>0 THEN 1 ELSE 0 END
                            + epic
                        ),
                        0
                    )
                    FROM levels
                    WHERE account_id=:account_id
                      AND is_deleted=0'
                );
                $cpQuery->execute(['account_id'=>$creatorId]);
                $creatorPoints=(int)$cpQuery->fetchColumn();

                $sync=$db->prepare(
                    'UPDATE profiles
                     SET creator_points=:cp
                     WHERE account_id=:account_id'
                );
                $sync->execute([
                    'cp'=>$creatorPoints,
                    'account_id'=>$creatorId
                ]);

                $db->commit();

                $featureBefore=match(true) {
                    (int)$before['epic']>=3 => 4,
                    (int)$before['epic']===2 => 3,
                    (int)$before['epic']===1 => 2,
                    (int)$before['featured']>0 => 1,
                    default => 0
                };

                audit(
                    $db,
                    'level.rate',
                    (string)$id,
                    [
                        'name'=>$before['name'],
                        'stars_before'=>(int)$before['stars'],
                        'difficulty_before'=>(int)$before['difficulty'],
                        'demon_before'=>(int)$before['demon'],
                        'demon_difficulty_before'=>(int)$before['demon_difficulty'],
                        'feature_before'=>$featureBefore,
                        'stars'=>$stars,
                        'difficulty'=>$difficulty,
                        'demon'=>$demon,
                        'demon_difficulty'=>$demonDifficulty,
                        'auto_level'=>$autoLevel,
                        'feature'=>$feature,
                        'featured'=>$featured,
                        'epic'=>$epic,
                        'creator_account_id'=>$creatorId,
                        'creator_points'=>$creatorPoints,
                        'request_cleared'=>(int)$before['requested_stars']>0
                    ]
                );

                flash(
                    'Rating published for #'.$id.'. Creator Points recalculated: '.
                    number_format($creatorPoints).'.'
                );
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }

        elseif ($action==='comment-delete') {
            requireRank(20);

            $table=(string)$_POST['table'];
            $id=(int)$_POST['id'];

            if (!in_array(
                $table,
                ['comments','account_comments'],
                true
            )) {
                throw new RuntimeException('Invalid table');
            }

            $q=$db->prepare(
                "DELETE FROM `$table` WHERE id=:id"
            );
            $q->execute(['id'=>$id]);

            audit(
                $db,
                'comment.delete',
                "$table:$id"
            );

            flash('Comment deleted.');
        }

        elseif ($action==='message-delete') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $q=$db->prepare(
                'DELETE FROM messages WHERE id=:id'
            );
            $q->execute(['id'=>$id]);

            audit($db,'message.delete',(string)$id);
            flash('Message deleted.');
        }

        elseif ($action==='relation-delete') {
            requireRank(20);

            $table=(string)$_POST['table'];
            $id=(int)$_POST['id'];

            if (!in_array(
                $table,
                [
                    'friends',
                    'blocks',
                    'friend_requests'
                ],
                true
            )) {
                throw new RuntimeException('Invalid relation');
            }

            $q=$db->prepare(
                "DELETE FROM `$table` WHERE id=:id"
            );

            $q->execute(['id'=>$id]);

            audit(
                $db,
                'relation.delete',
                "$table:$id"
            );

            flash('Relation deleted.');
        }

        /* SETTINGS */

        elseif ($action==='settings-save') {
            requireRank(40);

            if (isset($_POST['maintenance'])) {
                file_put_contents(
                    CONTROL_DIR.'/maintenance.flag',
                    '1'
                );
            } else {
                @unlink(
                    CONTROL_DIR.'/maintenance.flag'
                );
            }

            if (isset($_POST['server_name'])) {
                $branding['server_name']=$brandingService->saveServerName(
                    (string)$_POST['server_name']
                );
            }

            $branding=$brandingService->saveServerCredit(
                (string)($_POST['server_by_name'] ?? ''),
                (string)($_POST['social_url'] ?? '')
            );

            if (isset($_POST['registrations_disabled'])) {
                file_put_contents(
                    CONTROL_DIR.'/registrations-disabled.flag',
                    '1'
                );
            } else {
                @unlink(
                    CONTROL_DIR.'/registrations-disabled.flag'
                );
            }

            audit($db,'settings.save');
            flash('Settings applied.');
        }

        /* ENDPOINT TESTER */

        elseif ($action==='endpoint-test') {
            requireRank(30);

            $endpoint=trim(
                (string)$_POST['endpoint']
            );

            $raw=trim(
                (string)$_POST['payload']
            );

            parse_str($raw,$data);

            $_SESSION['endpoint_result']=
                postLocal(
                    $endpoint,
                    is_array($data)?$data:[]
                );

            audit(
                $db,
                'endpoint.test',
                $endpoint
            );
        }

        /* SYSTEM */

        elseif ($action==='system-op') {
            requireRank(40);

            $op=(string)$_POST['op'];

            $_SESSION['system_output']=rootOp($op);

            audit($db,'system.'.$op);
            flash('Operation completed.');
        }

        /* ADMINS */

        elseif ($action==='admin-create') {
            requireRank(40);

            $username=trim(
                (string)$_POST['username']
            );

            $password=(string)$_POST['password'];
            $role=(string)$_POST['role'];

            if (
                !preg_match(
                    '/^[A-Za-z0-9_.-]{3,32}$/',
                    $username
                )
            ) {
                throw new RuntimeException(
                    'Invalid username.'
                );
            }

            if (strlen($password)<10) {
                throw new RuntimeException(
                    'Password must be at least 10 characters.'
                );
            }

            if (!in_array(
                $role,
                [
                    'owner',
                    'admin',
                    'moderator',
                    'viewer'
                ],
                true
            )) {
                throw new RuntimeException('Bad role');
            }

            $q=$db->prepare(
                'INSERT INTO admin_users
                 (username,password_hash,role)
                 VALUES (:u,:p,:r)'
            );

            $q->execute([
                'u'=>$username,
                'p'=>password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'r'=>$role
            ]);

            audit($db,'admin.create',$username);
            flash('Administrator created.');
        }

        elseif ($action==='admin-toggle') {
            requireRank(40);

            $id=(int)$_POST['id'];

            if ($id===(int)admin()['id']) {
                throw new RuntimeException(
                    'You cannot disable your own account.'
                );
            }

            $db->prepare(
                'UPDATE admin_users
                 SET is_active=1-is_active
                 WHERE id=:id'
            )->execute(['id'=>$id]);

            audit(
                $db,
                'admin.toggle',
                (string)$id
            );

            flash('Status changed.');
        }

        elseif ($action==='access-key-generate') {
            requireRank(10);

            $accessKey=newAccessKey();

            $db->prepare(
                'UPDATE admin_users
                 SET access_key_hash=:hash,
                     access_key_created_at=NOW()
                 WHERE id=:id'
            )->execute([
                'hash'=>password_hash($accessKey,PASSWORD_DEFAULT),
                'id'=>admin()['id']
            ]);

            $_SESSION['new_access_key']=$accessKey;

            audit($db,'access_key.generate');
            flash(
                'A new access key was generated. Copy it now; it is not stored in plaintext.'
            );
        }

        elseif ($action==='access-key-revoke') {
            requireRank(10);

            $db->prepare(
                'UPDATE admin_users
                 SET access_key_hash=NULL,
                     access_key_created_at=NULL
                 WHERE id=:id'
            )->execute([
                'id'=>admin()['id']
            ]);

            unset($_SESSION['new_access_key']);

            audit($db,'access_key.revoke');
            flash('Administrator access key revoked.');
        }

        elseif ($action==='2fa-generate') {
            requireRank(10);

            $_SESSION['pending_totp']=[
                'secret'=>newTotpSecret(),
                'created_at'=>time()
            ];

            audit($db,'2fa.setup.start');
            flash(
                'New authenticator setup started. Scan the QR code, then enter the 6-digit code.'
            );
        }

        elseif ($action==='2fa-cancel') {
            requireRank(10);

            unset($_SESSION['pending_totp']);

            audit($db,'2fa.setup.cancel');
            flash('Authenticator setup cancelled.');
        }

        elseif ($action==='2fa-enable') {
            requireRank(10);

            $pending=$_SESSION['pending_totp'] ?? null;

            if (is_array($pending)) {
                $secret=(string)($pending['secret'] ?? '');
                $createdAt=(int)($pending['created_at'] ?? 0);
            } else {
                $secret=(string)($pending ?? '');
                $createdAt=0;
            }

            $code=trim((string)$_POST['otp']);

            if (
                $secret==='' ||
                (
                    $createdAt>0 &&
                    (time()-$createdAt)>600
                ) ||
                !verifyTotp($secret,$code)
            ) {
                throw new RuntimeException(
                    'Invalid or expired authenticator code. Generate a new setup code and try again.'
                );
            }

            $q=$db->prepare(
                'UPDATE admin_users
                 SET totp_secret=:s
                 WHERE id=:id'
            );

            $q->execute([
                's'=>$secret,
                'id'=>admin()['id']
            ]);

            unset($_SESSION['pending_totp']);

            audit($db,'2fa.enable');
            flash('Google Authenticator 2FA enabled successfully.');
        }

        elseif ($action==='2fa-disable') {
            requireRank(10);

            $q=$db->prepare(
                'UPDATE admin_users
                 SET totp_secret=NULL
                 WHERE id=:id'
            );

            $q->execute([
                'id'=>admin()['id']
            ]);

            audit($db,'2fa.disable');
            flash('2FA disabled.');
        }

    } catch(Throwable $e) {
        global $__muchoAdminRequestId;

        error_log(sprintf(
            '[MuchoCore Admin] request=%s action=%s %s: %s | %s:%d',
            $__muchoAdminRequestId,
            $action,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        flash(
            'The operation could not be completed. Please try again.',
            'error'
        );
    }

    if ($action==='level-rate-save') {
        $id=(int)($_POST['id'] ?? 0);
        $q=(string)($_POST['return_q'] ?? '');
        header(
            'Location:/admin/?page=rating&id='.
            rawurlencode((string)$id).
            '&q='.
            rawurlencode($q)
        );
        exit;
    }

    $return=(string)(
        $_POST['return']
        ?? $_GET['page']
        ?? 'dashboard'
    );

    header(
        'Location:/admin/?page='.
        rawurlencode($return)
    );

    exit;
}

/* =========================================================
   PAGE DEFINITIONS
========================================================= */

$page=(string)($_GET['page'] ?? 'dashboard');

$pages=require __DIR__.'/config/pages.php';

if(!is_array($pages)){
    throw new RuntimeException(
        'Invalid admin page registry.'
    );
}

if (!isset($pages[$page])) {
    $page='dashboard';
}

$flash=$_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($branding['server_name'])?> Control</title>

<style>
:root{
--bg:#080a10;
--side:#0c1017;
--card:#111620;
--card2:#161c28;
--border:#222b39;
--text:#eef2ff;
--muted:#8792a7;
--accent:#7764ff;
--green:#39d995;
--red:#ff6377;
--yellow:#ffc85c
}
*{box-sizing:border-box}
body{
margin:0;background:var(--bg);color:var(--text);
font-family:Inter,system-ui,-apple-system,sans-serif
}
a{text-decoration:none;color:inherit}
.shell{min-height:100vh;display:flex}
aside{
width:235px;background:var(--side);
border-right:1px solid var(--border);
position:fixed;left:0;top:0;bottom:0;
padding:18px 13px;overflow-y:auto
}
.logo{
font-size:21px;font-weight:900;
padding:9px 12px 19px
}
.logo b{color:#8877ff}
nav a{
display:flex;padding:10px 12px;
border-radius:9px;margin:2px 0;
color:#9aa5b9;font-size:13px
}
nav a:hover,nav a.on{
background:#191f2b;color:white
}
main{
margin-left:235px;width:calc(100% - 235px);
padding:27px
}
.head{
display:flex;justify-content:space-between;
align-items:center;margin-bottom:20px
}
h1{margin:0;font-size:25px}
h2{font-size:18px;margin-top:25px}
small,.muted{color:var(--muted)}
.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
gap:13px
}
.card{
background:var(--card);
border:1px solid var(--border);
border-radius:14px;padding:17px
}
.value{
font-size:27px;font-weight:850;
margin-top:5px
}
.table{
background:var(--card);
border:1px solid var(--border);
border-radius:14px;overflow:auto;margin:13px 0
}
table{
width:100%;border-collapse:collapse;
min-width:850px
}
th,td{
font-size:12.5px;text-align:left;
padding:10px 12px;
border-bottom:1px solid #202836;
vertical-align:middle
}
th{
background:#0f141d;color:#8994a8;
position:sticky;top:0
}
input,select,textarea{
background:#090d14;color:white;
border:1px solid #293448;border-radius:8px;
padding:8px;font:inherit
}
textarea{width:100%;min-height:120px}
button,.btn{
border:0;border-radius:8px;
padding:8px 11px;background:var(--accent);
color:white;font-weight:700;cursor:pointer
}
.red{background:#4d2029!important;color:#ff9ba8!important}
.gray{background:#252e3e!important}
.green{background:#174432!important;color:#73e7ae!important}
.search{
display:flex;gap:8px;flex-wrap:wrap;
margin:12px 0
}
.search input{min-width:260px}
.flash{
padding:11px 13px;border-radius:10px;
background:#173125;color:#7be3ad;
margin:0 0 14px
}
.flash.error{
background:#3a181e;color:#ff9eaa
}
pre{
background:#06090e;border:1px solid var(--border);
border-radius:11px;padding:14px;
white-space:pre-wrap;overflow:auto;
max-height:620px;font-size:12px
}
.row{
display:flex;gap:10px;flex-wrap:wrap;
align-items:center
}
.badge{
padding:4px 8px;border-radius:999px;
background:#222a38;color:#abb5c7;
font-size:11px
}
.ok{color:var(--green)}
.bad{color:var(--red)}
.bar{
height:8px;background:#252d3c;
border-radius:20px;overflow:hidden;margin-top:6px
}
.bar>i{
height:100%;display:block;
background:#7564ff;border-radius:20px
}
.boxgrid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
gap:13px
}
.logout{color:#ff8794;margin-top:17px!important}

@media(max-width:800px){
.shell{display:block}
aside{
position:static;width:100%;height:auto;
overflow:auto
}
nav{
display:flex;overflow-x:auto;gap:3px
}
nav a{white-space:nowrap}
main{margin:0;width:100%;padding:15px}
.logo{padding-bottom:9px}
.head{margin-top:5px}
}
</style>

<style id="mucho-mobile-v5">
.mobile-menu-btn,
.mobile-overlay{
    display:none;
}

@media(max-width:800px){

html,body{
    width:100%;
    overflow-x:hidden;
}

.shell{
    display:block !important;
}

/* SIDEBAR */
aside{
    position:fixed !important;
    top:0 !important;
    bottom:0 !important;
    left:-285px !important;
    width:270px !important;
    height:100dvh !important;
    z-index:1001 !important;
    overflow-y:auto !important;
    background:#0b0f16 !important;
    border-right:1px solid #263044 !important;
    padding:14px 12px 30px !important;
    transition:left .22s ease !important;
    box-shadow:20px 0 60px rgba(0,0,0,.6);
}

body.mobile-menu-open aside{
    left:0 !important;
}

.mobile-overlay{
    display:block;
    position:fixed;
    inset:0;
    z-index:1000;
    background:rgba(0,0,0,.68);
    opacity:0;
    pointer-events:none;
    transition:.2s;
}

body.mobile-menu-open .mobile-overlay{
    opacity:1;
    pointer-events:auto;
}

/* SIDEBAR CONTENT */
.brandrow{
    display:flex !important;
    padding:5px 4px 15px !important;
}

.sidebar-toggle{
    display:none !important;
}

.nav-title{
    display:block !important;
    font-size:10px !important;
    padding:15px 10px 5px !important;
}

aside nav{
    display:block !important;
    overflow:visible !important;
}

aside nav a,
body.sidebar-collapsed aside nav a{
    display:flex !important;
    width:100% !important;
    font-size:14px !important;
    justify-content:flex-start !important;
    padding:11px 12px !important;
    margin:3px 0 !important;
    white-space:normal !important;
}

body.sidebar-collapsed aside nav a:after{
    display:none !important;
}

body.sidebar-collapsed .logo{
    font-size:21px !important;
}

body.sidebar-collapsed .logo:after{
    display:none !important;
}

/* MAIN */
main,
body.sidebar-collapsed main{
    margin-left:0 !important;
    width:100% !important;
    padding:14px 14px 55px !important;
}

/* HEADER */
.head{
    position:sticky !important;
    top:0 !important;
    z-index:100 !important;

    display:grid !important;
    grid-template-columns:44px minmax(0,1fr) 42px !important;
    gap:9px !important;
    align-items:center !important;

    margin:-14px -14px 18px !important;
    padding:11px 14px !important;

    background:rgba(8,10,16,.96) !important;
    border-bottom:1px solid #202838 !important;
    backdrop-filter:blur(16px);
}

.mobile-menu-btn{
    display:grid !important;
    place-items:center !important;
    width:42px !important;
    height:42px !important;
    padding:0 !important;
    font-size:20px !important;
    border-radius:11px !important;
}

.head-title{
    min-width:0 !important;
}

.head-title h1{
    margin:0 !important;
    font-size:21px !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

.head-title small{
    display:block !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
}

.user-pill{
    width:42px !important;
    height:42px !important;
    padding:5px !important;
    justify-content:center !important;
}

.user-pill .user-text{
    display:none !important;
}

/* GLOBAL SEARCH — separate row */
.global-search{
    grid-column:1 / -1 !important;
    width:100% !important;
    max-width:none !important;

    display:grid !important;
    grid-template-columns:105px minmax(0,1fr) auto !important;

    margin-top:2px !important;
}

.global-search select{
    width:105px !important;
    min-width:0 !important;
}

.global-search input{
    width:100% !important;
    min-width:0 !important;
}

.global-search button{
    margin:4px !important;
}

/* QUICK ACTIONS */
.quick-grid{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
    margin-bottom:16px !important;
}

.quick-link{
    display:block !important;
    min-width:0 !important;
    padding:14px !important;
}

.quick-link b{
    display:block !important;
    font-size:15px !important;
    margin-bottom:4px !important;
}

.quick-link small{
    display:block !important;
    line-height:1.35 !important;
}

/* STATS */
.grid{
    grid-template-columns:1fr 1fr !important;
    gap:10px !important;
}

.card{
    min-width:0 !important;
    overflow:hidden;
}

/* PLAYER SEARCH */
body[data-page="players"] .search{
    display:grid !important;
    grid-template-columns:minmax(0,1fr) auto !important;
    gap:8px !important;
}

body[data-page="players"] .search input{
    width:100% !important;
    min-width:0 !important;
}

/* PLAYER CARD */
body[data-page="players"] .card form.row{
    display:grid !important;
    grid-template-columns:1fr 1fr !important;
    gap:9px !important;
    width:100% !important;
}

body[data-page="players"] .card form.row
> input:not([type="hidden"]),
body[data-page="players"] .card form.row
> select{
    width:100% !important;
}

body[data-page="players"] input[name="username"],
body[data-page="players"] input[name="email"]{
    grid-column:1 / -1 !important;
}

/* TABLES */
.table{
    width:100% !important;
    max-width:100% !important;
    overflow-x:auto !important;
    -webkit-overflow-scrolling:touch;
}

table{
    min-width:720px !important;
}

}

@media(max-width:430px){

.global-search{
    grid-template-columns:95px minmax(0,1fr) !important;
}

.global-search button{
    grid-column:1 / -1 !important;
    width:calc(100% - 8px) !important;
}

.quick-grid{
    grid-template-columns:1fr !important;
}

}
</style>
<link rel="stylesheet" href="/admin/admin-i18n.css?v=2">
<link rel="stylesheet" href="/admin/admin-ui-v2.css?v=3">
<link rel="stylesheet" href="/muchocore-theme.css?v=3">
<script src="/muchocore-theme.js?v=3" defer></script>
</head>
<body style='--brand-initial:"<?=h($brandInitial)?>";'>

<div class="mobile-overlay" id="mobileOverlay"></div>

<div class="shell">

<aside>
<!-- MUCHO_LANG_FLAGS -->
<div id="muchoLangSwitcher" class="mucho-lang-switcher">
    <button type="button" data-lang="en" title="English">🇬🇧</button>
    <button type="button" data-lang="ru" title="Русский">🇷🇺</button>
</div>
<!-- /MUCHO_LANG_FLAGS -->

<div class="brandrow">
<div class="logo"><img class="mucho-brand-logo" src="/assets/muchocore-logo.jpg" alt="MuchoCore" width="42" height="42" decoding="async"><span class="mucho-brand-name"><?=h($branding['server_name'])?></span><b>Control</b></div>
<button
 type="button"
 class="sidebar-toggle"
 id="sidebarToggle"
 title="Collapse menu"
>☰</button>
</div>

<nav>

<div class="nav-title">Main</div>
<?php foreach(['dashboard','analytics','advanced','monitoring'] as $key): ?>
<a
 href="/admin/?page=<?=h($key)?>"
 title="<?=h($pages[$key])?>"
 class="<?=$page===$key?'on':''?>"
><?=h($pages[$key])?></a>
<?php endforeach ?>

<div class="nav-title">Content</div>
<?php foreach(['players','muchoprofiles','levels','moderation','rating','comments','messages','social','songs'] as $key): ?>
<a
 href="/admin/?page=<?=h($key)?>"
 title="<?=h($pages[$key])?>"
 class="<?=$page===$key?'on':''?>"
><?=h($pages[$key])?></a>
<?php endforeach ?>

<div class="nav-title">Tools</div>
<?php foreach(['database','endpoints','clientfeatures','clientpatcher','securitycenter','dbbackups','backups','settings','system'] as $key): ?>
<a
 href="/admin/?page=<?=h($key)?>"
 title="<?=h($pages[$key])?>"
 class="<?=$page===$key?'on':''?>"
><?=h($pages[$key])?></a>
<?php endforeach ?>

<div class="nav-title">Access</div>
<?php foreach(['admins','audit'] as $key): ?>
<a
 href="/admin/?page=<?=h($key)?>"
 title="<?=h($pages[$key])?>"
 class="<?=$page===$key?'on':''?>"
><?=h($pages[$key])?></a>
<?php endforeach ?>

<a class="logout" href="/admin/?logout=1">
Logout
</a>

</nav>
</aside>

<main>

<div class="head">

<button
 type="button"
 class="mobile-menu-btn"
 id="mobileMenuButton"
 aria-label="Open menu"
>☰</button>

<div class="head-title">
<h1><?=h($pages[$page])?></h1>
<small><?=h((string)(getenv('MUCHO_ACCOUNT_URL') ?: $branding['server_name']))?></small>
</div>

<form class="global-search" method="get" id="globalSearch">
<select name="page" aria-label="Search type">
<option value="advanced">Everywhere</option>
<option value="players">Players</option>
<option value="levels">Levels</option>
</select>

<input
 id="globalSearchInput"
 name="q"
 value="<?=h((string)($_GET['q'] ?? ''))?>"
 placeholder="Quick search…"
 autocomplete="off"
>

<button type="submit">Search</button>
</form>

<div class="user-pill">
<div class="avatar">
<?=h(strtoupper(substr(admin()['username'],0,1)))?>
</div>

<div class="user-text">
<b><?=h(admin()['username'])?></b><br>
<small><?=h(admin()['role'])?></small>
</div>
</div>

</div>

<?php if($flash): ?>
<div class="flash <?=h($flash['type'])?>">
<?=h($flash['text'])?>
</div>
<?php endif ?>

<?php

/* =========================================================
   DASHBOARD
========================================================= */

$adminRouter=buildMuchoAdminRouter();

if ($adminRouter->dispatch($page,$db)) {

    /*
     * Page rendered by modular admin router.
     */

}

elseif($page==='comments') {

foreach(
    [
        'comments'=>'Level comments',
        'account_comments'=>'Profile comments'
    ] as $table=>$label
) {
    if (!tableExists($db,$table)) continue;

    echo '<h2>'.h($label).'</h2>';

    $rows=$db->query(
        "SELECT * FROM `$table`
         ORDER BY id DESC LIMIT 120"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if ($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '<th></th></tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                $v=(string)$v;

                if (strlen($v)>120) {
                    $v=substr($v,0,120).'…';
                }

                echo '<td>'.h($v).'</td>';
            }

            echo '<td>
            <form method="post">
            <input type="hidden" name="csrf" value="'.csrf().'">
            <input type="hidden" name="action" value="comment-delete">
            <input type="hidden" name="return" value="comments">
            <input type="hidden" name="table" value="'.h($table).'">
            <input type="hidden" name="id" value="'.h($r['id']).'">
            <button class="red">Delete</button>
            </form>
            </td>';

            echo '</tr>';
        }
    }

    echo '</table></div>';
}
}

/* =========================================================
   MESSAGES
========================================================= */

elseif($page==='messages') {

if (!tableExists($db,'messages')) {
    echo '<div class="card">';
    echo '<h2 style="margin-top:0">Messages</h2>';
    echo '<p class="muted">The messages feature is not enabled in the current database schema.</p>';
    echo '<p class="muted small">No <code>messages</code> table exists, so the admin panel will not crash here anymore.</p>';
    echo '</div>';
} else {

$rows=$db->query(
    'SELECT
        m.*,
        a.username sender,
        b.username receiver
     FROM messages m
     LEFT JOIN accounts a ON a.account_id=m.account_id
     LEFT JOIN accounts b ON b.account_id=m.to_account_id
     ORDER BY m.id DESC LIMIT 150'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>ID</th><th>From</th><th>To</th><th>Subject</th><th>Body</th><th>Read</th><th></th></tr>';

foreach($rows as $r):
?>
<tr>
<td><?=h($r['id'])?></td>
<td><?=h($r['sender'] ?? $r['account_id'])?></td>
<td><?=h($r['receiver'] ?? $r['to_account_id'])?></td>
<td><?=h($r['subject'] ?? '')?></td>
<td><?=h($r['body'] ?? '')?></td>
<td><?=h($r['is_read'] ?? 0)?></td>
<td>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="message-delete">
<input type="hidden" name="return" value="messages">
<input type="hidden" name="id" value="<?=h($r['id'])?>">
<button class="red">Delete</button>
</form>
</td>
</tr>
<?php endforeach ?>

</table></div>
<?php
}
}

/* =========================================================
   SOCIAL
========================================================= */

elseif($page==='social') {

foreach(
    [
        'friend_requests',
        'friends',
        'blocks'
    ] as $table
) {
    if (!tableExists($db,$table)) continue;

    echo '<h2>'.h($table).'</h2>';

    $rows=$db->query(
        "SELECT * FROM `$table`
         ORDER BY id DESC LIMIT 120"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '<th></th></tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                echo '<td>'.h($v).'</td>';
            }

            echo '<td>
            <form method="post">
            <input type="hidden" name="csrf" value="'.csrf().'">
            <input type="hidden" name="action" value="relation-delete">
            <input type="hidden" name="return" value="social">
            <input type="hidden" name="table" value="'.h($table).'">
            <input type="hidden" name="id" value="'.h($r['id']).'">
            <button class="red">Delete</button>
            </form>
            </td>';

            echo '</tr>';
        }
    }

    echo '</table></div>';
}
}

/* =========================================================
   SONGS
========================================================= */

elseif($page==='songs') {

if (!tableExists($db,'songs')) {
    echo '<div class="card">Songs table does not exist.</div>';
} else {

if (rank(admin()['role'])>=30) {
    echo '<div class="card" style="margin-bottom:14px">';
    echo '<h2>Upload Music</h2>';
    echo '<p class="muted">MP3 only, maximum 20 MB.</p>';
    echo '<form method="post" enctype="multipart/form-data" class="row">';
    echo '<input type="hidden" name="csrf" value="'.csrf().'">';
    echo '<input type="hidden" name="action" value="music-upload">';
    echo '<input type="hidden" name="return" value="songs">';
    echo '<input name="title" maxlength="128" placeholder="Song title" required>';
    echo '<input name="artist" maxlength="128" placeholder="Artist" required>';
    echo '<input type="file" name="music_file" accept=".mp3,audio/mpeg" required>';
    echo '<button>Upload MP3</button>';
    echo '</form>';
    echo '</div>';
}

    $rows=$db->query(
        'SELECT * FROM songs ORDER BY 1 DESC LIMIT 150'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '<th>actions</th>';
        echo '</tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                $v=(string)$v;

                if(strlen($v)>100) {
                    $v=substr($v,0,100).'…';
                }

                echo '<td>'.h($v).'</td>';
            }

            echo '<td>';
            echo '<div class="row" style="gap:5px">';

            if((int)$r['is_verified']===1) {
                echo '<form method="post">
                    <input type="hidden" name="csrf" value="'.csrf().'">
                    <input type="hidden" name="action" value="music-unverify">
                    <input type="hidden" name="return" value="songs">
                    <input type="hidden" name="id" value="'.h($r['id']).'">
                    <button class="gray">Hide</button>
                </form>';
            } else {
                echo '<form method="post">
                    <input type="hidden" name="csrf" value="'.csrf().'">
                    <input type="hidden" name="action" value="music-verify">
                    <input type="hidden" name="return" value="songs">
                    <input type="hidden" name="id" value="'.h($r['id']).'">
                    <button class="green">Verify</button>
                </form>';
            }

            echo '<form method="post" onsubmit="return confirm(\'Delete this song?\')">
                <input type="hidden" name="csrf" value="'.csrf().'">
                <input type="hidden" name="action" value="music-delete">
                <input type="hidden" name="return" value="songs">
                <input type="hidden" name="id" value="'.h($r['id']).'">
                <button class="red">Delete</button>
            </form>';

            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</table></div>';
}
}

/* =========================================================
   ANALYTICS
========================================================= */

elseif($page==='analytics') {

$charts=[];

try {
    $charts['Registrations — last 14 days']=$db->query(
        "SELECT DATE(created_at) d,COUNT(*) c
         FROM accounts
         WHERE created_at>=NOW()-INTERVAL 14 DAY
         GROUP BY DATE(created_at)
         ORDER BY d"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable) {}

try {
    $charts['Levels — last 14 days']=$db->query(
        "SELECT DATE(created_at) d,COUNT(*) c
         FROM levels
         WHERE created_at>=NOW()-INTERVAL 14 DAY
         GROUP BY DATE(created_at)
         ORDER BY d"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable) {}

foreach($charts as $title=>$data) {
    echo '<div class="card" style="margin-bottom:14px">';
    echo '<h2>'.h($title).'</h2>';

    $max=1;

    foreach($data as $x) {
        $max=max($max,(int)$x['c']);
    }

    foreach($data as $x) {
        $pct=((int)$x['c']/$max)*100;

        echo '<div style="margin:9px 0">';
        echo '<div class="row">';
        echo '<small>'.h($x['d']).'</small>';
        echo '<b>'.h($x['c']).'</b>';
        echo '</div>';
        echo '<div class="bar"><i style="width:'.
            h(round($pct,2)).
            '%"></i></div>';
        echo '</div>';
    }

    echo '</div>';
}
}

elseif($page==='advanced') {
    require __DIR__.'/advanced-module.php';
}

elseif($page==='monitoring') {
    require __DIR__.'/monitoring-module.php';
}

/* =========================================================
   DATABASE BROWSER
========================================================= */

elseif($page==='database') {

requireRank(40);

$tables=$db->query('SHOW TABLES')
    ->fetchAll(PDO::FETCH_COLUMN);

$selected=(string)($_GET['table'] ?? '');

echo '<div class="row">';

foreach($tables as $t) {
    echo '<a class="btn gray" href="/admin/?page=database&table='.
        rawurlencode($t).
        '">'.h($t).'</a>';
}

echo '</div>';

if (
    $selected!=='' &&
    in_array($selected,$tables,true)
) {
    echo '<h2>'.h($selected).'</h2>';

    $rows=$db->query(
        "SELECT * FROM `$selected`
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '</tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                $v=(string)$v;

                if(strlen($v)>150) {
                    $v=substr($v,0,150).'…';
                }

                echo '<td>'.h($v).'</td>';
            }

            echo '</tr>';
        }
    }

    echo '</table></div>';
}
}

/* =========================================================
   ENDPOINT TESTER
========================================================= */

elseif($page==='endpoints') {

$result=$_SESSION['endpoint_result'] ?? null;
unset($_SESSION['endpoint_result']);

?>
<div class="card">
<form method="post">

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="endpoint-test">
<input type="hidden" name="return" value="endpoints">

<div>
<small>Endpoint</small><br>
<input
 style="width:100%;margin-top:5px"
 name="endpoint"
 value="/getGJLevels21.php"
>
</div>

<div style="margin-top:12px">
<small>POST payload</small>
<textarea name="payload" placeholder="type=0&page=0"></textarea>
</div>

<button>Send locally</button>
</form>

<?php if($result!==null): ?>
<h2>Response</h2>
<pre><?=h($result)?></pre>
<?php endif ?>
</div>
<?php
}

/* =========================================================
   BACKUPS
========================================================= */

elseif($page==='backups') {

?>
<div class="card">
<div class="row">

<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="system-op">
<input type="hidden" name="return" value="backups">
<input type="hidden" name="op" value="backup-code">
<button>Code backups</button>
</form>

<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="system-op">
<input type="hidden" name="return" value="backups">
<input type="hidden" name="op" value="backup-db">
<button>Database backups</button>
</form>

</div>
</div>
<?php

$files=glob(BACKUP_DIR.'/*') ?: [];

usort(
    $files,
    fn($a,$b)=>filemtime($b)<=>filemtime($a)
);

echo '<div class="table"><table>';
echo '<tr><th>File</th><th>Size</th><th>Date</th><th></th></tr>';

foreach($files as $f) {
    echo '<tr>';
    echo '<td>'.h(basename($f)).'</td>';
    echo '<td>'.h(number_format(filesize($f)/1024/1024,2)).' MB</td>';
    echo '<td>'.h(date('Y-m-d H:i:s',filemtime($f))).'</td>';
    echo '<td><a class="btn gray" href="/admin/?download='.
        rawurlencode(basename($f)).
        '">Download</a></td>';
    echo '</tr>';
}

echo '</table></div>';
}

/* =========================================================
   SETTINGS
========================================================= */

elseif($page==='settings') {

$maintenance=is_file(
    CONTROL_DIR.'/maintenance.flag'
);

$regDisabled=is_file(
    CONTROL_DIR.'/registrations-disabled.flag'
);

?>
<div class="card">
<form method="post">

<p>
<strong>Server branding</strong>
</p>

<p class="muted">
This name replaces the visible server logo on the public pages and player music dashboard. MuchoCore attribution stays in the footer.
</p>

<label style="display:block;margin-bottom:7px">Server name</label>
<input
 type="text"
 name="server_name"
 maxlength="64"
 value="<?=h($branding['server_name'])?>"
 placeholder="My GDPS"
 style="width:min(520px,100%)"
>

<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:14px 0 18px">
 <div>
  <label style="display:block;margin-bottom:7px">Server by</label>
  <input
   type="text"
   name="server_by_name"
   maxlength="64"
   value="<?=h($branding['server_by_name'] ?? '')?>"
   placeholder="Your nickname"
   style="width:100%"
  >
 </div>
 <div>
  <label style="display:block;margin-bottom:7px">Social/profile URL</label>
  <input
   type="url"
   name="social_url"
   maxlength="512"
   value="<?=h($branding['social_url'] ?? '')?>"
   placeholder="https://discord.gg/..."
   style="width:100%"
  >
 </div>
</div>

<div class="muted small" style="margin:-6px 0 18px">
Shown in the public footer as <b>Server by</b>. Leave both fields empty to hide the credit.
</div>

<div style="margin:12px 0 18px;padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:linear-gradient(135deg,#111724,#0d1119)">
 <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.08em">Preview</div>
 <div style="margin-top:6px;font-size:24px;font-weight:900;letter-spacing:-.8px">
  <span style="background:linear-gradient(90deg,#fff,#9687ff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent"><?=h($branding['server_name'])?></span>
  <span style="color:#9687ff"> Control</span>
 </div>
</div>

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="settings-save">
<input type="hidden" name="return" value="settings">

<p>
<label>
<input
 type="checkbox"
 name="maintenance"
 <?=$maintenance?'checked':''?>
>
Maintenance mode
</label>
</p>

<p>
<label>
<input
 type="checkbox"
 name="registrations_disabled"
 <?=$regDisabled?'checked':''?>
>
Disable new registrations
</label>
</p>

<button>Apply</button>
</form>
</div>
<?php
}

/* =========================================================
   SYSTEM
========================================================= */

elseif($page==='system') {

$status=rootOp('status');

?>
<div class="card">
<pre><?=h($status)?></pre>
</div>

<div class="card" style="margin-top:13px">
<div class="row">

<?php

$ops=[
'nginx-test'=>'Nginx test',
'nginx-reload'=>'Reload Nginx',
'php-restart'=>'Restart PHP',
'cloudflare-restart'=>'Restart Cloudflare',
'composer'=>'Composer',
'migrate'=>'Migrations',
'error-log'=>'Nginx errors',
'access-log'=>'Nginx access',
'php-log'=>'PHP logs',
'cloudflare-log'=>'Cloudflare logs'
];

foreach($ops as $op=>$label):
?>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="system-op">
<input type="hidden" name="return" value="system">
<input type="hidden" name="op" value="<?=h($op)?>">
<button class="gray"><?=h($label)?></button>
</form>
<?php endforeach ?>

</div>
</div>

<?php

if(isset($_SESSION['system_output'])) {
    echo '<pre>'.h($_SESSION['system_output']).'</pre>';
    unset($_SESSION['system_output']);
}
}

/* =========================================================
   ADMIN USERS + 2FA
========================================================= */

elseif($page==='admins') {

$me=$db->prepare(
    'SELECT * FROM admin_users WHERE id=:id'
);

$me->execute(['id'=>admin()['id']]);
$me=$me->fetch(PDO::FETCH_ASSOC);

?>
<div class="boxgrid">

<div class="card admin-2fa-card">
<style>
.admin-2fa-card{position:relative;overflow:hidden}
.admin-2fa-card .security-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 9px;border-radius:999px;background:#123226;border:1px solid #23583f;color:#78e5ad;font-size:11px;font-weight:800}
.admin-2fa-card .security-dot{width:7px;height:7px;border-radius:50%;background:#42df9e;box-shadow:0 0 0 4px rgba(66,223,158,.12)}
.admin-2fa-card .setup-grid{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:center;margin-top:15px}
.admin-2fa-card .qr-box{display:grid;place-items:center;padding:13px;border-radius:14px;background:#fff;border:1px solid #fff;min-height:220px}
.admin-2fa-card .qr-box #totpQr canvas,.admin-2fa-card .qr-box #totpQr img{max-width:100%;height:auto}
.admin-2fa-card .qr-placeholder{width:100%;min-height:194px;display:grid;place-items:center;text-align:center;color:#667184;font-size:11px;background:#f7f8fb;border-radius:8px}
.admin-2fa-card .secret-box{display:flex;align-items:center;gap:8px;margin:8px 0 11px}
.admin-2fa-card .secret-value{flex:1;min-width:0;font:700 13px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;word-break:break-all;padding:11px 12px;border:1px solid #29364a;border-radius:10px;background:#090d14;color:#dde4f0}
.admin-2fa-card .copy-btn{background:#20293a!important;color:#cbd5e5!important;border:1px solid #303d52!important}
.admin-2fa-card .step{display:flex;gap:10px;margin:9px 0}
.admin-2fa-card .step-no{flex:0 0 24px;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:#272149;color:#b9b0ff;font-size:11px;font-weight:850}
.admin-2fa-card .step-text{font-size:12px;color:#9aa6b9;line-height:1.45}
.admin-2fa-card .step-text b{color:#e4e9f2}
.admin-2fa-card .verify-row{display:flex;gap:8px;margin-top:10px}
.admin-2fa-card .verify-row input{flex:1;min-width:0;text-align:center;letter-spacing:.28em;font-weight:800}
.admin-2fa-card .warning{margin-top:12px;padding:10px 11px;border-radius:10px;background:#241f12;border:1px solid #4a4026;color:#cfc08f;font-size:10px;line-height:1.45}
.admin-2fa-card .disabled-state{margin-top:13px;padding:13px;border-radius:12px;background:#101720;border:1px solid #283346}
.admin-2fa-card .disabled-state b{display:block;margin-bottom:4px}
@media(max-width:640px){.admin-2fa-card .setup-grid{grid-template-columns:1fr}.admin-2fa-card .qr-box{max-width:245px;margin:auto}.admin-2fa-card .verify-row{flex-direction:column}.admin-2fa-card .copy-btn{min-height:40px}}
</style>

<?php
$pendingRaw=$_SESSION['pending_totp'] ?? null;
$pendingSecret=is_array($pendingRaw)
    ? (string)($pendingRaw['secret'] ?? '')
    : (string)($pendingRaw ?? '');
$pendingCreated=is_array($pendingRaw)
    ? (int)($pendingRaw['created_at'] ?? 0)
    : 0;
$pendingValid=
    $pendingSecret!=='' &&
    (
        $pendingCreated<=0 ||
        (time()-$pendingCreated)<=600
    );
$totpIssuer=trim((string)$branding['server_name']) ?: 'MuchoCore';
$totpUri=$pendingValid
    ? totpProvisioningUri(
        $pendingSecret,
        (string)($me['username'] ?? admin()['username']),
        $totpIssuer
    )
    : '';
?>

<div class="row" style="justify-content:space-between;align-items:center">
    <div>
        <h2 style="margin:0">Google Authenticator</h2>
        <small>Time-based one-time passwords (TOTP)</small>
    </div>
    <?php if(!empty($me['totp_secret'])): ?>
        <span class="security-badge"><span class="security-dot"></span> Protected</span>
    <?php else: ?>
        <span class="badge">Not enabled</span>
    <?php endif; ?>
</div>

<?php if(!empty($me['totp_secret'])): ?>

<div class="disabled-state" style="background:#123226;border-color:#23583f">
    <b class="ok">Two-factor authentication is active.</b>
    <small>Sign-in requires a current 6-digit code from your authenticator app.</small>
</div>

<div class="warning">
Keep access to your authenticator device. Disabling 2FA removes the extra sign-in factor from this administrator account.
</div>

<form method="post" style="margin-top:12px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="2fa-disable">
<input type="hidden" name="return" value="admins">
<button class="red">Disable 2FA</button>
</form>

<?php elseif($pendingValid): ?>

<div class="setup-grid">
    <div class="qr-box">
        <div id="totpQr" aria-label="Authenticator QR code"></div>
    </div>

    <div>
        <div class="step">
            <span class="step-no">1</span>
            <div class="step-text"><b>Open Google Authenticator</b><br>Tap <b>+</b> and choose <b>Scan a QR code</b>.</div>
        </div>
        <div class="step">
            <span class="step-no">2</span>
            <div class="step-text"><b>Scan this code</b><br>The setup is local; the secret is not sent to a QR-code service.</div>
        </div>
        <div class="step">
            <span class="step-no">3</span>
            <div class="step-text"><b>Confirm the 6-digit code</b><br>Enter the current code below to activate 2FA.</div>
        </div>

        <div style="margin-top:12px">
            <small>Manual setup key</small>
            <div class="secret-box">
                <div class="secret-value" id="totpSecret"><?=h($pendingSecret)?></div>
                <button type="button" class="copy-btn" id="copyTotpSecret">Copy</button>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="2fa-enable">
            <input type="hidden" name="return" value="admins">
            <div class="verify-row">
                <input
                    name="otp"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="000000"
                    required
                >
                <button>Enable 2FA</button>
            </div>
        </form>

        <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="2fa-cancel">
            <input type="hidden" name="return" value="admins">
            <button type="submit" class="gray">Cancel setup</button>
        </form>
    </div>
</div>

<div class="warning">
This setup key is the recovery credential for your TOTP factor. Store it privately and never post it publicly. The setup QR expires after 10 minutes.
</div>

<script>
(() => {
    const uri = <?=json_encode($totpUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
    const qr = document.getElementById('totpQr');
    if (qr && typeof QRCode !== 'undefined' && uri) {
        new QRCode(qr, {
            text: uri,
            width: 220,
            height: 220,
            colorDark: '#111827',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    document.getElementById('copyTotpSecret')?.addEventListener('click', async () => {
        const secret = document.getElementById('totpSecret')?.textContent?.trim() || '';
        try {
            await navigator.clipboard.writeText(secret);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = secret;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }

        const button = document.getElementById('copyTotpSecret');
        if (button) {
            const original = button.textContent;
            button.textContent = 'Copied';
            setTimeout(() => button.textContent = original, 1200);
        }
    });
})();
</script>

<?php elseif($pendingSecret!=='' && !$pendingValid): ?>

<div class="warning">
The previous authenticator setup expired. Generate a new QR code to continue.
</div>

<form method="post" style="margin-top:10px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="2fa-generate">
<input type="hidden" name="return" value="admins">
<button>Generate new QR code</button>
</form>

<?php else: ?>

<div class="disabled-state">
    <b>Protect this administrator account.</b>
    <small>Use Google Authenticator or another TOTP-compatible authenticator app. The setup flow will show a QR code and a manual key.</small>
</div>

<form method="post" style="margin-top:12px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="2fa-generate">
<input type="hidden" name="return" value="admins">
<button>Set up Google Authenticator</button>
</form>

<?php endif; ?>

</div>

</div>

<?php if(rank(admin()['role'])>=40): ?>

<div class="card">
<h2>Add administrator</h2>

<form method="post">

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="admin-create">
<input type="hidden" name="return" value="admins">

<p><input name="username" placeholder="Username"></p>
<p><input type="password" name="password" placeholder="Password"></p>

<select name="role">
<option>viewer</option>
<option>moderator</option>
<option>admin</option>
<option>owner</option>
</select>

<button>Create</button>
</form>
</div>

<?php endif ?>

</div>
<?php
$newAccessKey=(string)($_SESSION['new_access_key'] ?? '');
unset($_SESSION['new_access_key']);
$hasAccessKey=!empty($me['access_key_hash']);
?>
<div class="card admin-access-key-card" style="margin-top:13px">
<style>
.admin-access-key-card{position:relative;overflow:hidden;background:linear-gradient(145deg,#121827,#0e131c)}
.admin-access-key-card .key-badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;background:#282147;border:1px solid #473b7e;color:#c5baff;font-size:11px;font-weight:800}
.admin-access-key-card .key-panel{margin-top:14px;padding:13px;border:1px solid #29354a;border-radius:12px;background:#0b1018}
.admin-access-key-card .key-value{font:750 13px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.035em;word-break:break-all;color:#eef2ff;margin-top:5px}
.admin-access-key-card .key-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:11px}
.admin-access-key-card .key-note{margin-top:10px;color:#7f8ba0;font-size:10px;line-height:1.5}
</style>
<div class="row" style="justify-content:space-between;align-items:center">
    <div>
        <h2 style="margin:0">Admin Access Key</h2>
        <small>Fast sign-in credential for this administrator</small>
    </div>
    <?php if($hasAccessKey): ?>
        <span class="key-badge">Active</span>
    <?php else: ?>
        <span class="badge">Not set</span>
    <?php endif; ?>
</div>

<?php if($newAccessKey!==''): ?>
<div class="key-panel">
    <small>New access key — copy it now</small>
    <div class="key-value" id="newAccessKey"><?=h($newAccessKey)?></div>
    <div class="key-actions">
        <button type="button" class="copy-btn" id="copyAccessKey">Copy key</button>
    </div>
</div>
<div class="warning">Shown once. Only a hash is stored in the database.</div>
<?php elseif($hasAccessKey): ?>
<div class="disabled-state">
    <b>Fast sign-in is enabled.</b>
    <small>Use your username and access key on the login page. If 2FA is enabled, the authenticator code is still required.</small>
</div>
<div class="key-actions">
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="access-key-generate">
<input type="hidden" name="return" value="admins">
<button>Generate new key</button>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="access-key-revoke">
<input type="hidden" name="return" value="admins">
<button class="red">Revoke key</button>
</form>
</div>
<?php else: ?>
<div class="disabled-state">
    <b>Skip the password for faster sign-in.</b>
    <small>The access key replaces the password and is stored as a one-way hash.</small>
</div>
<div class="key-actions">
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="access-key-generate">
<input type="hidden" name="return" value="admins">
<button>Generate access key</button>
</form>
</div>
<?php endif; ?>

<div class="key-note">Access Key replaces the password, not the second factor.</div>
</div>

<?php

$admins=$db->query(
    'SELECT id,username,role,is_active,
            totp_secret,created_at
     FROM admin_users
     ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>ID</th><th>User</th><th>Role</th><th>2FA</th><th>Active</th><th></th></tr>';

foreach($admins as $a) {
    echo '<tr>';
    echo '<td>'.h($a['id']).'</td>';
    echo '<td>'.h($a['username']).'</td>';
    echo '<td>'.h($a['role']).'</td>';
    echo '<td>'.(!empty($a['totp_secret'])?'YES':'NO').'</td>';
    echo '<td>'.h($a['is_active']).'</td>';

    echo '<td>';

    if(
        rank(admin()['role'])>=40 &&
        (int)$a['id']!==(int)admin()['id']
    ) {
        echo '<form method="post">
        <input type="hidden" name="csrf" value="'.csrf().'">
        <input type="hidden" name="action" value="admin-toggle">
        <input type="hidden" name="return" value="admins">
        <input type="hidden" name="id" value="'.h($a['id']).'">
        <button class="gray">Toggle</button>
        </form>';
    }

    echo '</td></tr>';
}

echo '</table></div>';
}

/* =========================================================
   AUDIT
========================================================= */

elseif($page==='audit') {

$rows=$db->query(
    'SELECT *
     FROM admin_audit_logs
     ORDER BY id DESC
     LIMIT 400'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Metadata</th></tr>';

foreach($rows as $r) {
    echo '<tr>';
    echo '<td>'.h($r['created_at']).'</td>';
    echo '<td>'.h($r['username']).'</td>';
    echo '<td>'.h($r['action']).'</td>';
    echo '<td>'.h($r['target']).'</td>';
    echo '<td>'.h($r['ip']).'</td>';
    echo '<td>'.h($r['metadata']).'</td>';
    echo '</tr>';
}

echo '</table></div>';
}

?>

<div class="mucho-page-footer" style="margin-top:auto;padding:16px 6px 0;border-top:1px solid rgba(36,45,60,.75);color:#69758a;font-size:12px;text-align:center">
 <?=h($branding['server_name'])?>
 <?php if (!empty($branding['server_by_name']) && !empty($branding['social_url'])): ?>
 · Server by <a href="<?=h($branding['social_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($branding['server_by_name'])?></a>
 <?php endif; ?>
 · Powered by MuchoCore · Copyright © <?=date('Y')?> IZK · <a class="github-footer-link" href="https://github.com/IZKGMD" target="_blank" rel="noopener noreferrer" aria-label="GitHub IZKGMD">
<svg class="github-footer-logo" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5a12 12 0 0 0-3.79 23.39c.6.11.82-.26.82-.58v-2.24c-3.34.73-4.04-1.61-4.04-1.61-.55-1.4-1.34-1.77-1.34-1.77-1.09-.75.08-.74.08-.74 1.2.08 1.83 1.23 1.83 1.23 1.07 1.83 2.8 1.3 3.48.99.11-.77.42-1.3.76-1.6-2.67-.3-5.47-1.34-5.47-5.94 0-1.31.47-2.38 1.24-3.22-.12-.3-.54-1.52.12-3.17 0 0 1.01-.32 3.3 1.23a11.5 11.5 0 0 1 6 0c2.29-1.55 3.3-1.23 3.3-1.23.66 1.65.24 2.87.12 3.17.77.84 1.24 1.91 1.24 3.22 0 4.61-2.8 5.63-5.48 5.93.43.37.81 1.1.81 2.22v3.29c0 .32.22.69.83.57A12 12 0 0 0 12 .5"/></svg><span>GitHub</span></a>
</div>

</main>
</div>

<script id="mucho-mobile-v5-js">
(() => {
    const body = document.body;

    const open =
        document.getElementById('mobileMenuButton');

    const overlay =
        document.getElementById('mobileOverlay');

    function closeMenu(){
        body.classList.remove('mobile-menu-open');
    }

    open?.addEventListener('click', () => {
        body.classList.toggle('mobile-menu-open');
    });

    overlay?.addEventListener('click', closeMenu);

    document.querySelectorAll('aside nav a')
        .forEach(a => a.addEventListener('click', closeMenu));

    document.addEventListener('keydown', e => {
        if(e.key === 'Escape'){
            closeMenu();
        }
    });
})();
</script>
<script src="/admin/admin-i18n.js?v=100"></script>


</body>
</html>
