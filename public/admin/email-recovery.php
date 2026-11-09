<?php
declare(strict_types=1);

use MuchoCore\Admin\AdminEmailRecovery;
use MuchoCore\Admin\AdminSmtp;
use MuchoCore\Http\ClientIp;

function muchoAdminEmailPage(string $title, string $content): never
{
    if (adminIsPlain()) {
        adminPlainStartOutput();
    }
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/html; charset=utf-8');
    $title = h($title);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $title . ' · MuchoCore</title>';
    echo '<link rel="stylesheet" href="/assets/mucho-design-system.css?v=2">';
    echo '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#101017;color:#f3f0f8;font-family:system-ui,sans-serif;padding:20px;box-sizing:border-box}main{width:min(440px,100%);padding:32px;border:1px solid #393047;border-radius:17px;background:#181520;box-sizing:border-box}h1{font-size:26px;letter-spacing:-.03em}p{color:#b8adc7;line-height:1.6}label{display:block;margin-top:18px;margin-bottom:7px;font-size:13px}input{display:block;width:100%;box-sizing:border-box;padding:12px;background:#101017;color:#fff;border:1px solid #524263;border-radius:9px;font-size:15px}button{background:#ba9cee;color:#181122;border:0;border-radius:9px;padding:13px 17px;font-weight:700;width:100%;margin-top:20px;cursor:pointer}a{color:#c5a9f2}.note{font-size:13px}.mark{font-size:12px;color:#ba9cee;letter-spacing:.1em;text-transform:uppercase;font-weight:700}</style>';
    echo '</head><body><main><div class="mark">MuchoCore Admin</div><h1>' . $title . '</h1>' . $content;
    echo '<p class="note"><a href="/admin/">Return to sign in</a></p></main></body></html>';
    exit;
}

function muchoAdminEmailRoute(PDO $db): void
{
    $action = (string) ($_POST['action'] ?? '');
    $isRoute = isset($_GET['email_recovery']) || isset($_GET['email_verify']) ||
        isset($_GET['email_reset']) ||
        in_array($action, ['admin-email-bind', 'admin-email-recover', 'admin-email-verify', 'admin-email-reset'], true);
    if (!$isRoute) {
        return;
    }
    AdminEmailRecovery::ensureSchema($db);
    $ip = ClientIp::resolve($_SERVER);
    $csrfInput = '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';

    if ($action === 'admin-email-bind') {
        if (!admin()) {
            http_response_code(403);
            exit('Authentication required.');
        }
        checkCsrf();
        $q = $db->prepare('SELECT id,password_hash,totp_secret FROM admin_users WHERE id=:id AND is_active=1');
        $q->execute(['id' => (int) admin()['id']]);
        $me = $q->fetch(PDO::FETCH_ASSOC);
        $password = (string) ($_POST['current_password'] ?? '');
        $otp = trim((string) ($_POST['otp'] ?? ''));
        if (!$me || !password_verify($password, (string) $me['password_hash']) ||
            (!empty($me['totp_secret']) && !verifyTotp((string) $me['totp_secret'], $otp) &&
                !verifyAdminRecoveryCode($db, (int) $me['id'], $otp))) {
            flash('Current password or authenticator code incorrect.', 'error');
        } else {
            $ok = AdminEmailRecovery::requestVerification($db, (int) $me['id'],
                (string) ($_POST['email'] ?? ''), $ip);
            flash($ok ? 'Verification message sent. Open the link in your inbox.' :
                'Could not send verification. Check the address and SMTP settings.', $ok ? 'ok' : 'error');
        }
        header('Location: /admin/?page=admins', true, 303);
        exit;
    }

    if ($action === 'admin-email-recover' || isset($_GET['email_recovery'])) {
        if ($action === 'admin-email-recover') {
            checkCsrf();
            AdminEmailRecovery::requestReset($db, (string) ($_POST['email'] ?? ''), $ip);
            muchoAdminEmailPage('Check your inbox',
                '<p>If the address belongs to a verified administrator and email delivery is configured, a reset link will arrive shortly.</p>');
        }
        muchoAdminEmailPage('Forgot your password?',
            '<p>Enter the verified recovery address for your administrator account.</p>' .
            '<form method="post" action="/admin/">' . $csrfInput .
            '<input type="hidden" name="action" value="admin-email-recover">' .
            '<label for="email">Recovery email</label><input id="email" type="email" name="email" autocomplete="email" maxlength="254" required>' .
            '<button>Send recovery link</button></form>');
    }

    if ($action === 'admin-email-verify' || isset($_GET['email_verify'])) {
        $token = (string) ($_POST['token'] ?? $_GET['email_verify'] ?? '');
        if ($action === 'admin-email-verify') {
            checkCsrf();
            $ok = AdminEmailRecovery::confirmEmail($db, $token);
            muchoAdminEmailPage($ok ? 'Email confirmed' : 'Link unavailable',
                $ok ? '<p>Your administrator recovery email is now verified.</p>' :
                    '<p>This verification link is expired, invalid, or already used.</p>');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            muchoAdminEmailPage('Link unavailable', '<p>This verification link is invalid.</p>');
        }
        muchoAdminEmailPage('Confirm recovery email',
            '<p>Confirm that you want to use this email address to recover your administrator account.</p>' .
            '<form method="post" action="/admin/">' . $csrfInput .
            '<input type="hidden" name="action" value="admin-email-verify">' .
            '<input type="hidden" name="token" value="' . h($token) . '">' .
            '<button>Confirm address</button></form>');
    }

    if ($action === 'admin-email-reset' || isset($_GET['email_reset'])) {
        $token = (string) ($_POST['token'] ?? $_GET['email_reset'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            muchoAdminEmailPage('Link unavailable', '<p>This reset link is invalid or expired.</p>');
        }
        if ($action === 'admin-email-reset') {
            checkCsrf();
            $ok = AdminEmailRecovery::resetPassword($db, $token,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['confirmation'] ?? ''), $ip);
            muchoAdminEmailPage($ok ? 'Password changed' : 'Could not reset password',
                $ok ? '<p>You can now sign in with your new password. Your 2FA requirement stays enabled.</p>' :
                    '<p>Check that the passwords match and contain 12–72 characters, or request a fresh link.</p>');
        }
        muchoAdminEmailPage('Set a new password',
            '<p>This link is single-use and expires after 20 minutes.</p>' .
            '<form method="post" action="/admin/">' . $csrfInput .
            '<input type="hidden" name="action" value="admin-email-reset">' .
            '<input type="hidden" name="token" value="' . h($token) . '">' .
            '<label for="password">New password</label><input id="password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required>' .
            '<label for="confirmation">Confirm password</label><input id="confirmation" type="password" name="confirmation" autocomplete="new-password" minlength="12" maxlength="72" required>' .
            '<button>Change password</button></form>');
    }
}
