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

            $name = trim((string)($_POST['clanName'] ?? ''));
            $tag = strtoupper(trim((string)($_POST['clanTag'] ?? '')));
            $description = substr(
                trim(preg_replace('/\s+/', ' ', (string)($_POST['clanDescription'] ?? '')) ?? ''),
                0,
                160
            );
            $open = ((int)($_POST['clanOpen'] ?? 1)) === 1;
            $maxMembers = max(2, min(500, (int)($_POST['clanMaxMembers'] ?? 50)));

            if (
                strlen($name) > 32 ||
                preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,31}$/D', $name) !== 1 ||
                strlen($tag) < 2 ||
                strlen($tag) > 8 ||
                preg_match('/^[A-Z0-9]{2,8}$/D', $tag) !== 1
            ) {
                throw new RuntimeException('Use a valid clan name and a 2–8 character tag. Clan names cannot contain spaces.');
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

            if (!(new RateLimiter())->allowStrict(
                'clan-invite:' . $accountId,
                30,
                3600
            )) {
                throw new RuntimeException('Invitation rate limit reached. Try again later.');
            }

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
            $name = trim((string)($_POST['clanName'] ?? ''));
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
                throw new RuntimeException('Use a valid clan name and a 2–8 character tag. Clan names cannot contain spaces.');
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


        if ($action === 'apply') {
            $clanId = (int)($_POST['clanID'] ?? 0);

            if ($myClan !== null) {
                throw new RuntimeException('You are already in a clan.');
            }

            if (!(new RateLimiter())->allowStrict(
                'clan-application:' . $accountId,
                10,
                3600
            )) {
                throw new RuntimeException('Application rate limit reached. Try again later.');
            }

            $message = substr(
                trim(preg_replace('/\\s+/', ' ', (string)($_POST['message'] ?? '')) ?? ''),
                0,
                160
            );

            $repo->apply($clanId, $accountId, $message);
            pcFlash('Clan application sent.');
            pcRedirect();
        }

        if ($action === 'cancel_application') {
            $applicationId = (int)($_POST['applicationID'] ?? 0);
            $repo->cancelApplication($applicationId, $accountId);
            pcFlash('Clan application cancelled.');
            pcRedirect();
        }

        if ($action === 'accept_application') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $applicationId = (int)($_POST['applicationID'] ?? 0);
            $repo->acceptApplication($applicationId, $accountId);
            pcFlash('Clan application accepted.');
            pcAudit($db, $accountId, 'clan.application.accepted', (int)$myClan['clan_id'], [
                'application_id' => $applicationId,
            ]);
            pcRedirect();
        }

        if ($action === 'decline_application') {
            if (
                $myClan === null ||
                !in_array((string)$myClan['role'], ['owner', 'officer'], true)
            ) {
                throw new RuntimeException('Officer permission required.');
            }

            $applicationId = (int)($_POST['applicationID'] ?? 0);
            $repo->declineApplication($applicationId, $accountId);
            pcFlash('Clan application declined.');
            pcAudit($db, $accountId, 'clan.application.declined', (int)$myClan['clan_id'], [
                'application_id' => $applicationId,
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
if ($myClan !== null) {
    $myClan['permissions'] = $repo->permissionMap((string)$myClan['role']);
}
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
$myClanApplications = (
    $myClan !== null &&
    in_array((string)$myClan['role'], ['owner', 'officer'], true)
)
    ? $repo->clanApplications((int)$myClan['clan_id'])
    : [];
$myApplications = $account
    ? $repo->applications((int)$account['id'])
    : [];
$myClanStats = $myClan !== null
    ? $repo->stats((int)$myClan['clan_id'])
    : [];
$topClansStars = $repo->topClans('stars', 10);
$topClansDemons = $repo->topClans('demons', 10);
$topClansCreators = $repo->topClans('creator_points', 10);
$topClansMembers = $repo->topClans('members', 10);

function pcNum(mixed $value): string
{
    return number_format((int)$value, 0, '.', ',');
}

$selectedApplication = (
    $account &&
    !$myClan &&
    $selected !== null
)
    ? $repo->applicationForAccount(
        (int)$selected['clan_id'],
        (int)$account['id']
    )
    : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#080b12">
<title><?=pcH($serverName)?> · Clans</title>
<link rel="stylesheet" href="/muchocore-theme.css?v=3">
<script src="/muchocore-theme.js?v=3" defer></script>
<style>
:root{--bg:#080b12;--panel:#101722;--panel2:#0c121c;--line:#263246;--muted:#8290a5;--text:#f3f6fb;--accent:#8e7dff}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,sans-serif}
.shell{width:min(980px,calc(100% - 24px));margin:auto;padding:18px 0 42px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:16px;background:#0b1018}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit}.logo{width:40px;height:40px;border-radius:10px;object-fit:cover}.brand b{display:block;font-size:12px}.brand small{display:block;color:#6f7d92;font-size:8px;letter-spacing:.12em;font-weight:800;margin-top:2px}
.nav{display:flex;gap:6px;flex-wrap:wrap}.nav a,.nav button{border:1px solid var(--line);background:#111927;color:#cdd6e3;padding:8px 10px;border-radius:9px;font-size:10px;font-weight:800;text-decoration:none;cursor:pointer}
.notice{margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:11px;background:#111925;color:#cbd5e2;font-size:11px}.notice.error{border-color:#5c3440;color:#ffc2cb}
.hero{margin-top:12px;padding:18px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(145deg,#111924,#0c121b)}
.back{color:#8e9ab0;text-decoration:none;font-size:10px;font-weight:800}.clan-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-top:9px}.tag{color:#b8aeff;font-size:11px;font-weight:900;letter-spacing:.08em}.title{margin:3px 0 0;font-size:30px;line-height:1.05;letter-spacing:-1px}.desc{margin:8px 0 0;color:var(--muted);font-size:11px;line-height:1.55;max-width:680px}.meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.chip{padding:5px 8px;border:1px solid var(--line);border-radius:999px;background:#111b28;color:#b8c4d6;font-size:9px;font-weight:800}
.actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border-radius:9px;border:1px solid transparent;background:var(--accent);color:white;font-size:10px;font-weight:900;text-decoration:none;cursor:pointer}.btn.alt{background:#111927;border-color:var(--line);color:#d7dfeb}.btn.danger{background:#1a1116;border-color:#5a2e39;color:#ffb9c3}
.section{margin-top:12px}.section-title{display:flex;align-items:end;justify-content:space-between;gap:8px;margin:0 0 8px}.section-title h2{margin:0;font-size:16px}.section-title span{color:#738197;font-size:9px}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.stat{padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel)}.stat b{display:block;font-size:18px}.stat span{display:block;margin-top:4px;color:#75839a;font-size:8px;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
.list{display:grid;gap:7px}.member{display:flex;justify-content:space-between;align-items:center;gap:9px;padding:10px 11px;border:1px solid var(--line);border-radius:11px;background:var(--panel2)}.member-main{min-width:0}.member b{display:block;font-size:11px}.member small{display:block;color:#75839a;margin-top:2px;font-size:8px}.member-actions{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.role-owner{color:#ffe5aa}.role-officer{color:#cfc4ff}.role-member{color:#a7b6ca}
.card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.card{display:block;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:inherit;text-decoration:none}.card:hover{border-color:#3d4c63}.card .big{font-size:14px;font-weight:900}.card small{display:block;color:#76849a;margin-top:4px;font-size:8px}
.details{border:1px solid var(--line);border-radius:12px;background:var(--panel2);overflow:hidden}.details+.details{margin-top:7px}.details summary{padding:12px;cursor:pointer;font-size:11px;font-weight:900;list-style:none}.details summary::-webkit-details-marker{display:none}.details summary:after{content:'+';float:right;color:#748198}.details[open] summary:after{content:'−'}.details-body{padding:0 12px 12px}
.form{display:grid;gap:8px}.field{display:grid;gap:4px}.field label{color:#8e9bb0;font-size:8px;font-weight:800}.form input,.form textarea,.form select{width:100%;border:1px solid #2a374b;background:#080e16;color:#fff;border-radius:9px;padding:9px 10px;font-size:10px}.form textarea{min-height:68px;resize:vertical}.two{display:grid;grid-template-columns:1fr 1fr;gap:7px}.helper{margin-top:6px;color:#707e93;font-size:8px;line-height:1.5}.empty{padding:16px;text-align:center;border:1px dashed #2d3a4e;border-radius:11px;color:#6f7d92;font-size:10px}
.small-links{display:flex;gap:6px;flex-wrap:wrap}.small-links a{color:#9eaac0;font-size:9px;text-decoration:none}
.footer{margin-top:18px;text-align:center;color:#66748a;font-size:9px}
@media(max-width:700px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.clan-head{flex-direction:column}.card-grid{grid-template-columns:1fr}}
@media(max-width:520px){.shell{width:min(100% - 14px,980px)}.topbar{align-items:stretch;flex-direction:column}.nav{overflow:auto;flex-wrap:nowrap}.two{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
<header class="topbar">
    <a class="brand" href="/dashboard">
        <img class="logo" src="/assets/muchocore-dashboard-logo.jpg?v=1" alt="" width="40" height="40">
        <span><b><?=pcH($serverName)?></b><small>MUCHOCORE CLANS</small></span>
    </a>
    <nav class="nav">
        <a href="/dashboard/clans.php">All clans</a>
        <a href="/dashboard/clans.php#clan-rankings">Rankings</a>
        <?php if ($account): ?><a href="/dashboard">Dashboard</a><?php endif; ?>
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

<?php if ($selected): ?>
<section class="hero">
    <a class="back" href="/dashboard/clans.php">← All clans</a>
    <div class="clan-head">
        <div>
            <div class="tag">[<?=pcH($selected['tag'])?>]</div>
            <h1 class="title"><?=pcH($selected['name'])?></h1>
            <p class="desc"><?=pcH($selected['description'] ?: 'No description yet.')?></p>
            <div class="meta">
                <span class="chip"><?=pcNum($selected['member_count'])?> / <?=pcNum($selected['max_members'])?> members</span>
                <span class="chip"><?=((int)$selected['is_open']===1)?'Open':'Invite only'?></span>
                <span class="chip">Owner: <?=pcH($selected['owner_username'])?></span>
            </div>
        </div>
        <div class="actions">
            <?php if ($account && !$myClan && (int)$selected['is_open']===1): ?>
            <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="join"><input type="hidden" name="clanID" value="<?=((int)$selected['clan_id'])?>"><button class="btn" type="submit">Join clan</button></form>
            <?php elseif ($account && !$myClan): ?>
                <?php if ($selectedApplication): ?>
                    <span class="chip">Application pending</span>
                <?php else: ?>
                <a class="btn" href="#apply">Apply to join</a>
                <?php endif; ?>
            <?php elseif (!$account): ?>
                <a class="btn" href="/dashboard">Sign in to join</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-title"><h2>Clan stats</h2><span>Live from current members</span></div>
    <?php $selectedStats=$repo->stats((int)$selected['clan_id']); ?>
    <div class="stats">
        <div class="stat"><b><?=pcNum($selectedStats['total_stars']??0)?></b><span>Stars</span></div>
        <div class="stat"><b><?=pcNum($selectedStats['total_demons']??0)?></b><span>Demons</span></div>
        <div class="stat"><b><?=pcNum($selectedStats['total_creator_points']??0)?></b><span>Creator points</span></div>
        <div class="stat"><b><?=pcNum($selectedStats['total_levels']??0)?></b><span>Published levels</span></div>
    </div>
</section>

<?php if ($account && !$myClan && $selectedApplication): ?>
<section class="section">
    <details class="details" open>
        <summary>My application</summary>
        <div class="details-body">
            <div class="notice">Your application is waiting for an owner/officer. Expires <?=pcH($selectedApplication['expires_at'])?>.</div>
            <form method="post" class="actions">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="cancel_application">
                <input type="hidden" name="applicationID" value="<?=((int)$selectedApplication['application_id'])?>">
                <button class="btn alt" type="submit">Cancel application</button>
            </form>
        </div>
    </details>
</section>
<?php elseif ($account && !$myClan && (int)$selected['is_open']!==1): ?>
<section class="section" id="apply">
    <details class="details" open>
        <summary>Apply to join</summary>
        <div class="details-body">
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="clanID" value="<?=((int)$selected['clan_id'])?>">
                <div class="field"><label>Message</label><textarea name="message" maxlength="160" placeholder="Tell the clan why you'd like to join"></textarea></div>
                <button class="btn" type="submit">Send application</button>
            </form>
        </div>
    </details>
</section>
<?php endif; ?>

<section class="section">
    <div class="section-title"><h2>Members</h2><span><?=pcNum(count($selected['members']))?> people</span></div>
    <div class="list">
    <?php foreach ($selected['members'] as $member): ?>
        <?php
        $selectedViewerRole=(
            $account &&
            $myClan &&
            (int)$myClan['clan_id']===(int)$selected['clan_id']
        ) ? (string)$myClan['role'] : null;
        $canKick=in_array($selectedViewerRole,['owner','officer'],true)
            && (string)$member['role']!=='owner'
            && !($selectedViewerRole==='officer' && (string)$member['role']!=='member');
        $canChangeRole=$selectedViewerRole==='owner' && (string)$member['role']!=='owner';
        $nextRole=(string)$member['role']==='officer'?'member':'officer';
        ?>
        <div class="member">
            <div class="member-main">
                <b><a href="/dashboard?u=<?=rawurlencode((string)$member['username'])?>" style="color:inherit;text-decoration:none"><?=pcH($member['username'])?></a></b>
                <small>Account #<?=pcH($member['account_id'])?> · <span class="role-<?=pcH((string)$member['role'])?>"><?=pcH(ucfirst((string)$member['role']))?></span></small>
            </div>
            <?php if ($canKick || $canChangeRole): ?>
            <div class="member-actions">
                <?php if ($canKick): ?>
                <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="kick"><input type="hidden" name="targetAccountID" value="<?=((int)$member['account_id'])?>"><button class="btn alt" type="submit" onclick="return confirm('Remove this member from the clan?');">Remove</button></form>
                <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="ban"><input type="hidden" name="targetAccountID" value="<?=((int)$member['account_id'])?>"><button class="btn danger" type="submit" onclick="return confirm('Ban this player from the clan?');">Ban</button></form>
                <?php endif; ?>
                <?php if ($canChangeRole): ?>
                <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="role"><input type="hidden" name="targetAccountID" value="<?=((int)$member['account_id'])?>"><input type="hidden" name="role" value="<?=pcH($nextRole)?>"><button class="btn alt" type="submit"><?=($nextRole==='officer')?'Promote':'Demote'?></button></form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</section>

<?php if ($account && $myClan && (int)$myClan['clan_id']===(int)$selected['clan_id']): ?>
<section class="section">
    <div class="section-title"><h2>My clan</h2><span><?=pcH(ucfirst((string)$myClan['role']))?></span></div>

    <?php if (in_array((string)$myClan['role'],['owner','officer'],true)): ?>
    <details class="details" open>
        <summary>Invite players</summary>
        <div class="details-body">
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="invite">
                <div class="field"><label>Geometry Dash username</label><input type="text" name="targetUsername" maxlength="20" placeholder="Player username" required></div>
                <button class="btn" type="submit">Send invite</button>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <?php if ((string)$myClan['role']==='owner'): ?>
    <details class="details">
        <summary>Manage clan</summary>
        <div class="details-body">
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="settings">
                <div class="two">
                    <div class="field"><label>Clan name</label><input type="text" name="clanName" maxlength="32" value="<?=pcH($myClan['name'])?>" required></div>
                    <div class="field"><label>Tag</label><input type="text" name="clanTag" maxlength="8" value="<?=pcH($myClan['tag'])?>" required></div>
                </div>
                <div class="field"><label>Description</label><textarea name="clanDescription" maxlength="160"><?=pcH($myClan['description']??'')?></textarea></div>
                <div class="two">
                    <div class="field"><label>Access</label><select name="clanOpen"><option value="1" <?=((int)$myClan['is_open']===1)?'selected':''?>>Open</option><option value="0" <?=((int)$myClan['is_open']!==1)?'selected':''?>>Invite only</option></select></div>
                    <div class="field"><label>Member limit</label><input type="number" name="clanMaxMembers" min="2" max="500" value="<?=pcH($myClan['max_members'])?>"></div>
                </div>
                <button class="btn" type="submit">Save changes</button>
            </form>

            <div class="section">
                <div class="section-title"><h2>Transfer ownership</h2><span>Owner only</span></div>
                <form method="post" class="form">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="transfer">
                    <div class="two">
                        <select name="targetAccountID" required>
                            <option value="">Choose member...</option>
                            <?php foreach ($myClanMembers as $member): ?>
                                <?php if ((string)$member['role']!=='owner'): ?><option value="<?=((int)$member['account_id'])?>"><?=pcH($member['username'])?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn alt" type="submit" onclick="return confirm('Transfer ownership?');">Transfer</button>
                    </div>
                </form>
            </div>

            <div class="section">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                    <input type="hidden" name="action" value="disband">
                    <button class="btn danger" type="submit" onclick="return confirm('Delete this clan permanently?');">Delete clan</button>
                </form>
            </div>
        </div>
    </details>
    <?php endif; ?>

    <?php if (in_array((string)$myClan['role'],['owner','officer'],true)): ?>
    <details class="details">
        <summary>Join requests · <?=pcNum(count($myClanApplications))?></summary>
        <div class="details-body">
            <?php if ($myClanApplications): ?>
                <div class="list">
                <?php foreach ($myClanApplications as $application): ?>
                    <div class="member">
                        <div class="member-main"><b><?=pcH($application['username'])?></b><small><?=pcH($application['message']?:'No message')?> · expires <?=pcH($application['expires_at'])?></small></div>
                        <div class="member-actions">
                            <form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="accept_application"><input type="hidden" name="applicationID" value="<?=((int)$application['application_id'])?>"><button class="btn" type="submit">Accept</button></form>
                            <form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="decline_application"><input type="hidden" name="applicationID" value="<?=((int)$application['application_id'])?>"><button class="btn alt" type="submit">Decline</button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?><div class="empty">No pending requests.</div><?php endif; ?>
        </div>
    </details>

    <details class="details">
        <summary>Invitations · <?=pcNum(count($myClanInvites))?></summary>
        <div class="details-body">
            <?php if ($myClanInvites): ?>
                <div class="list">
                <?php foreach ($myClanInvites as $invite): ?>
                    <div class="member"><div class="member-main"><b><?=pcH($invite['username'])?></b><small>expires <?=pcH($invite['expires_at'])?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="inviteID" value="<?=((int)$invite['invite_id'])?>"><button class="btn alt" type="submit">Revoke</button></form></div>
                <?php endforeach; ?>
                </div>
            <?php else: ?><div class="empty">No outgoing invitations.</div><?php endif; ?>
        </div>
    </details>

    <details class="details">
        <summary>Bans · <?=pcNum(count($myClanBans))?></summary>
        <div class="details-body">
            <?php if ($myClanBans): ?>
                <div class="list">
                <?php foreach ($myClanBans as $ban): ?>
                    <div class="member"><div class="member-main"><b><?=pcH($ban['username'])?></b><small><?=pcH($ban['reason']?:'No reason')?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="unban"><input type="hidden" name="targetAccountID" value="<?=((int)$ban['account_id'])?>"><button class="btn alt" type="submit">Unban</button></form></div>
                <?php endforeach; ?>
                </div>
            <?php else: ?><div class="empty">No active bans.</div><?php endif; ?>

            <form method="post" class="form" style="margin-top:9px">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="ban">
                <div class="two">
                    <input type="text" name="targetUsername" maxlength="20" placeholder="Username">
                    <input type="text" name="targetAccountID" inputmode="numeric" pattern="\d*" placeholder="or Account ID">
                </div>
                <input type="text" name="reason" maxlength="160" placeholder="Reason (optional)">
                <button class="btn danger" type="submit">Ban player</button>
            </form>
        </div>
    </details>
    <?php endif; ?>
</section>

<section class="section">
    <details class="details">
        <summary>Clan permissions</summary>
        <div class="details-body">
            <div class="small-links">
                <?php foreach (($myClan['permissions']??$repo->permissionMap((string)$myClan['role'])) as $permission): ?>
                    <span class="chip"><?=pcH(str_replace('_',' ',$permission))?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </details>
</section>
<?php endif; ?>

<?php else: ?>
<section class="hero">
    <div class="tag">MUCHOCORE · CLANS</div>
    <h1 class="title" style="margin-top:7px">Find your clan.</h1>
    <p class="desc">Search clans, open a profile, join open clans, or apply to private ones. Nothing else is hidden behind this page.</p>
    <form class="actions" method="get">
        <input type="text" name="q" maxlength="32" value="<?=pcH($search)?>" placeholder="Search clan name or tag" style="flex:1;min-width:220px;border:1px solid #2a374b;background:#080e16;color:#fff;border-radius:9px;padding:9px 10px">
        <button class="btn" type="submit">Search</button>
    </form>
</section>

<section class="section">
    <div class="section-title"><h2>Clans</h2><span><?=pcNum(count($clans))?> shown</span></div>
    <?php if (!$clans): ?><div class="empty">No clans found.</div>
    <?php else: ?><div class="card-grid">
        <?php foreach ($clans as $clan): ?>
        <a class="card" href="/dashboard/clans.php?clan=<?=((int)$clan['clan_id'])?>">
            <div class="tag">[<?=pcH($clan['tag'])?>]</div>
            <div class="big"><?=pcH($clan['name'])?></div>
            <small><?=pcNum($clan['member_count'])?> / <?=pcNum($clan['max_members'])?> · <?=((int)$clan['is_open']===1)?'Open':'Invite only'?> · Owner <?=pcH($clan['owner_username'])?></small>
        </a>
        <?php endforeach; ?>
    </div><?php endif; ?>
</section>

<section class="section" id="clan-rankings">
    <div class="section-title"><h2>Rankings</h2><span>Top 10</span></div>
    <div class="card-grid">
        <?php foreach ([
            ['title'=>'Top Stars','rows'=>$topClansStars,'value'=>'total_stars','label'=>'stars'],
            ['title'=>'Top Demons','rows'=>$topClansDemons,'value'=>'total_demons','label'=>'demons'],
            ['title'=>'Top Creators','rows'=>$topClansCreators,'value'=>'total_creator_points','label'=>'creator points'],
            ['title'=>'Largest Clans','rows'=>$topClansMembers,'value'=>'member_count','label'=>'members'],
        ] as $ranking): ?>
        <div class="card">
            <div class="big"><?=pcH($ranking['title'])?></div>
            <?php foreach ($ranking['rows'] as $rank=>$clan): ?>
            <div class="member" style="margin-top:6px">
                <div class="member-main"><b>#<?=pcNum($rank+1)?> [<?=pcH($clan['tag'])?>] <?=pcH($clan['name'])?></b><small><?=pcNum($clan[$ranking['value']])?> <?=pcH($ranking['label'])?></small></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($account && !$myClan): ?>
<section class="section">
    <details class="details" open>
        <summary>Create a clan</summary>
        <div class="details-body">
            <form class="form" method="post">
                <input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>">
                <input type="hidden" name="action" value="create">
                <div class="two">
                    <div class="field"><label>Clan name</label><input type="text" name="clanName" maxlength="32" required></div>
                    <div class="field"><label>Tag</label><input type="text" name="clanTag" maxlength="8" placeholder="MUCHO" required></div>
                </div>
                <div class="field"><label>Description</label><textarea name="clanDescription" maxlength="160"></textarea></div>
                <div class="two">
                    <div class="field"><label>Access</label><select name="clanOpen"><option value="1">Open</option><option value="0">Invite only</option></select></div>
                    <div class="field"><label>Member limit</label><input type="number" name="clanMaxMembers" min="2" max="500" value="50"></div>
                </div>
                <button class="btn" type="submit">Create clan</button>
            </form>
        </div>
    </details>
</section>
<?php elseif ($account && $myClan): ?>
<section class="section">
    <div class="section-title"><h2>My clan</h2><span><?=pcH(ucfirst((string)$myClan['role']))?></span></div>
    <a class="card" href="/dashboard/clans.php?clan=<?=((int)$myClan['clan_id'])?>">
        <div class="tag">[<?=pcH($myClan['tag'])?>]</div>
        <div class="big"><?=pcH($myClan['name'])?></div>
        <small><?=pcNum($myClan['member_count'])?> / <?=pcNum($myClan['max_members'])?> members</small>
    </a>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($account && !$myClan && $myApplications): ?>
<section class="section">
    <details class="details">
        <summary>My applications · <?=pcNum(count($myApplications))?></summary>
        <div class="details-body">
            <div class="list">
            <?php foreach ($myApplications as $application): ?>
                <div class="member"><div class="member-main"><b>[<?=pcH($application['tag'])?>] <?=pcH($application['name'])?></b><small>expires <?=pcH($application['expires_at'])?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="cancel_application"><input type="hidden" name="applicationID" value="<?=((int)$application['application_id'])?>"><button class="btn alt" type="submit">Cancel</button></form></div>
            <?php endforeach; ?>
            </div>
        </div>
    </details>
</section>
<?php endif; ?>

<?php if ($account): ?>
<?php $pendingInvites=$repo->invitations((int)$account['id']); ?>
<?php if ($pendingInvites): ?>
<section class="section">
    <details class="details" open>
        <summary>Pending invitations · <?=pcNum(count($pendingInvites))?></summary>
        <div class="details-body">
            <div class="list">
            <?php foreach ($pendingInvites as $invite): ?>
                <div class="member"><div class="member-main"><b>[<?=pcH($invite['tag'])?>] <?=pcH($invite['name'])?></b><small>Invited by <?=pcH($invite['invited_by_username'])?> · expires <?=pcH($invite['expires_at'])?></small></div><div class="member-actions"><form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="accept"><input type="hidden" name="inviteID" value="<?=((int)$invite['invite_id'])?>"><button class="btn" type="submit">Accept</button></form><form method="post"><input type="hidden" name="csrf" value="<?=pcH(pcCsrf())?>"><input type="hidden" name="action" value="decline"><input type="hidden" name="inviteID" value="<?=((int)$invite['invite_id'])?>"><button class="btn alt" type="submit">Decline</button></form></div></div>
            <?php endforeach; ?>
            </div>
        </div>
    </details>
</section>
<?php endif; ?>
<?php endif; ?>

<footer class="footer"><?=pcH($serverName)?> · Powered by MuchoCore<?php if ($serverByName!=='' && $socialUrl!==''): ?> · Server by <a href="<?=pcH($socialUrl)?>" target="_blank" rel="noopener noreferrer"><?=pcH($serverByName)?></a><?php endif; ?></footer>
</div>
</body>
</html>
