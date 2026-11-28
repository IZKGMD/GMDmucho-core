<?php
/**
 * Native PHP 7 shared-host recovery through the host's mail() relay.
 *
 * No SMTP credentials, Composer or PHP 8 dependencies. Delivery is not
 * considered verified until the recipient redeems a one-time email token.
 */
declare(strict_types=1);

function mucho7_admin_mail_schema()
{
    return "CREATE TABLE IF NOT EXISTS php7_admin_mail_recovery (
        admin_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        verified_email VARCHAR(254) NULL UNIQUE,
        verified_at DATETIME NULL,
        pending_email VARCHAR(254) NULL,
        verify_token_hash CHAR(64) NULL UNIQUE,
        verify_expires_at DATETIME NULL,
        verify_sent_at DATETIME NULL,
        reset_token_hash CHAR(64) NULL UNIQUE,
        reset_expires_at DATETIME NULL,
        reset_sent_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function mucho7_admin_mail_install(PDO $db)
{
    if (!tableExists($db, 'php7_admin_mail_recovery')) {
        $db->exec(mucho7_admin_mail_schema());
    }
}

function mucho7_admin_mail_normal_email($email)
{
    if (!is_string($email)) return null;
    $email = strtolower(trim($email));
    if (strlen($email) < 5 || strlen($email) > 254
        || preg_match('/[\x00-\x1F\x7F]/', $email)
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return null;
    return $email;
}

/** The installer supplies this site_url. Never use Host or forwarded headers. */
function mucho7_admin_mail_origin($settings)
{
    $url = isset($settings['site_url']) && is_string($settings['site_url'])
        ? trim($settings['site_url']) : '';
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || $parts['scheme'] !== 'https'
        || !preg_match('/^[a-z0-9.-]+$/iD', (string)$parts['host'])
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['path']) && $parts['path'] !== '/')) return null;
    return 'https://'.strtolower($parts['host']);
}

