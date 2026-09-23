<?php
declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Security\RateLimiter;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = (new Database())->connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rootDir = dirname(__DIR__);
$musicDir = $rootDir . '/storage/music-public';
$musicMax = 20 * 1024 * 1024;

$secure = (
    ($_SERVER['HTTPS'] ?? '') === 'on' ||
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

session_name('MUCHO_MUSIC');
session_start();

function mdH(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function mdCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf'];
}

function mdRequireCsrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');

    if ($token === '' || !hash_equals(mdCsrf(), $token)) {
        http_response_code(403);
        exit('CSRF rejected');
    }
}

function mdFlash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type,
        ];

        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function mdCurrentIp(): string
{
    return trim(
        (string)(
            $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown'
        )
    );
}

function mdRedirect(): never
{
    header('Location: /music-dashboard.php');
    exit;
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'logout') {
    mdRequireCsrf();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();

    header('Location: /music-dashboard.php');
    exit;
}

if ($action === 'login') {
    mdRequireCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (
        $username === '' ||
        strlen($username) > 64 ||
        $password === '' ||
        strlen($password) > 256
    ) {
        mdFlash('Invalid login data.', 'error');
        mdRedirect();
    }

    $limiter = new RateLimiter();

    if (!$limiter->allow('music-login:' . mdCurrentIp(), 8, 900)) {
        mdFlash('Too many login attempts. Try again later.', 'error');
        mdRedirect();
    }

    $q = $db->prepare(
        'SELECT
            account_id,
            username,
            password_hash,
            is_active,
            is_banned
         FROM accounts
         WHERE username=:username
         LIMIT 1'
    );

    $q->execute([
        'username' => $username,
    ]);

    $account = $q->fetch(PDO::FETCH_ASSOC);

    $valid =
        is_array($account) &&
        (int)$account['is_active'] === 1 &&
        (int)$account['is_banned'] === 0 &&
        password_verify(
            $password,
            (string)$account['password_hash']
        );

    if (!$valid) {
        mdFlash('Invalid username or password.', 'error');
        mdRedirect();
    }

    session_regenerate_id(true);

    $_SESSION['account'] = [
        'id' => (int)$account['account_id'],
        'username' => (string)$account['username'],
    ];

    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    mdFlash('Welcome back, ' . (string)$account['username'] . '.');
    mdRedirect();
}

