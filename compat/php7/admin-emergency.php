<?php
/**
 * Host-ownership emergency recovery for native 5v.pl PHP7 installations.
 * The secret lives in a PHP-denied, FTP-readable private file; NEVER in a URL
 * or a downloaded release package. Only the single active owner is reset.
 */
declare(strict_types=1);

function mucho7_emergency_file($root)
{
    return $root.'/config/php7-emergency-code.php';
}

/** Keep standalone recovery tests independent of the HTML admin bridge. */
function mucho7_emergency_table_exists(PDO $db, $table)
{
    if (function_exists('tableExists')) return tableExists($db,$table);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $q=$db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute(array($table));
        return (bool)$q->fetchColumn();
    }
    $q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $q->execute(array($table));
    return (bool)$q->fetchColumn();
}

function mucho7_emergency_prefix()
{
    return "<?php http_response_code(404); exit; ?>\n";
}

function mucho7_emergency_ensure($root, $hasOwner)
{
    if (!$hasOwner) return false;
    $path=mucho7_emergency_file($root);
    if (is_file($path)) return true;
    if (!is_dir($root.'/config') || !is_writable($root.'/config')) return false;
    $handle=@fopen($path,'x');
    if (!$handle) return is_file($path);
    @chmod($path,0600);
    $secret=bin2hex(random_bytes(48));
    $data=mucho7_emergency_prefix().$secret."\n";
    $offset=0;
    try {
        while ($offset<strlen($data)) {
            $written=fwrite($handle,substr($data,$offset));
            if ($written===false || $written===0) throw new RuntimeException('Could not create emergency secret.');
            $offset+=$written;
        }
        if (!fflush($handle)) throw new RuntimeException('Could not flush emergency secret.');
        fclose($handle);
        return true;
    } catch (Exception $e) {
        fclose($handle);
        @unlink($path);
        error_log('[MuchoCore 5v] Failed creating private owner recovery file.');
        return false;
    }
}

/**
 * A valid FTP file grants recovery of the existing owner, never new ownership.
 * Updates SQL while holding a filesystem lock. Rotates the token before
 * committing the transaction; any interrupted attempt requires the code
 * currently visible over FTP.
 */
function mucho7_emergency_reset(PDO $db, $root, $code, $password, $confirmation, $ip, $settings)
{
    if (!is_string($code) || preg_match('/^[a-f0-9]{96}$/D',$code)!==1
        || !is_string($password) || strlen($password)<10 || strlen($password)>72
        || preg_match('/[\x00-\x1F\x7F]/',$password)
        || !is_string($confirmation) || !hash_equals($password,$confirmation)) return false;
    if (!mucho7_auth_event($db,'admin-emergency','owner',(string)$ip,
        isset($settings['auth_pepper'])?(string)$settings['auth_pepper']:'')) return false;
    $path=mucho7_emergency_file($root);
    if (!is_file($path) || is_link($path)) return false;
    $handle=@fopen($path,'r+');
    if (!$handle) return false;
    $locked=false;
    try {
        if (!flock($handle,LOCK_EX)) return false;
        $locked=true;
        rewind($handle);
        $stored=stream_get_contents($handle,256);
        $prefix=mucho7_emergency_prefix();
        if (!is_string($stored) || strpos($stored,$prefix)!==0) return false;
        $storedCode=trim(substr($stored,strlen($prefix)));
        if (preg_match('/^[a-f0-9]{96}$/D',$storedCode)!==1
            || !hash_equals($storedCode,$code)) return false;

        // Security tables are ensured BEFORE opening a transaction (MySQL DDL).
        if (!mucho7_emergency_table_exists($db,'php7_admin_security')) mucho7_admin_security_install($db);
        $db->beginTransaction();
        try {
            $query=$db->query("SELECT u.id,u.account_id FROM php7_admin_users u
                LEFT JOIN accounts a ON a.account_id=u.account_id
                WHERE u.role='owner' AND u.is_active=1 AND u.password_must_set=0
                AND (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))
                FOR UPDATE");
            $owners=$query->fetchAll(PDO::FETCH_ASSOC);
            if (count($owners)!==1) throw new RuntimeException('Ambiguous or missing active owner.');
            $id=(int)$owners[0]['id'];
            $gameId=$owners[0]['account_id']===null?0:(int)$owners[0]['account_id'];
            $hash=password_hash($password,PASSWORD_BCRYPT,array('cost'=>12));
            if ($hash===false) throw new RuntimeException('Could not hash password.');
            if ($gameId>0) {
                $gjp2=password_hash(sha1($password.'mI29fmAnxgTs'),
                    PASSWORD_BCRYPT,array('cost'=>12));
                $q=$db->prepare('UPDATE accounts SET password_hash=?,gjp2_hash=?
                    WHERE account_id=? AND is_active=1 AND is_banned=0');
                $q->execute(array($hash,$gjp2,$gameId));
            } else {
                $q=$db->prepare("UPDATE php7_admin_users SET password_hash=?,
                    password_must_set=0,password_setup_token_hash=NULL,
                    password_setup_expires_at=NULL WHERE id=? AND role='owner' AND is_active=1");
                $q->execute(array($hash,$id));
            }
            if ($q->rowCount()!==1) throw new RuntimeException('Owner password not updated.');

            // Physical host control may recover a lost second factor, but no
            // other account, role, game data or security setting is reset.
            $db->prepare('INSERT IGNORE INTO php7_admin_security(account_id) VALUES(?)')
                ->execute(array($id));
            $db->prepare('UPDATE php7_admin_security SET totp_secret=NULL,
                last_step=-1,auth_version=auth_version+1 WHERE account_id=?')
                ->execute(array($id));
            if (mucho7_emergency_table_exists($db,'php7_admin_recovery_codes')) {
                $db->prepare('DELETE FROM php7_admin_recovery_codes WHERE account_id=?')
                    ->execute(array($id));
            }
            if (mucho7_emergency_table_exists($db,'php7_admin_passkeys')) {
                $db->prepare('UPDATE php7_admin_passkeys SET is_active=0
                    WHERE admin_user_id=?')->execute(array($id));
            }
            if (mucho7_emergency_table_exists($db,'php7_admin_mail_recovery')) {
                $db->prepare('UPDATE php7_admin_mail_recovery SET
                    pending_email=NULL,verify_token_hash=NULL,verify_expires_at=NULL,
                    reset_token_hash=NULL,reset_expires_at=NULL WHERE admin_id=?')
                    ->execute(array($id));
            }
            mucho7_admin_audit($db,$id,'admin.emergency_reset',$id);

            $newContent=$prefix.bin2hex(random_bytes(48))."\n";
            rewind($handle);
            $offset=0;
            while ($offset<strlen($newContent)) {
                $written=fwrite($handle,substr($newContent,$offset));
                if ($written===false || $written===0) throw new RuntimeException('Secret rotation failed.');
                $offset+=$written;
            }
            if (!ftruncate($handle,strlen($newContent)) || !fflush($handle))
                throw new RuntimeException('Secret rotation was not persisted.');
            @chmod($path,0600);
            $db->commit();
            return true;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[MuchoCore 5v] Emergency owner recovery failed.');
            return false;
        }
    } finally {
        if ($locked) flock($handle,LOCK_UN);
        fclose($handle);
    }
}
