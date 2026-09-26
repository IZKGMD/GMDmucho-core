<?php
declare(strict_types=1);

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Branding\BrandingService;
use MuchoCore\Clan\ClanRepository;
use MuchoCore\Database\Database;
use MuchoCore\Security\RateLimiter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = (new Database())->connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$branding = new BrandingService($db);
$brandingData = $branding->get();
$serverName = $brandingData['server_name'];
$serverByName = $brandingData['server_by_name'];
$socialUrl = $brandingData['social_url'];

ini_set('session.use_strict_mode', '1');
$secure = (
    ($_SERVER['HTTPS'] ?? '') === 'on' ||
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', '604800');
ini_set('session.gc_maxlifetime', '604800');
session_name('MUCHO_PLAYER');
session_start();

function pcH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pcCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf'];
}

function pcRequireCsrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');

    if ($token === '' || !hash_equals(pcCsrf(), $token)) {
        http_response_code(403);
        exit('CSRF rejected');
    }
}

function pcFlash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['clan_flash'] = [
            'message' => $message,
            'type' => $type,
        ];
        return null;
    }

    $flash = $_SESSION['clan_flash'] ?? null;
    unset($_SESSION['clan_flash']);

    return is_array($flash) ? $flash : null;
}

function pcRedirect(): never
{
    header('Location: /dashboard/clans.php');
    exit;
}

function pcAccount(): ?array
{
    $account = $_SESSION['account'] ?? null;

    return is_array($account) && (int)($account['id'] ?? 0) > 0
        ? $account
        : null;
}

function pcAudit(PDO $db, int $accountId, string $action, ?int $targetId = null, array $metadata = []): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO audit_logs
                (account_id, action, target_type, target_id, metadata)
             VALUES
                (:account_id, :action, :target_type, :target_id, :metadata)'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'action' => $action,
            'target_type' => 'clan',
            'target_id' => $targetId,
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);
    } catch (Throwable) {
        // Audit failures must not break clan operations.
    }
}

