<?php
declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Core\Environment;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class AccountManagementController
{
    public function __construct(private AccountManagementService $service) {}

    public function handle(Request $request): Response
    {
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        if (!in_array($request->method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            return new Response('Method not allowed.', 405);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', (($request->server['HTTPS'] ?? '') === 'on' || (string)($request->server['SERVER_PORT'] ?? '') === '443') ? '1' : '0');
            ini_set('session.cookie_samesite', 'Lax');
            session_name('MUCHO_ACCOUNT');
            session_start();
        }
        if (!isset($_SESSION['account_management_csrf']) || !is_string($_SESSION['account_management_csrf'])) {
            $_SESSION['account_management_csrf'] = bin2hex(random_bytes(32));
        }
        $message = '';
        $status = 200;
        $success = false;
        if ($request->method === 'POST') {
            if (!hash_equals($_SESSION['account_management_csrf'], $request->postString('csrf'))) {
                $message = 'This page has expired. Refresh the page and try again.';
                $status = 403;
            } else {
                try {
                    $success = $this->service->changePassword(
                        $request->postString('username'), $request->postString('current_password'),
                        $request->postString('password'), $request->postString('password_confirmation'), $request->clientIp()
                    );
                } catch (Throwable) {
                    // Never log a password, token, database error or request body.
                    $status = 503;
                }
                $message = $success
                    ? 'Password changed. Return to Geometry Dash and sign in with your new password.'
                    : ($status === 503 ? 'Account management is temporarily unavailable. Try again later.'
                        : 'Unable to change the password. Check your details or try again later.');
                if ($success) $_SESSION['account_management_csrf'] = bin2hex(random_bytes(32));
            }
        }
        // This session stores only a CSRF token; every change requires the
        // current password. No credentials or authenticated account are saved.
        $csrf = htmlspecialchars($_SESSION['account_management_csrf'], ENT_QUOTES, 'UTF-8');
        session_write_close();
        $base = htmlspecialchars((string)Environment::get('MUCHO_PUBLIC_ASSET_BASE', ''), ENT_QUOTES, 'UTF-8');
        $recovery = htmlspecialchars((string)Environment::get('MUCHO_ACCOUNT_RECOVERY_PATH', '/api/api/accounts/lostusername.php'), ENT_QUOTES, 'UTF-8');
        $flash = $message === '' ? '' : '<div role="status" class="flash ' . ($success ? 'success' : 'error') . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        return new Response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>MuchoCore — account management</title><link rel="icon" href="' . $base . '/assets/muchocore-favicon-v4.png">'
            . '<link rel="stylesheet" href="' . $base . '/recovery/recovery.css"><link rel="stylesheet" href="' . $base . '/muchocore-theme.css?v=3">'
            . '<link rel="stylesheet" href="' . $base . '/assets/product-ui.css?v=2"><script src="' . $base . '/muchocore-theme.js?v=5" defer></script></head>'
            . '<body class="mc-workspace"><main class="shell"><section class="card"><div class="brand"><img class="mc-brand-mark" src="' . $base . '/assets/muchocore-icon.png" alt="" width="44" height="44"><span>MuchoCore</span></div>'
            . '<div class="eyebrow">PLAYER ACCOUNT</div><h1>Account management</h1><p class="lead">Change your Geometry Dash account password. Enter your current password to confirm your identity.</p>' . $flash
            . '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<label for="username">Username</label><input id="username" name="username" maxlength="20" autocomplete="username" required>'
            . '<label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" maxlength="256" autocomplete="current-password" required>'
            . '<label for="password">New password</label><input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>'
            . '<label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>'
            . '<p class="hint">Use a strong password with at least 8 characters. Your old password and recovery links will stop working.</p><button type="submit">Change password</button></form>'
            . '<p><a href="' . $recovery . '">Forgot your password?</a></p></section><footer>Account management · Powered by MuchoCore</footer></main></body></html>', $status, 'text/html; charset=utf-8');
    }
}
