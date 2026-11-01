<?php
declare(strict_types=1);

/**
 * Read-only HTML fragment for the REBORN dashboard, deliberately separate
 * from PHP 7's compiled player portal. The existing dashboard sources and
 * their generated compatibility mirror remain unchanged.
 */
use MuchoCore\Database\Database;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$secure = (
    ($_SERVER['HTTPS'] ?? '') === 'on' ||
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_name('MUCHO_PLAYER');
session_start();

$account = $_SESSION['account'] ?? null;
$account = is_array($account) && (int)($account['id'] ?? 0) > 0 ? $account : null;
session_write_close();

function pdH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pdFormatNumber(mixed $value): string
{
    return number_format((int)$value);
}

try {
    $db = (new Database())->connection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($account !== null) {
        $q = $db->prepare(
            'SELECT 1 FROM accounts WHERE account_id = ? AND is_active = 1 AND is_banned = 0'
        );
        $q->execute([(int)$account['id']]);
        if (!$q->fetchColumn()) {
            $account = null;
        }
    }
    require __DIR__ . '/events-panel.php';
} catch (Throwable $e) {
    error_log('[MuchoGDPS Events] Fragment unavailable: ' . $e::class);
    http_response_code(503);
    echo '<section class="me-hub"><div class="me-empty">Events are temporarily unavailable.</div></section>';
}