function pcResolveAccountId(PDO $db, string $username): int
{
    $username = trim($username);

    if ($username === '' || strlen($username) > 20) {
        return 0;
    }

    $stmt = $db->prepare(
        'SELECT account_id
         FROM accounts
         WHERE username=:username
           AND is_active=1
           AND is_banned=0
         LIMIT 1'
    );
    $stmt->execute(['username' => $username]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function pcUsableAccount(PDO $db, int $accountId): bool
{
    $q = $db->prepare(
        'SELECT is_active, is_banned
         FROM accounts
         WHERE account_id = :id
         LIMIT 1'
    );
    $q->execute(['id' => $accountId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    return is_array($row)
        && (int)$row['is_active'] === 1
        && (int)$row['is_banned'] === 0;
}

function pcClan(PDO $db, int $clanId): ?array
{
    $repo = new ClanRepository($db);
    $clan = $repo->getById($clanId);

    if ($clan === null) {
        return null;
    }

    $clan['members'] = $repo->members($clanId);

    return $clan;
}

$account = pcAccount();

if ($account && !pcUsableAccount($db, (int)$account['id'])) {
    $_SESSION = [];
    session_destroy();
    $account = null;
}

$repo = new ClanRepository($db);
$flash = null;
$action = (string)($_POST['action'] ?? '');

if ($action !== '') {
    pcRequireCsrf();

    if (!$account) {
        pcFlash('Sign in through the player dashboard to manage clans.', 'error');
        pcRedirect();
    }

    $accountId = (int)$account['id'];

    if (!pcUsableAccount($db, $accountId)) {
        pcFlash('This account cannot manage clans.', 'error');
        pcRedirect();
    }

    try {
        $myClan = $repo->getForAccount($accountId);

        if ($action === 'create') {
            if (!(new RateLimiter())->allow('clan-create:' . $accountId, 3, 3600)) {
                throw new RuntimeException('Clan creation limit reached. Try again later.');
            }

            if ($myClan !== null) {
                throw new RuntimeException('You are already in a clan.');
            }

            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['clanName'] ?? '')) ?? '');
            $tag = strtoupper(trim((string)($_POST['clanTag'] ?? '')));
            $description = substr(
                trim(preg_replace('/\s+/', ' ', (string)($_POST['clanDescription'] ?? '')) ?? ''),
                0,
                160
            );
            $open = ((int)($_POST['clanOpen'] ?? 1)) === 1;
            $maxMembers = max(2, min(500, (int)($_POST['clanMaxMembers'] ?? 50)));

            if (
                strlen($name) > 24 ||
                preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{1,23}$/D', $name) !== 1 ||
                strlen($tag) < 2 ||
                strlen($tag) > 6 ||
                preg_match('/^[A-Z0-9]{2,6}$/D', $tag) !== 1
            ) {
                throw new RuntimeException('Use a valid clan name and a 2–6 character tag.');
            }

            $created = $repo->create(
                $accountId,
                $name,
                $tag,
                $description,
                $open,
                $maxMembers
            );

            pcFlash('Clan "' . $created['name'] . '" created.');
            pcRedirect();
        }

        if ($action === 'join') {
            $clanId = (int)($_POST['clanID'] ?? 0);

            if ($myClan !== null) {
                throw new RuntimeException('You are already in a clan.');
            }

            if (!(new RateLimiter())->allow('clan-join:' . $accountId, 10, 600)) {
                throw new RuntimeException('Too many join attempts. Try again later.');
            }

            $clan = $repo->getById($clanId);

            if ($clan === null || (int)$clan['is_open'] !== 1) {
                throw new RuntimeException('This clan is not open for direct joining.');
            }

            if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
                throw new RuntimeException('This clan is full.');
            }

            $repo->join($clanId, $accountId);
            pcFlash('Joined ' . $clan['name'] . '.');
            pcRedirect();
        }

        if ($action === 'leave') {
            if ($myClan === null) {
                throw new RuntimeException('You are not in a clan.');
            }

            if ((string)$myClan['role'] === 'owner') {
                throw new RuntimeException('The owner must transfer ownership before leaving.');
            }

            $repo->removeMember((int)$myClan['clan_id'], $accountId);
            pcFlash('You left ' . $myClan['name'] . '.');
            pcRedirect();
        }

        if ($action === 'invite') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $targetUsername = trim((string)($_POST['targetUsername'] ?? ''));

            if ($targetUsername === '' || strlen($targetUsername) > 20) {
                throw new RuntimeException('Enter a valid Geometry Dash username.');
            }

            $q = $db->prepare(
                'SELECT account_id, is_active, is_banned
                 FROM accounts
                 WHERE username = :username
                 LIMIT 1'
            );
            $q->execute(['username' => $targetUsername]);
            $target = $q->fetch(PDO::FETCH_ASSOC);

            if (
                !is_array($target) ||
                (int)$target['is_active'] !== 1 ||
                (int)$target['is_banned'] !== 0
            ) {
                throw new RuntimeException('Player not found.');
            }

            $targetId = (int)$target['account_id'];

            if ($targetId === $accountId) {
                throw new RuntimeException('You cannot invite yourself.');
            }

            if ($repo->getForAccount($targetId) !== null) {
                throw new RuntimeException('That player is already in a clan.');
            }

            if ((int)$myClan['member_count'] >= (int)$myClan['max_members']) {
                throw new RuntimeException('Your clan is full.');
            }

            $repo->invite(
                (int)$myClan['clan_id'],
                $targetId,
                $accountId
            );

            pcFlash('Invitation sent to ' . $targetUsername . '.');
            pcRedirect();
        }

        if ($action === 'accept') {
            $inviteId = (int)($_POST['inviteID'] ?? 0);

            if ($myClan !== null) {
                throw new RuntimeException('You are already in a clan.');
            }

            if (!$repo->acceptInvite($inviteId, $accountId)) {
                throw new RuntimeException('Invitation not found or expired.');
            }

            pcFlash('Invitation accepted.');
            pcAudit($db, $accountId, 'clan.invite.accepted', $inviteId);
            pcRedirect();
        }

        if ($action === 'decline') {
            $inviteId = (int)($_POST['inviteID'] ?? 0);
            $repo->deleteInvite($inviteId, $accountId);
            pcFlash('Invitation declined.');
            pcRedirect();
        }

        if ($action === 'kick') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $targetId = (int)($_POST['targetAccountID'] ?? 0);
            $target = $repo->getForAccount($targetId);

            if (
                $target === null ||
                (int)$target['clan_id'] !== (int)$myClan['clan_id']
            ) {
                throw new RuntimeException('Target is not in your clan.');
            }

            if ((string)$target['role'] === 'owner') {
                throw new RuntimeException('The clan owner cannot be kicked.');
            }

            if (
                (string)$myClan['role'] === 'officer' &&
                (string)$target['role'] !== 'member'
            ) {
                throw new RuntimeException('Officers cannot remove other officers.');
            }

            $repo->removeMember((int)$myClan['clan_id'], $targetId);
            pcFlash('Member removed.');
            pcRedirect();
        }

        if ($action === 'role') {
            if ($myClan === null || (string)$myClan['role'] !== 'owner') {
                throw new RuntimeException('Owner permission required.');
            }

            $targetId = (int)($_POST['targetAccountID'] ?? 0);
            $role = (string)($_POST['role'] ?? '');
            $target = $repo->getForAccount($targetId);

            if (
                !in_array($role, ['officer', 'member'], true) ||
                $target === null ||
                (int)$target['clan_id'] !== (int)$myClan['clan_id'] ||
                (string)$target['role'] === 'owner'
            ) {
                throw new RuntimeException('Invalid role change.');
            }

            $repo->setRole((int)$myClan['clan_id'], $targetId, $role);
            pcFlash('Member role updated.');
            pcRedirect();
        }

        if ($action === 'settings') {
            if ($myClan === null || (string)$myClan['role'] !== 'owner') {
                throw new RuntimeException('Owner permission required.');
            }

            $clanId = (int)$myClan['clan_id'];
            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['clanName'] ?? '')) ?? '');
            $tag = strtoupper(trim((string)($_POST['clanTag'] ?? '')));
            $description = substr(
                trim(preg_replace('/\s+/', ' ', (string)($_POST['clanDescription'] ?? '')) ?? ''),
                0,
                160
            );
            $open = ((int)($_POST['clanOpen'] ?? 1)) === 1;
            $maxMembers = max(2, min(500, (int)($_POST['clanMaxMembers'] ?? 50)));

            if (
                strlen($name) > 24 ||
                preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{1,23}$/D', $name) !== 1 ||
                preg_match('/^[A-Z0-9]{2,6}$/D', $tag) !== 1
            ) {
                throw new RuntimeException('Use a valid clan name and a 2–6 character tag.');
            }

            $repo->updateSettings(
                $clanId,
                $accountId,
                $name,
                $tag,
                $description,
                $open,
                $maxMembers
            );
            pcFlash('Clan settings updated.');
            pcAudit($db, $accountId, 'clan.settings.updated', $clanId, [
                'is_open' => $open,
                'max_members' => $maxMembers,
            ]);
            pcRedirect();
        }

        if ($action === 'transfer') {
            if ($myClan === null || (string)$myClan['role'] !== 'owner') {
                throw new RuntimeException('Owner permission required.');
            }

            $targetId = (int)($_POST['targetAccountID'] ?? 0);
            $repo->transferOwnership(
                (int)$myClan['clan_id'],
                $accountId,
                $targetId
            );
            pcFlash('Clan ownership transferred.');
            pcAudit($db, $accountId, 'clan.ownership.transferred', (int)$myClan['clan_id'], [
                'new_owner_account_id' => $targetId,
            ]);
            pcRedirect();
        }

        if ($action === 'disband') {
            if ($myClan === null || (string)$myClan['role'] !== 'owner') {
                throw new RuntimeException('Owner permission required.');
            }

            $clanId = (int)$myClan['clan_id'];
            $repo->disband($clanId, $accountId);
            pcFlash('Clan disbanded.');
            pcAudit($db, $accountId, 'clan.disbanded', $clanId);
            pcRedirect();
        }

        if ($action === 'revoke_invite') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $inviteId = (int)($_POST['inviteID'] ?? 0);
            $repo->revokeInvite($inviteId, $accountId);
            pcFlash('Invitation revoked.');
            pcAudit($db, $accountId, 'clan.invite.revoked', (int)$myClan['clan_id'], [
                'invite_id' => $inviteId,
            ]);
            pcRedirect();
        }

        if ($action === 'ban') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $targetId = (int)($_POST['targetAccountID'] ?? 0);
            $targetUsername = trim((string)($_POST['targetUsername'] ?? ''));

            if ($targetId <= 0 && $targetUsername !== '') {
                $targetId = pcResolveAccountId($db, $targetUsername);
            }

            if ($targetId <= 0) {
                throw new RuntimeException('Enter a valid player username or account ID.');
            }

            $reason = trim((string)($_POST['reason'] ?? ''));
            $repo->ban(
                (int)$myClan['clan_id'],
                $accountId,
                $targetId,
                substr($reason, 0, 160)
            );
            pcFlash('Player banned from the clan.');
            pcAudit($db, $accountId, 'clan.member.banned', (int)$myClan['clan_id'], [
                'target_account_id' => $targetId,
            ]);
            pcRedirect();
        }

        if ($action === 'unban') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $targetId = (int)($_POST['targetAccountID'] ?? 0);
            $repo->unban(
                (int)$myClan['clan_id'],
                $accountId,
                $targetId
            );
            pcFlash('Clan ban removed.');
            pcAudit($db, $accountId, 'clan.member.unbanned', (int)$myClan['clan_id'], [
                'target_account_id' => $targetId,
            ]);
            pcRedirect();
        }

        throw new RuntimeException('Unknown clan action.');
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            pcFlash('That clan name, tag or membership already exists.', 'error');
        } else {
            error_log('[MuchoCore][Dashboard][Clans] ' . $e->getMessage());
            pcFlash('The clan operation failed.', 'error');
        }

        pcRedirect();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        pcFlash($e->getMessage(), 'error');
        pcRedirect();
    }
}

