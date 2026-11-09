<?php
/**
 * TEMPORARY owner recovery handler — uploaded only by the FTP-owned
 * MuchoCore 5v.pl recovery utility, removed after one successful POST.
 * PHP 7.0+. Never shipped permanently inside a hosted mc/ directory.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function mucho_recovery_fail($message, $status) {
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message));
    exit;
}

$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$isHttps = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) === 'on';
if (!$isHttps && (string)(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '') !== '443') {
    mucho_recovery_fail('HTTPS required.', 403);
}
if ($host !== '__DOMAIN__' || time() > __EXPIRES__) {
    mucho_recovery_fail('Recovery window expired or wrong site.', 403);
}
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    mucho_recovery_fail('POST required.', 405);
}
$length = (int)(isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0);
if ($length > 2048) {
    mucho_recovery_fail('Request too large.', 413);
}
$token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
if (strlen($token) !== 64 || !hash_equals('__HASH__', hash('sha256', $token))) {
    mucho_recovery_fail('Invalid recovery authorization.', 403);
}
$name = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $name) ||
    strlen($password) < 10 || strlen($password) > 72) {
    mucho_recovery_fail('Invalid owner name or password length.', 422);
}
$root = dirname(__DIR__);
try {
    require_once $root . '/compat/php7/bootstrap.php';
    $settings = mucho7_load_settings($root . '/config/php7-local.php');
    $db = mucho7_connect($settings);
    $db->beginTransaction();

    $q = $db->prepare("SELECT id,account_id FROM php7_admin_users
        WHERE username=? AND role='owner' AND is_active=1 FOR UPDATE");
    $q->execute(array($name));
    $owners = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($owners) !== 1) {
        throw new RuntimeException('Owner not found.');
    }
    $adminId = (int)$owners[0]['id'];
    $gameId = isset($owners[0]['account_id']) ? (int)$owners[0]['account_id'] : 0;
    $bcrypt = password_hash($password, PASSWORD_BCRYPT, array('cost' => 12));
    if (!$bcrypt) {
        throw new RuntimeException('Hashing failed.');
    }
    if ($gameId > 0) {
        $gjp2 = password_hash(sha1($password . 'mI29fmAnxgTs'),
                              PASSWORD_BCRYPT, array('cost' => 12));
        $q = $db->prepare("UPDATE accounts SET password_hash=?, gjp2_hash=?
                           WHERE account_id=? AND is_active=1 AND is_banned=0");
        $q->execute(array($bcrypt, $gjp2, $gameId));
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Owner game account unavailable.');
        }
    } else {
        $q = $db->prepare("UPDATE php7_admin_users
                SET password_hash=?,password_must_set=0,
                password_setup_token_hash=NULL,password_setup_expires_at=NULL
                WHERE id=? AND role='owner' AND is_active=1");
        $q->execute(array($bcrypt, $adminId));
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Owner account unavailable.');
        }
    }

    // Revoke compromised or lost second factors and previously issued sessions.
    $exists = $db->query("SHOW TABLES LIKE 'php7_admin_security'")->fetchColumn();
    if ($exists) {
        $db->prepare("INSERT IGNORE INTO php7_admin_security(account_id,auth_version)
                       VALUES(?,0)")->execute(array($adminId));
        $db->prepare("UPDATE php7_admin_security
                       SET totp_secret=NULL,last_step=-1,auth_version=auth_version+1
                       WHERE account_id=?")->execute(array($adminId));
    }
    if ($db->query("SHOW TABLES LIKE 'php7_admin_recovery_codes'")->fetchColumn()) {
        $db->prepare("DELETE FROM php7_admin_recovery_codes WHERE account_id=?")
           ->execute(array($adminId));
    }
    if ($db->query("SHOW TABLES LIKE 'php7_admin_passkeys'")->fetchColumn()) {
        $db->prepare("UPDATE php7_admin_passkeys SET is_active=0 WHERE admin_user_id=?")
           ->execute(array($adminId));
    }

    $db->commit();
    @unlink(__FILE__);
    echo json_encode(array('ok'=>true, 'message'=>'Owner access restored; 2FA and passkeys revoked.'));
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[MuchoCore 5v recovery] Owner recovery failed.');
    mucho_recovery_fail('Recovery failed; verify owner name and database access.', 422);
}
