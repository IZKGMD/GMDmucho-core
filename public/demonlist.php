<?php
declare(strict_types=1);

use MuchoCore\Account\PlayerSession;
use MuchoCore\Database\Database;
use MuchoCore\Demonlist\DemonlistService;

require dirname(__DIR__) . '/vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$db = (new Database())->connection();
$list = new DemonlistService($db);
$account = null;
$notice = '';
$csrf = '';
if ($method === 'POST' || isset($_COOKIE['MUCHO_PLAYER'])) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('MUCHO_PLAYER');
    session_start();
    $account = PlayerSession::resolve($db, $_SESSION['account'] ?? null);
    if ($account !== null) {
        $csrf = $_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
    }
    $notice = $_SESSION['demonlist_notice'] ?? '';
    unset($_SESSION['demonlist_notice']);
    if ($method !== 'POST') {
        session_write_close();
    }
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if ($method === 'POST') {
    $token = $_POST['csrf'] ?? null;
    if ($account === null || !is_string($token) || !hash_equals($csrf, $token)) {
        http_response_code(403);
        exit('Sign in again from the player dashboard.');
    }
    $target = filter_var($_POST['level_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    $proof = is_string($_POST['proof'] ?? null) ? $_POST['proof'] : '';
    try {
        $_SESSION['demonlist_notice'] = $list->submit($account['id'], $target, $proof)
            ? 'Your record was submitted for review.'
            : 'This level is unavailable, or you already submitted a record for it.';
    } catch (InvalidArgumentException $e) {
        $_SESSION['demonlist_notice'] = $e->getMessage();
    }
    header('Location: /demonlist.php' . ($target > 0 ? '?level=' . $target : ''), true, 303);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}

$levels = $list->levels();
$selectedId = (int)(filter_var($_GET['level'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0);
$selected = null;
foreach ($levels as $level) {
    if ((int)$level['level_id'] === $selectedId) {
        $selected = $level;
        break;
    }
}
if ($selectedId > 0 && $selected === null) {
    http_response_code(404);
}
$records = $selected ? $list->records($selectedId) : [];
$recordStatus = $selected && $account ? $list->recordStatus($account['id'], $selectedId) : null;
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $selected ? $h($selected['name']) . ' · ' : '' ?>Demonlist</title>
<link rel="stylesheet" href="/assets/demonlist.css">
</head>
<body>
<main>
<nav><a href="/dashboard">Player dashboard</a><?php if ($selected): ?><a href="/demonlist.php">All Demons</a><?php endif ?></nav>
<h1><?= $selected ? $h($selected['name']) : 'Demonlist' ?></h1>
<?php if ($notice !== ''): ?><p role="status"><?= $h($notice) ?></p><?php endif ?>
<?php if ($selectedId > 0 && !$selected): ?>
<p>This level is unavailable.</p>
<?php elseif ($selected): ?>
<p>By <?= $h($selected['creator'] ?? 'Unknown') ?> · Level ID <?= $selectedId ?></p>
<h2>Verified records</h2>
<?php if (!$records): ?><p>No verified records yet.</p><?php endif ?>
<ul class="entries">
<?php foreach ($records as $record): ?>
<li><strong><?= $h($record['username']) ?></strong><span>100%</span><a href="https://www.youtube.com/watch?v=<?= $h($record['video_id']) ?>">Watch run</a></li>
<?php endforeach ?>
</ul>
<?php if ($recordStatus === 'pending'): ?>
<p>Your record is awaiting review.</p>
<?php elseif ($recordStatus === 'approved'): ?>
<p>Your record was approved.</p>
<?php elseif ($account): ?>
<?php if ($recordStatus === 'rejected'): ?><p>Your last record was rejected. You can submit another recording.</p><?php endif ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
<input type="hidden" name="level_id" value="<?= $selectedId ?>">
<label for="proof">YouTube link to your 100% run</label>
<div class="submit"><input id="proof" name="proof" type="url" maxlength="512" required><button type="submit">Submit record</button></div>
</form>
<?php else: ?>
<p><a href="/dashboard">Sign in to submit a record</a></p>
<?php endif ?>
<?php else: ?>
<?php if (!$levels): ?><p>No Demons have been published yet.</p><?php endif ?>
<ol class="entries">
<?php foreach ($levels as $level): ?>
<li><span class="position">#<?= (int)$level['position'] ?></span><a href="?level=<?= (int)$level['level_id'] ?>"><?= $h($level['name']) ?></a><span>By <?= $h($level['creator'] ?? 'Unknown') ?></span></li>
<?php endforeach ?>
</ol>
<?php endif ?>
</main>
</body>
</html>