$flash = pcFlash();
$search = trim((string)($_GET['q'] ?? ''));
$selectedId = (int)($_GET['clan'] ?? 0);
$selected = $selectedId > 0 ? pcClan($db, $selectedId) : null;
$clans = $repo->search($search, 0, 60);
$myClan = $account ? $repo->getForAccount((int)$account['id']) : null;
$myClanMembers = ($myClan !== null)
    ? $repo->members((int)$myClan['clan_id'])
    : [];
$myClanBans = (
    $myClan !== null &&
    in_array((string)$myClan['role'], ['owner', 'officer'], true)
)
    ? $repo->bans((int)$myClan['clan_id'])
    : [];
$myClanInvites = (
    $myClan !== null &&
    in_array((string)$myClan['role'], ['owner', 'officer'], true)
)
    ? $repo->clanInvitations((int)$myClan['clan_id'])
    : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#090b10">
<title><?=pcH($serverName)?> · Clans</title>
<link rel="stylesheet" href="/muchocore-theme.css?v=3">
<script src="/muchocore-theme.js?v=3" defer></script>
<style>
body{margin:0;background:#07090f;color:#f4f7ff;font-family:Inter,ui-sans-serif,system-ui,sans-serif}
.shell{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:18px 0 44px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #263246;border-radius:18px;background:rgba(8,12,19,.86);backdrop-filter:blur(20px)}
.brand{display:flex;align-items:center;gap:10px}.logo{width:46px;height:46px;object-fit:contain;border-radius:12px}.brand b{display:block;font-size:13px}.brand small{display:block;color:#708098;font-size:9px;font-weight:800;letter-spacing:.12em}
.nav{display:flex;gap:7px;flex-wrap:wrap}.nav a,.nav button{border:1px solid #263246;background:#101724;color:#cbd3e2;padding:9px 11px;border-radius:10px;font-size:11px;font-weight:800;text-decoration:none}
.hero{display:grid;grid-template-columns:1.1fr .9fr;gap:12px;margin-top:14px}.panel{padding:20px;border:1px solid #263246;border-radius:18px;background:linear-gradient(160deg,#111824,#0b1019);box-shadow:0 18px 55px rgba(0,0,0,.28)}
.eyebrow{color:#a89dff;font-size:9px;font-weight:850;letter-spacing:.12em;text-transform:uppercase}.hero h1{margin:6px 0 10px;font-size:clamp(34px,5vw,50px);line-height:.98;letter-spacing:-1.8px}.hero h1 span{color:#a89dff}.copy{color:#8e9bb0;font-size:12px;line-height:1.65}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 13px;border-radius:10px;border:1px solid transparent;background:#7968ff;color:#fff;font-size:11px;font-weight:850;text-decoration:none;cursor:pointer}.btn.alt{background:#111a27;border-color:#263246;color:#d8deea}
.search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;margin:14px 0}.search input,.form input,.form textarea,.form select{width:100%;border:1px solid #28364a;background:#080e16;color:#fff;border-radius:10px;padding:10px 11px;box-sizing:border-box}.form textarea{min-height:78px;resize:vertical}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.card{padding:15px;border:1px solid #263246;border-radius:14px;background:#0e1622;text-decoration:none}.card:hover{border-color:#43516d}.tag{color:#d9d4ff;font-size:12px;font-weight:950;letter-spacing:.08em}.name{margin-top:5px;color:#fff;font-size:16px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sub{margin-top:4px;color:#77879e;font-size:10px;line-height:1.5}.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.chip{padding:4px 7px;border:1px solid #26364d;border-radius:999px;background:#121e2d;color:#b8c5d8;font-size:9px;font-weight:800}
.notice{margin-top:14px;padding:12px 14px;border:1px solid #26354b;border-radius:12px;background:#101a28;font-size:12px}.notice.error{border-color:rgba(255,111,131,.32)}
.section{margin-top:18px}.section-head{display:flex;justify-content:space-between;gap:10px;align-items:end;margin-bottom:10px}.section-head h2{margin:0;font-size:18px}.muted{color:#8e9bb0;font-size:10px}
.member{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 11px;border:1px solid #243146;border-radius:11px;background:#0e1622}.member+.member{margin-top:7px}.member b{display:block;font-size:12px}.member small{display:block;color:#7788a0;margin-top:2px;font-size:9px}
.form{display:grid;gap:9px}.field{display:grid;gap:5px}.field label{color:#9cabc0;font-size:10px;font-weight:800}
.empty{padding:22px;text-align:center;border:1px dashed #2e3c51;border-radius:13px;color:#72829a}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-top:12px}
.kpi{padding:13px 14px;border:1px solid #263246;border-radius:13px;background:#0e1622}
.kpi b{display:block;font-size:20px;line-height:1}
.kpi small{display:block;color:#718199;font-size:9px;text-transform:uppercase;letter-spacing:.08em;margin-top:6px;font-weight:800}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.role-owner{color:#ffe5a8}.role-officer{color:#d8d0ff}.role-member{color:#9eb1c7}
.danger{border-color:rgba(255,111,131,.35)!important;background:#1b1116!important;color:#ffb6c0!important}
.helper{font-size:9px;color:#718199;line-height:1.5}
.footer{margin-top:28px;text-align:center;color:#6f7f97;font-size:11px}
@media(max-width:850px){.hero{grid-template-columns:1fr}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.shell{width:min(100% - 18px,1180px)}.topbar{align-items:stretch;flex-direction:column}.nav{overflow-x:auto;flex-wrap:nowrap}.grid{grid-template-columns:1fr}.search{grid-template-columns:1fr}.panel{padding:17px}}
</style>
</head>
<body>
<div class="shell">
<header class="topbar">
    <a class="brand" href="/dashboard">
        <img class="logo" src="/assets/muchocore-dashboard-logo.jpg?v=1" alt="" width="46" height="46">
        <span>
            <b><?=pcH($serverName)?></b>
            <small>MUCHOCORE PLAYER DASHBOARD</small>
        </span>
    </a>
    <nav class="nav">
        <a href="/dashboard">Dashboard</a>
        <a href="/dashboard/clans.php">Clans</a>
        <a href="/dashboard#players">Players</a>
        <?php if ($account): ?>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit">Log out</button>
        </form>
        <?php endif; ?>
    </nav>
</header>

<?php if ($flash): ?>
<div class="notice <?=pcH($flash['type'])?>"><?=pcH($flash['message'])?></div>
<?php endif; ?>

<section class="hero" id="clans">
    <div class="panel">
        <div class="eyebrow">Community · Clans</div>
        <h1>Build your <span>clan</span>.</h1>
        <p class="copy">
            Every MuchoCore GDPS gets its own clan directory. Players can browse clans,
            open a clan profile, join open clans, and see the clan tag used by the game.
        </p>
        <div class="actions">
            <a class="btn" href="#directory">Browse clans</a>
            <?php if ($account && $myClan): ?>
            <a class="btn alt" href="#my-clan-overview">My clan</a>
            <?php else: ?>
            <a class="btn alt" href="#create">Create clan</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel" id="create">
        <?php if (!$account): ?>
            <div class="eyebrow">Account access</div>
            <h2>Sign in from the dashboard</h2>
            <p class="copy">Use the normal Geometry Dash account session. Your password is never stored by the clan page.</p>
            <a class="btn" href="/dashboard#upload">Sign in</a>
        <?php elseif ($myClan): ?>
            <div class="eyebrow">Your clan</div>
            <h2 style="margin:6px 0"><?=pcH($myClan['name'])?></h2>
            <div class="tag">[<?=pcH($myClan['tag'])?>]</div>
            <p class="copy"><?=pcH($myClan['description'] ?? '')?></p>
            <div class="chips">
                <span class="chip"><?=pcH($myClan['member_count'])?> / <?=pcH($myClan['max_members'])?> members</span>
                <span class="chip"><?=((int)$myClan['is_open'] === 1) ? 'Open' : 'Invite only'?></span>
                <span class="chip"><?=pcH($myClan['role'])?></span>
            </div>
            <div class="actions">
                <a class="btn" href="/dashboard/clans.php?clan=<?=((int)$myClan['clan_id'])?>">Open clan</a>
                <?php if ((string)$myClan['role'] !== 'owner'): ?>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="leave">
                    <button class="btn alt" type="submit" onclick="return confirm('Leave this clan?');">Leave clan</button>
                </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="eyebrow">Create a clan</div>
            <h2 style="margin:6px 0">Start your community</h2>
            <p class="copy">The account username stays unchanged. The tag is only a protocol display prefix.</p>
            <form class="form" method="post">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="create">
                <div class="field">
                    <label>Clan name</label>
                    <input type="text" name="clanName" maxlength="24" required>
                </div>
                <div class="field">
                    <label>Tag</label>
                    <input type="text" name="clanTag" maxlength="6" placeholder="MUCHO" required>
                </div>
                <div class="field">
                    <label>Description</label>
                    <textarea name="clanDescription" maxlength="160"></textarea>
                </div>
                <div class="field">
                    <label>Access</label>
                    <select name="clanOpen">
                        <option value="1">Open — anyone can join</option>
                        <option value="0">Invite only</option>
                    </select>
                </div>
                <div class="field">
                    <label>Member limit</label>
                    <input type="number" name="clanMaxMembers" min="2" max="500" value="50">
                </div>
                <button class="btn" type="submit">Create clan</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($account && $myClan): ?>
<section class="section" id="my-clan-overview">
    <div class="panel">
        <div class="toolbar">
            <div>
                <div class="eyebrow">Your clan</div>
                <h2 style="margin:5px 0 0">[<?=pcH($myClan['tag'])?>] <?=pcH($myClan['name'])?></h2>
            </div>
            <span class="chip"><?=pcH(ucfirst((string)$myClan['role']))?></span>
        </div>
        <div class="kpi-grid">
            <div class="kpi"><b><?=pcH($myClan['member_count'])?></b><small>Members</small></div>
            <div class="kpi"><b><?=pcH($myClan['max_members'])?></b><small>Member limit</small></div>
            <div class="kpi"><b><?=((int)$myClan['is_open'] === 1) ? 'Open' : 'Private'?></b><small>Access</small></div>
            <div class="kpi"><b><?=pcH(count($myClanBans))?></b><small>Active bans</small></div>
        </div>
        <div class="actions">
            <a class="btn" href="/dashboard/clans.php?clan=<?=((int)$myClan['clan_id'])?>">Open my clan</a>
            <?php if ((string)$myClan['role'] !== 'owner'): ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="leave">
                <button class="btn alt" type="submit" onclick="return confirm('Leave this clan?');">Leave clan</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section" id="directory">
    <div class="section-head">
        <h2>Clan directory</h2>
        <span class="muted"><?=count($clans)?> shown</span>
    </div>
    <form class="search" method="get">
        <input type="text" name="q" maxlength="32" value="<?=pcH($search)?>" placeholder="Search by clan name or tag..." autocomplete="off">
        <button class="btn" type="submit">Search</button>
    </form>

    <?php if (!$clans): ?>
        <div class="empty">No clans found yet.</div>
    <?php else: ?>
        <div class="grid">
        <?php foreach ($clans as $clan): ?>
            <a class="card" href="/dashboard/clans.php?clan=<?=((int)$clan['clan_id'])?>">
                <div class="tag">[<?=pcH($clan['tag'])?>]</div>
                <div class="name"><?=pcH($clan['name'])?></div>
                <div class="sub">Owner: <?=pcH($clan['owner_username'])?></div>
                <div class="chips">
                    <span class="chip"><?=pcH($clan['member_count'])?> / <?=pcH($clan['max_members'])?></span>
                    <span class="chip"><?=((int)$clan['is_open'] === 1) ? 'Open' : 'Invite only'?></span>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($selected): ?>
<section class="section">
    <div class="section-head">
        <h2>[<?=pcH($selected['tag'])?>] <?=pcH($selected['name'])?></h2>
        <a class="btn alt" href="/dashboard/clans.php">All clans</a>
    </div>
    <div class="panel">
        <div class="eyebrow">Clan #<?=pcH($selected['clan_id'])?></div>
        <p class="copy"><?=pcH($selected['description'] ?? '')?></p>
        <div class="chips">
            <span class="chip"><?=pcH($selected['member_count'])?> / <?=pcH($selected['max_members'])?> members</span>
            <span class="chip">Owner: <?=pcH($selected['owner_username'])?></span>
            <span class="chip"><?=((int)$selected['is_open'] === 1) ? 'Open' : 'Invite only'?></span>
        </div>

        <?php if ($account && !$myClan && (int)$selected['is_open'] === 1): ?>
        <form method="post" class="actions">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="join">
            <input type="hidden" name="clanID" value="<?=((int)$selected['clan_id'])?>">
            <button class="btn" type="submit">Join clan</button>
        </form>
        <?php elseif ($account && !$myClan): ?>
        <div class="notice">This clan is invite only.</div>
        <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:10px">
        <div class="section-head">
            <h2>Members</h2>
            <span class="muted"><?=count($selected['members'])?> members</span>
        </div>
        <?php
        $selectedViewerRole = null;

        if (
            $account &&
            $myClan &&
            (int)$myClan['clan_id'] === (int)$selected['clan_id']
        ) {
            $selectedViewerRole = (string)$myClan['role'];
        }
        ?>

        <?php foreach ($selected['members'] as $member): ?>
        <?php
        $canKick =
            in_array($selectedViewerRole, ['owner', 'officer'], true) &&
            (string)$member['role'] !== 'owner' &&
            !(
                $selectedViewerRole === 'officer' &&
                (string)$member['role'] !== 'member'
            );

        $canChangeRole =
            $selectedViewerRole === 'owner' &&
            (string)$member['role'] !== 'owner';

        $nextRole = (string)$member['role'] === 'officer'
            ? 'member'
            : 'officer';
        ?>
        <div class="member">
            <span>
                <b><a href="/dashboard?u=<?=rawurlencode((string)$member['username'])?>" style="color:inherit;text-decoration:none"><?=pcH($member['username'])?></a></b>
                <small>Account #<?=pcH($member['account_id'])?> · <span class="role-<?=pcH((string)$member['role'])?>"><?=pcH($member['role'])?></span></small>
            </span>
            <?php if ($canKick || $canChangeRole): ?>
            <span>
                <?php if ($canKick): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="kick">
                    <input type="hidden" name="targetAccountID" value="<?=((int)$member['account_id'])?>">
                    <button class="btn alt" type="submit">Kick</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="ban">
                    <input type="hidden" name="targetAccountID" value="<?=((int)$member['account_id'])?>">
                    <button class="btn alt danger" type="submit" onclick="return confirm('Ban this player from the clan?');">Ban</button>
                </form>
                <?php endif; ?>

                <?php if ($canChangeRole): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="role">
                    <input type="hidden" name="targetAccountID" value="<?=((int)$member['account_id'])?>">
                    <input type="hidden" name="role" value="<?=pcH($nextRole)?>">
                    <button class="btn alt" type="submit">
                        <?=($nextRole === 'officer') ? 'Promote' : 'Demote'?>
                    </button>
                </form>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($account && $myClan): ?>
<?php if ((string)$myClan['role'] === 'owner'): ?>
<section class="section">
    <div class="panel">
        <div class="section-head">
            <h2>Clan settings</h2>
            <span class="muted">Owner only</span>
        </div>
        <form class="form" method="post">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="settings">
            <div class="field"><label>Clan name</label><input type="text" name="clanName" maxlength="24" value="<?=pcH($myClan['name'])?>" required></div>
            <div class="field"><label>Tag</label><input type="text" name="clanTag" maxlength="6" value="<?=pcH($myClan['tag'])?>" required></div>
            <div class="field"><label>Description</label><textarea name="clanDescription" maxlength="160"><?=pcH($myClan['description'] ?? '')?></textarea></div>
            <div class="field"><label>Access</label>
                <select name="clanOpen">
                    <option value="1" <?=((int)$myClan['is_open'] === 1) ? 'selected' : ''?>>Open — anyone can join</option>
                    <option value="0" <?=((int)$myClan['is_open'] !== 1) ? 'selected' : ''?>>Invite only</option>
                </select>
            </div>
            <div class="field"><label>Member limit</label><input type="number" name="clanMaxMembers" min="2" max="500" value="<?=pcH($myClan['max_members'])?>"></div>
            <button class="btn" type="submit">Save settings</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="panel">
        <div class="section-head"><h2>Ownership</h2><span class="muted">Transfer or disband</span></div>
        <form class="search" method="post">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="transfer">
            <select name="targetAccountID" required>
                <option value="">Transfer ownership to...</option>
                <?php foreach ($myClanMembers as $member): ?>
                    <?php if ((string)$member['role'] !== 'owner'): ?>
                    <option value="<?=((int)$member['account_id'])?>"><?=pcH($member['username'])?> · <?=pcH($member['role'])?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit" onclick="return confirm('Transfer clan ownership?');">Transfer</button>
        </form>
        <form method="post" style="margin-top:9px">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="disband">
            <button class="btn alt" type="submit" onclick="return confirm('Disband this clan permanently?');">Disband clan</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if ($account && $myClan): ?>
<section class="section" id="my-clan">
    <div class="panel">
        <div class="section-head">
            <h2>Clan tag in Geometry Dash</h2>
            <span class="tag">[<?=pcH($myClan['tag'])?>]</span>
        </div>
        <p class="copy">
            Members keep their real usernames. MuchoCore decorates the standard GD username
            returned by profile, search, leaderboard and social endpoints, so a member can
            appear as <b>[<?=pcH($myClan['tag'])?>]PlayerName</b> in an unmodified compatible client.
        </p>
        <div class="notice">
            No GD mod is required for the tag itself. The old client is simply receiving a
            normal username string from the server.
        </div>
    </div>
</section>

<?php if ($account && $myClan && in_array((string)$myClan['role'], ['owner', 'officer'], true)): ?>
<section class="section">
    <div class="panel">
        <div class="section-head">
            <h2>Invite a player</h2>
            <span class="muted">Use the player's Geometry Dash username</span>
        </div>
        <form class="search" method="post">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="invite">
            <input type="text" name="targetUsername" maxlength="20" placeholder="Player username" required>
            <button class="btn" type="submit">Send invite</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if ($account && $myClan && in_array((string)$myClan['role'], ['owner', 'officer'], true)): ?>
<section class="section">
    <div class="panel">
        <div class="section-head"><h2>Invitations & bans</h2><span class="muted">Officer tools</span></div>

        <?php if ($myClanInvites): ?>
            <?php foreach ($myClanInvites as $invite): ?>
            <div class="member">
                <span><b><?=pcH($invite['username'])?></b><small>expires <?=pcH($invite['expires_at'])?></small></span>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="revoke_invite">
                    <input type="hidden" name="inviteID" value="<?=((int)$invite['invite_id'])?>">
                    <button class="btn alt" type="submit">Revoke</button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty">No outgoing invitations.</div>
        <?php endif; ?>

        <div class="form" style="margin-top:12px">
            <?php if ($myClanBans): ?>
                <?php foreach ($myClanBans as $ban): ?>
                <div class="member">
                    <span><b><?=pcH($ban['username'])?></b><small><?=pcH($ban['reason'] ?: 'No reason')?></small></span>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                        <input type="hidden" name="action" value="unban">
                        <input type="hidden" name="targetAccountID" value="<?=((int)$ban['account_id'])?>">
                        <button class="btn alt" type="submit">Unban</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty">No active clan bans.</div>
            <?php endif; ?>
        </div>

        <form class="search" method="post" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
            <input type="hidden" name="action" value="ban">
            <input type="text" name="targetUsername" maxlength="20" placeholder="Player username">
            <input type="text" name="targetAccountID" inputmode="numeric" pattern="\\d*" placeholder="or Account ID">
            <input type="text" name="reason" maxlength="160" placeholder="Reason (optional)">
            <button class="btn alt danger" type="submit">Ban player</button>
        </form>
        <div class="helper" style="margin-top:7px">Ban blocks future joins and invitations. The target does not have to be a current member.</div>
    </div>
</section>
<?php endif; ?>

<?php if ($account): ?>
<?php $pendingInvites = $repo->invitations((int)$account['id']); ?>
<?php if ($pendingInvites): ?>
<section class="section">
    <div class="panel">
        <div class="section-head">
            <h2>Pending invitations</h2>
            <span class="muted"><?=count($pendingInvites)?> waiting</span>
        </div>
        <?php foreach ($pendingInvites as $invite): ?>
        <div class="member">
            <span>
                <b>[<?=pcH($invite['tag'])?>] <?=pcH($invite['name'])?></b>
                <small>Invited by <?=pcH($invite['invited_by_username'])?> · expires <?=pcH($invite['expires_at'])?></small>
            </span>
            <span>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="inviteID" value="<?=((int)$invite['invite_id'])?>">
                    <button class="btn" type="submit">Accept</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="decline">
                    <input type="hidden" name="inviteID" value="<?=((int)$invite['invite_id'])?>">
                    <button class="btn alt" type="submit">Decline</button>
                </form>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php endif; ?>

<footer class="footer">
    <?=pcH($serverName)?> Player Dashboard · Powered by MuchoCore
    <?php if ($serverByName !== '' && $socialUrl !== ''): ?>
    · Server by <a href="<?=pcH($socialUrl)?>" target="_blank" rel="noopener noreferrer"><?=pcH($serverByName)?></a>
    <?php endif; ?>
</footer>
</div>
</body>
</html>
