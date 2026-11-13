<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminEmailRecovery.php';

use MuchoCore\Admin\AdminEmailRecovery;

function checkRecovery(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL admin email recovery: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

checkRecovery(AdminEmailRecovery::normalEmail('  Operator@Example.COM ') === 'operator@example.com',
    'normalizes email identity');
checkRecovery(AdminEmailRecovery::normalEmail("a@example.com\r\nBcc: victim@example.com") === null,
    'rejects mail header injection');
checkRecovery(AdminEmailRecovery::normalEmail('not-an-email') === null,
    'rejects invalid mailbox');

$root = dirname(__DIR__, 2);
$admin = file_get_contents($root . '/public/admin/index.php');
$route = file_get_contents($root . '/public/admin/email-recovery.php');
$service = file_get_contents($root . '/src/Admin/AdminEmailRecovery.php');
$smtp = file_get_contents($root . '/src/Admin/AdminSmtp.php');
checkRecovery(str_contains($admin, "header('Referrer-Policy: no-referrer')") &&
              str_contains($admin, "header('Cache-Control: private, no-store, max-age=0')") &&
              str_contains($admin, "header('X-Content-Type-Options: nosniff')"),
    'administrator reset and setup links stay private on shared hosting');
checkRecovery(str_contains($admin, "muchoAdminEmailRoute(\$db)"),
    'email recovery is routed before login');
checkRecovery(str_contains($admin, 'admin_password_fingerprint') &&
              str_contains($admin, 'AdminBrowserSessionPolicy::isCurrent(') &&
              str_contains(
                  (string)file_get_contents($root . '/public/admin/core/AdminBrowserSessionPolicy.php'),
                  "hash_equals(hash('sha256', \$passwordHash), \$passwordFingerprint)"
              ),
    'browser sessions are invalidated after credential or role changes');
checkRecovery(str_contains($admin, 'Forgot your password?') &&
              str_contains($admin, 'admin-email-bind'),
    'login and self-service settings expose recovery');
checkRecovery(str_contains($route, 'checkCsrf();') &&
              str_contains($route, "isset(\$_GET['email_recovery'])") &&
              str_contains($route, "isset(\$_GET['email_reset'])"),
    'recovery forms enforce CSRF and both GET routes');
checkRecovery(str_contains($service, "FOR UPDATE") &&
              str_contains($service, "reset_token_hash=NULL") &&
              str_contains($service, "password_hash(\$password, PASSWORD_DEFAULT)"),
    'reset consumes hashed one-time token in transaction');
checkRecovery(str_contains($service, "verified_email IS NOT NULL") &&
              str_contains($service, "a.is_active=1"),
    'reset requires active admin and verified email');
checkRecovery(str_contains($service, 'allowStrict(') &&
              str_contains($service, 'INTERVAL 600 SECOND'),
    'public recovery has strict per-identity and per-IP limits');
checkRecovery(str_contains($smtp, 'STARTTLS') &&
              str_contains($smtp, "'verify_peer' => true") &&
              !str_contains($smtp, '@mail(') && !str_contains($smtp, 'return mail('),
    'SMTP enforces encrypted verified transport without insecure fallback');
checkRecovery(str_contains($smtp, "MUCHO_ADMIN_MAIL_ORIGIN") &&
              !str_contains($smtp, 'HTTP_HOST'),
    'reset URL comes from explicit trusted origin');