function mucho7_admin_mail_link($settings, $script, $kind, $token)
{
    $origin = mucho7_admin_mail_origin($settings);
    if ($origin === null) throw new RuntimeException('Configure the public HTTPS site URL first.');
    if (!is_string($script) || !preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$~D', $script)
        || strlen($script) > 150) throw new RuntimeException('Invalid administrator entry path.');
    if (!in_array($kind, array('email_verify', 'email_reset'), true)
        || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Invalid recovery link.');
    return $origin.$script.'?'.$kind.'='.rawurlencode($token);
}

/**
 * Prefer an explicitly configured/host-provided sender over a fabricated
 * mailbox at the GDPS domain. The provider may still rewrite the From header.
 *
 * admin_mail_from is a PRIVATE operator setting, not user form input.
 * Only use an address approved for sending by the hosting/email provider.
 * Supplying an invalid explicit address fails closed instead of silently
 * falling back to another identity.
 */
function mucho7_admin_mail_sender($settings, $hostSender = null)
{
    $origin = mucho7_admin_mail_origin($settings);
    if ($origin === null) return null;
    if (isset($settings['admin_mail_from']) && $settings['admin_mail_from'] !== '') {
        return mucho7_admin_mail_normal_email($settings['admin_mail_from']);
    }
    if ($hostSender === null) {
        $hostSender = ini_get('sendmail_from');
    }
    $sender = mucho7_admin_mail_normal_email($hostSender);
    if ($sender !== null) return $sender;
    return mucho7_admin_mail_normal_email(
        'no-reply@'.(string)parse_url($origin, PHP_URL_HOST)
    );
}

/** A true mail() result means queued by the host, never delivered to the inbox. */
function mucho7_admin_mail_send($to, $subject, $body, $settings, $transport = null)
{
    $origin = mucho7_admin_mail_origin($settings);
    $email = mucho7_admin_mail_normal_email($to);
    if ($origin === null || $email === null || !is_string($subject)
        || !preg_match('/^[A-Za-z0-9 .:-]{1,100}$/D', $subject)) return false;
    $from = mucho7_admin_mail_sender($settings);
    if ($from === null) return false;
    $headers = 'From: MuchoCore <'.$from.">\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."Auto-Submitted: auto-generated\r\n";
    if ($transport !== null) return (bool)call_user_func($transport, $email, $subject, $body, $headers);
    if (!function_exists('mail')) {
        error_log('[MuchoCore 5v] Administrator recovery mail() is unavailable.');
        return false;
    }
    $queued = (bool)@mail($email, $subject, $body, $headers);
    if (!$queued) error_log('[MuchoCore 5v] Administrator recovery mail relay rejected message.');
    return $queued;
}

function mucho7_admin_mail_status(PDO $db, $aid)
{
    // The legacy SQLite fixtures and interrupted hosting upgrades may
    // not have the optional recovery table yet.
    $missing = false;
    if (function_exists('tableExists')) {
        $missing = !tableExists($db, 'php7_admin_mail_recovery');
    } elseif ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $probe = $db->query("SELECT name FROM sqlite_master
            WHERE type='table' AND name='php7_admin_mail_recovery'");
        $missing = !$probe->fetchColumn();
    }
    if ($missing) {
        return array('verified_email'=>null, 'pending_email'=>null);
    }
    $q = $db->prepare('SELECT verified_email,pending_email FROM php7_admin_mail_recovery WHERE admin_id=?');
    $q->execute(array((int)$aid));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: array('verified_email'=>null, 'pending_email'=>null);
}

function mucho7_admin_mail_rate(PDO $db, $action, $identity, $ip, $settings)
{
    $pepper = isset($settings['auth_pepper']) ? (string)$settings['auth_pepper'] : '';
    if (!mucho7_auth_event($db, $action, (string)$identity, (string)$ip, $pepper)) {
        return false;
    }
    return true;
}

function mucho7_admin_mail_request_verify(PDO $db, $aid, $email, $ip, $settings, $script, $transport = null)
{
    $email = mucho7_admin_mail_normal_email($email);
    if ($email === null || !mucho7_admin_mail_rate($db, 'admin-mail-verify', (int)$aid, $ip, $settings)) return false;
    $q = $db->prepare('SELECT id FROM php7_admin_users WHERE id=? AND is_active=1 AND password_must_set=0');
    $q->execute(array((int)$aid));
    if (!$q->fetchColumn()) return false;

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $link = mucho7_admin_mail_link($settings, $script, 'email_verify', $token);
    $q = $db->prepare('INSERT INTO php7_admin_mail_recovery
        (admin_id,pending_email,verify_token_hash,verify_expires_at,verify_sent_at)
        VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE pending_email=VALUES(pending_email),
        verify_token_hash=VALUES(verify_token_hash),verify_expires_at=VALUES(verify_expires_at),
        verify_sent_at=VALUES(verify_sent_at)');
    $q->execute(array((int)$aid, $email, $hash));
    $sent = mucho7_admin_mail_send($email, 'MuchoCore admin email verification',
        "Please confirm your administrator recovery email:\n\n".$link
        ."\n\nThis link expires in 30 minutes and can be used only once.\n", $settings, $transport);
    if (!$sent) {
        $q = $db->prepare('UPDATE php7_admin_mail_recovery
            SET pending_email=NULL,verify_token_hash=NULL,verify_expires_at=NULL
            WHERE admin_id=? AND verify_token_hash=?');
        $q->execute(array((int)$aid, $hash));
    }
    return $sent;
}

function mucho7_admin_mail_valid_token($token)
{
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
}

function mucho7_admin_mail_confirm(PDO $db, $token)
{
    if (!mucho7_admin_mail_valid_token($token)) return false;
    $hash = hash('sha256', $token);
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT r.admin_id FROM php7_admin_mail_recovery r
            JOIN php7_admin_users u ON u.id=r.admin_id AND u.is_active=1 AND u.password_must_set=0
            WHERE r.verify_token_hash=? AND r.verify_expires_at>UTC_TIMESTAMP()
            AND r.pending_email IS NOT NULL FOR UPDATE');
        $q->execute(array($hash));
        $id = (int)$q->fetchColumn();
        if ($id < 1) { $db->rollBack(); return false; }
        $q = $db->prepare('UPDATE php7_admin_mail_recovery SET
            verified_email=pending_email,verified_at=UTC_TIMESTAMP(),
            pending_email=NULL,verify_token_hash=NULL,verify_expires_at=NULL,
            reset_token_hash=NULL,reset_expires_at=NULL
            WHERE admin_id=? AND verify_token_hash=?');
        $q->execute(array($id,$hash));
        if ($q->rowCount() !== 1) { $db->rollBack(); return false; }
        mucho7_admin_audit($db,$id,'admin.mail.verified',$id);
        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[MuchoCore 5v] Administrator email confirmation failed.');
        return false;
    }
}

function mucho7_admin_mail_request_reset(PDO $db, $email, $ip, $settings, $script, $transport = null)
{
    $email = mucho7_admin_mail_normal_email($email);
    if ($email === null || !mucho7_admin_mail_rate($db, 'admin-mail-reset', $email, $ip, $settings)) return;
    $q = $db->prepare('SELECT r.admin_id FROM php7_admin_mail_recovery r
        JOIN php7_admin_users u ON u.id=r.admin_id AND u.is_active=1 AND u.password_must_set=0
        LEFT JOIN accounts a ON a.account_id=u.account_id
        WHERE r.verified_email=? AND
          (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))
        AND (r.reset_sent_at IS NULL OR r.reset_sent_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
        LIMIT 1');
    $q->execute(array($email));
    $id = (int)$q->fetchColumn();
    if ($id < 1) return; // Never reveal whether an administrator exists.

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $link = mucho7_admin_mail_link($settings, $script, 'email_reset', $token);
    $q = $db->prepare('UPDATE php7_admin_mail_recovery SET
        reset_token_hash=?,reset_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 MINUTE),
        reset_sent_at=UTC_TIMESTAMP() WHERE admin_id=?
        AND (reset_sent_at IS NULL OR reset_sent_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))');
    $q->execute(array($hash,$id));
    if ($q->rowCount() !== 1) return;

    $sent = mucho7_admin_mail_send($email, 'MuchoCore admin password reset',
        "A password reset was requested for your MuchoCore administrator.\n\n".$link
        ."\n\nThis link expires in 20 minutes and can be used once.\n"
        ."Two-factor authentication remains enabled. Ignore if not requested.\n",
        $settings, $transport);
    if (!$sent) {
        $q=$db->prepare('UPDATE php7_admin_mail_recovery SET
            reset_token_hash=NULL,reset_expires_at=NULL,reset_sent_at=NULL
            WHERE admin_id=? AND reset_token_hash=?');
        $q->execute(array($id,$hash));
    }
}

function mucho7_admin_mail_reset_password(PDO $db, $token, $password, $confirmation, $ip, $settings)
{
    if (!mucho7_admin_mail_valid_token($token)
        || !is_string($password) || strlen($password)<10 || strlen($password)>72
        || preg_match('/[\x00-\x1F\x7F]/', $password)
        || !is_string($confirmation) || !hash_equals($password, $confirmation)
        || !mucho7_admin_mail_rate($db, 'admin-mail-apply', 'reset', $ip, $settings)) return false;

    $hash = hash('sha256', $token);
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT u.id,u.account_id
            FROM php7_admin_mail_recovery r
            JOIN php7_admin_users u ON u.id=r.admin_id AND u.is_active=1 AND u.password_must_set=0
            LEFT JOIN accounts a ON a.account_id=u.account_id
            WHERE r.reset_token_hash=? AND r.reset_expires_at>UTC_TIMESTAMP()
            AND r.verified_email IS NOT NULL
            AND (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))
            FOR UPDATE');
        $q->execute(array($hash));
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $db->rollBack(); return false; }
        $aid=(int)$row['id'];
        $hashed=password_hash($password,PASSWORD_BCRYPT,array('cost'=>12));
        if ($row['account_id'] !== null) {
            // Linked game owner uses one password for both GD and admin.
            $q=$db->prepare('UPDATE accounts SET password_hash=?,gjp2_hash=?
                WHERE account_id=? AND is_active=1 AND is_banned=0');
            $q->execute(array($hashed,password_hash(sha1($password.'mI29fmAnxgTs'),
                PASSWORD_BCRYPT,array('cost'=>12)),(int)$row['account_id']));
        } else {
            $q=$db->prepare('UPDATE php7_admin_users SET password_hash=? WHERE id=? AND is_active=1');
            $q->execute(array($hashed,$aid));
        }
        if ($q->rowCount() !== 1) { $db->rollBack(); return false; }
        $q=$db->prepare('UPDATE php7_admin_mail_recovery SET
            reset_token_hash=NULL,reset_expires_at=NULL,reset_sent_at=NULL
            WHERE admin_id=? AND reset_token_hash=?');
        $q->execute(array($aid,$hash));
        if ($q->rowCount() !== 1) { $db->rollBack(); return false; }

        // Every old browser session checks auth_version at its next request.
        $db->prepare('INSERT IGNORE INTO php7_admin_security(account_id) VALUES(?)')
            ->execute(array($aid));
        $db->prepare('UPDATE php7_admin_security
            SET auth_version=auth_version+1 WHERE account_id=?')->execute(array($aid));
        mucho7_admin_audit($db,$aid,'admin.mail.reset',$aid);
        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[MuchoCore 5v] Administrator password reset failed.');
        return false;
    }
}

/** Reauthenticate before adding or changing a sensitive recovery address. */
function mucho7_admin_mail_reauthenticate(PDO $db, $aid, $password, $otp, $settings)
{
    $account=mucho7_admin_identity($db,(int)$aid);
    $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'';
    if (!$account || !mucho7_admin_mail_rate($db,'admin-mail-bind',(int)$aid,$ip,$settings)
        || !is_string($password) || !password_verify($password,(string)$account['password_hash']))
        throw new RuntimeException('Check the current administrator password.');
    mucho7_admin_factor_login($db,(int)$aid,is_string($otp)?$otp:'');
}