if ($action === 'upload') {
    mdRequireCsrf();

    $account = $_SESSION['account'] ?? null;

    if (!is_array($account) || (int)($account['id'] ?? 0) <= 0) {
        mdFlash('Please sign in first.', 'error');
        mdRedirect();
    }

    $accountId = (int)$account['id'];
    $username = (string)$account['username'];

    $title = trim((string)($_POST['title'] ?? ''));
    $artist = trim((string)($_POST['artist'] ?? $username));

    if ($title === '' || mb_strlen($title, 'UTF-8') > 128) {
        mdFlash('Song title is required and must be 128 characters or less.', 'error');
        mdRedirect();
    }

    if ($artist === '' || mb_strlen($artist, 'UTF-8') > 128) {
        mdFlash('Artist is required and must be 128 characters or less.', 'error');
        mdRedirect();
    }

    if (
        !isset($_FILES['music_file']) ||
        ($_FILES['music_file']['error'] ?? -1) !== UPLOAD_ERR_OK
    ) {
        mdFlash('MP3 upload failed.', 'error');
        mdRedirect();
    }

    $file = $_FILES['music_file'];
    $size = (int)($file['size'] ?? 0);
    $tmp = (string)($file['tmp_name'] ?? '');

    if ($size <= 0 || $size > $musicMax) {
        mdFlash('MP3 must be between 1 byte and 20 MB.', 'error');
        mdRedirect();
    }

    if (!is_uploaded_file($tmp)) {
        mdFlash('Invalid upload.', 'error');
        mdRedirect();
    }

    $accountLimiter = new RateLimiter();

    if (!$accountLimiter->allow(
        'music-upload-account:' . $accountId,
        5,
        900
    )) {
        mdFlash('Upload limit reached. Please try again later.', 'error');
        mdRedirect();
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

    if (!in_array(
        $mime,
        ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
        true
    )) {
        mdFlash('Only MP3 files are allowed.', 'error');
        mdRedirect();
    }

    if (
        !is_dir($musicDir) &&
        !mkdir($musicDir, 0770, true) &&
        !is_dir($musicDir)
    ) {
        mdFlash('Music storage is unavailable.', 'error');
        mdRedirect();
    }

    $stored = bin2hex(random_bytes(20)) . '.mp3';
    $target = $musicDir . '/' . $stored;

    if (!move_uploaded_file($tmp, $target)) {
        mdFlash('Cannot store the uploaded MP3.', 'error');
        mdRedirect();
    }

    @chmod($target, 0640);

    $baseUrl = rtrim(
        (string)(
            getenv('MUCHO_ACCOUNT_URL')
            ?: (
                'https://' .
                (string)($_SERVER['HTTP_HOST'] ?? 'localhost')
            )
        ),
        '/'
    );

    $downloadUrl = $baseUrl . '/music/' . rawurlencode($stored);

    try {
        $db->beginTransaction();

        $q = $db->prepare(
            'INSERT INTO songs
                (name,author_id,author_name,size,download_url,is_verified)
             VALUES
                (:name,:author_id,:author,:size,:url,0)'
        );

        $q->execute([
            'name' => $title,
            'author_id' => $accountId,
            'author' => $artist,
            'size' => round($size / 1024 / 1024, 2),
            'url' => $downloadUrl,
        ]);

        $songId = (int)$db->lastInsertId();

        $db->commit();

        mdFlash(
            'Music uploaded. Track #' . $songId . ' is waiting for moderation.'
        );
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        @unlink($target);

        error_log('[Mucho Music Dashboard] ' . $e->getMessage());
        mdFlash('Upload failed. Please try again.', 'error');
    }

    mdRedirect();
}

$flash = mdFlash();
$account = $_SESSION['account'] ?? null;
$isLoggedIn = is_array($account) && (int)($account['id'] ?? 0) > 0;
$mySongs = [];

if ($isLoggedIn) {
    $q = $db->prepare(
        'SELECT
            id,
            name,
            author_name,
            size,
            is_verified,
            created_at,
            download_url
         FROM songs
         WHERE author_id=:account_id
         ORDER BY id DESC
         LIMIT 100'
    );

    $q->execute([
        'account_id' => (int)$account['id'],
    ]);

    $mySongs = $q->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Mucho Music Dashboard">
<title>Mucho Music</title>
<style>
:root{
    --bg:#070910;
    --panel:#101520;
    --panel2:#151c28;
    --border:#273043;
    --text:#f3f6ff;
    --muted:#8b97ab;
    --accent:#7966ff;
    --accent2:#9687ff;
    --ok:#3ddc97;
    --bad:#ff687b;
    --shadow:0 20px 60px rgba(0,0,0,.28);
}
*{box-sizing:border-box}
body{
    margin:0;
    min-height:100vh;
    color:var(--text);
    background:
        radial-gradient(circle at 15% -10%,rgba(121,102,255,.18),transparent 35%),
        radial-gradient(circle at 90% 0,rgba(61,220,151,.08),transparent 30%),
        var(--bg);
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
a{color:inherit}
.shell{max-width:1100px;margin:0 auto;padding:28px 18px 60px}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    margin-bottom:24px;
}
.brand{
    font-size:25px;
    font-weight:900;
    letter-spacing:-1px;
}
.brand b{color:var(--accent2)}
.muted{color:var(--muted)}
.card{
    background:linear-gradient(145deg,var(--panel),#0e131c);
    border:1px solid var(--border);
    border-radius:16px;
    padding:20px;
    box-shadow:var(--shadow);
    margin-bottom:16px;
}
h1,h2{margin:0 0 10px}
h1{font-size:30px}
h2{font-size:19px}
p{line-height:1.55}
.row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
input,button{
    font:inherit;
    border-radius:10px;
    border:1px solid var(--border);
}
input{
    width:100%;
    background:#090d14;
    color:var(--text);
    padding:11px 12px;
    outline:0;
}
input:focus{
    border-color:#6255ae;
    box-shadow:0 0 0 3px rgba(121,102,255,.11);
}
button,.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px 14px;
    cursor:pointer;
    color:#fff;
    background:linear-gradient(135deg,var(--accent),#6554df);
    border:0;
    text-decoration:none;
    font-weight:750;
}
button.secondary,.btn.secondary{
    background:var(--panel2);
    border:1px solid var(--border);
}
.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
    gap:16px;
}
.field{display:grid;gap:7px;margin-bottom:12px}
label{font-size:13px;color:#b7c0cf}
.notice{
    padding:11px 13px;
    border-radius:11px;
    margin-bottom:14px;
    border:1px solid var(--border);
    background:var(--panel2);
}
.notice.ok{border-color:rgba(61,220,151,.35)}
.notice.error{border-color:rgba(255,104,123,.35)}
.status{
    display:inline-flex;
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    background:rgba(139,151,171,.13);
    color:#c1c9d6;
}
.status.ok{background:rgba(61,220,151,.12);color:var(--ok)}
.table{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:700px}
th,td{
    text-align:left;
    padding:11px 10px;
    border-bottom:1px solid #202838;
    font-size:13px;
}
th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.small{font-size:12px}
.file{
    padding:12px;
    border:1px dashed #3b4660;
    background:#0a0f17;
}
.center{
    min-height:70vh;
    display:grid;
    place-items:center;
}
.login{width:min(440px,100%)}
.footer{
    margin-top:30px;
    color:var(--muted);
    font-size:12px;
    text-align:center;
}
</style>
</head>
<body>
<div class="shell">

<?php if (!$isLoggedIn): ?>

<div class="center">
<div class="card login">
    <div class="brand">Mucho<b>Core</b></div>
    <p class="muted">Player Music Dashboard</p>

    <?php if ($flash): ?>
        <div class="notice <?=mdH($flash['type'])?>">
            <?=mdH($flash['message'])?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=mdH(mdCsrf())?>">
        <input type="hidden" name="action" value="login">

        <div class="field">
            <label>Geometry Dash username</label>
            <input
                name="username"
                maxlength="64"
                autocomplete="username"
                required
            >
        </div>

        <div class="field">
            <label>Password</label>
            <input
                type="password"
                name="password"
                maxlength="256"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit">Sign in</button>
    </form>
</div>
</div>

<?php else: ?>

<div class="top">
    <div>
        <div class="brand">Mucho<b>Music</b></div>
        <div class="muted">Upload and manage your custom songs.</div>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=mdH(mdCsrf())?>">
        <input type="hidden" name="action" value="logout">
        <button class="secondary" type="submit">Log out</button>
    </form>
</div>

<?php if ($flash): ?>
    <div class="notice <?=mdH($flash['type'])?>">
        <?=mdH($flash['message'])?>
    </div>
<?php endif; ?>

<div class="grid">

<div class="card">
    <h2>Upload Music</h2>
    <p class="muted small">
        MP3 only. Maximum 20 MB. Your upload goes to moderation before it is marked verified.
    </p>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?=mdH(mdCsrf())?>">
        <input type="hidden" name="action" value="upload">

        <div class="field">
            <label>Song title</label>
            <input name="title" maxlength="128" required>
        </div>

        <div class="field">
            <label>Artist</label>
            <input
                name="artist"
                maxlength="128"
                value="<?=mdH($account['username'] ?? '')?>"
                required
            >
        </div>

        <div class="field">
            <label>MP3 file</label>
            <input
                class="file"
                type="file"
                name="music_file"
                accept=".mp3,audio/mpeg"
                required
            >
        </div>

        <button type="submit">Upload MP3</button>
    </form>
</div>

<div class="card">
    <h2>Your account</h2>
    <p><b><?=mdH($account['username'])?></b></p>
    <p class="muted small">
        Uploaded tracks: <?=count($mySongs)?> / 100 shown
    </p>
    <p class="muted small">
        Upload limit: 5 files / 15 minutes.
    </p>
</div>

</div>

<div class="card">
    <h2>Your Music</h2>

    <?php if (!$mySongs): ?>
        <p class="muted">You have not uploaded any music yet.</p>
    <?php else: ?>
    <div class="table">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Artist</th>
            <th>Size</th>
            <th>Status</th>
            <th>Uploaded</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($mySongs as $song): ?>
        <tr>
            <td>#<?=mdH($song['id'])?></td>
            <td><?=mdH($song['name'])?></td>
            <td><?=mdH($song['author_name'])?></td>
            <td><?=mdH($song['size'])?> MB</td>
            <td>
                <?php if ((int)$song['is_verified'] === 1): ?>
                    <span class="status ok">Verified</span>
                <?php else: ?>
                    <span class="status">Pending</span>
                <?php endif; ?>
            </td>
            <td><?=mdH($song['created_at'])?></td>
            <td>
                <a
                    class="btn secondary small"
                    href="<?=mdH($song['download_url'])?>"
                    target="_blank"
                    rel="noopener"
                >Open</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="footer">
    MuchoCore Music Dashboard · <a href="/">Back to GDPS</a>
</div>

<?php endif; ?>

</div>
</body>
</html>
