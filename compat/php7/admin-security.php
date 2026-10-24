<?php
/** Original TOTP/recovery flow, bound to native game-account administrators. */
declare(strict_types=1);
require_once __DIR__.'/admin-ui/security-source.php';

function mucho7_admin_security_schema() {
    return array(
        "CREATE TABLE IF NOT EXISTS php7_admin_security (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            totp_secret VARCHAR(64) NULL,
            last_step BIGINT NOT NULL DEFAULT -1,
            auth_version INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS php7_admin_recovery_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL,
            KEY idx_recovery_account(account_id,used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function mucho7_admin_security_row(PDO $db,$aid,$lock=false) {
    if(!tableExists($db,'php7_admin_security'))return array('totp_secret'=>null,'last_step'=>-1,'auth_version'=>0);
    $query=$db->prepare('SELECT * FROM php7_admin_security WHERE account_id=?'.($lock?' FOR UPDATE':''));
    $query->execute(array($aid));
    $row=$query->fetch(PDO::FETCH_ASSOC);
    return $row?:array('totp_secret'=>null,'last_step'=>-1,'auth_version'=>0);
}

function mucho7_admin_security_install(PDO $db) {
    foreach(mucho7_admin_security_schema() as $sql)$db->exec($sql);
}

function mucho7_admin_totp_step($secret,$code,$now=null) {
    if(!is_string($code) || !preg_match('/^[0-9]{6}$/D',$code))return false;
    $now=$now===null?time():$now;
    foreach(array(0,-1,1) as $offset)if(hash_equals(totp($secret,$offset,$now),$code))return intdiv($now,30)+$offset;
    return false;
}

/** Must be called under the factor row lock; a time step or recovery code is single use. */
function mucho7_admin_factor_consume(PDO $db,$aid,$row,$code) {
    $secret=(string)$row['totp_secret'];
    if($secret==='')return false;
    $step=mucho7_admin_totp_step($secret,$code);
    if($step!==false && $step>(int)$row['last_step']) {
        $db->prepare('UPDATE php7_admin_security SET last_step=? WHERE account_id=?')->execute(array($step,$aid));
        return true;
    }
    return verifyAdminRecoveryCode($db,(int)$aid,(string)$code);
}

/** Password verification has already succeeded. No session is created before this returns. */
function mucho7_admin_factor_login(PDO $db,$aid,$code) {
    $db->beginTransaction();
    try {
        $row=mucho7_admin_security_row($db,$aid,true);
        if(!empty($row['totp_secret']) && !mucho7_admin_factor_consume($db,$aid,$row,$code))
            throw new RuntimeException('Check the username, password, and 2FA code.');
        $db->commit();return (int)$row['auth_version'];
    }catch(Exception $error){if($db->inTransaction())$db->rollBack();throw $error;}
}

function mucho7_admin_security_action(PDO $db,$aid,$action,$data,$settings) {
    $actor=mucho7_admin_user($db,$aid);
    if(!$actor || (function_exists('mucho7_admin_can') && !mucho7_admin_can($db,$actor,'self.security')))throw new RuntimeException('No access.');
    if($action==='2fa-cancel'){unset($_SESSION['pending_totp']);return;}
    mucho7_admin_security_install($db);
    $db->prepare('INSERT IGNORE INTO php7_admin_security(account_id) VALUES(?)')->execute(array($aid));
    if(function_exists('mucho7_admin_registry_ready') && mucho7_admin_registry_ready($db))$account=mucho7_admin_identity($db,$aid);
    else{$account=$db->prepare('SELECT username,password_hash FROM accounts WHERE account_id=?');$account->execute(array($aid));$account=$account->fetch(PDO::FETCH_ASSOC);}
    $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'';
    if(!mucho7_auth_event($db,'admin-2fa',$account['username'],$ip,(string)$settings['auth_pepper']))throw new RuntimeException('Too many attempts. Try again in 10 minutes.');
    if($action!=='2fa-enable' && (!isset($data['current_password']) || !is_string($data['current_password']) || !password_verify($data['current_password'],$account['password_hash'])))
        throw new RuntimeException('Check your current password.');
    $db->beginTransaction();
    try {
        $row=mucho7_admin_security_row($db,$aid,true);
        if($action==='2fa-generate') {
            if(!empty($row['totp_secret']))throw new RuntimeException('2FA is already enabled.');
            $_SESSION['pending_totp']=array('secret'=>newTotpSecret(),'created_at'=>time(),'aid'=>$aid);
        }elseif($action==='2fa-enable') {
            $pending=isset($_SESSION['pending_totp'])?$_SESSION['pending_totp']:array();
            $code=isset($data['otp']) && is_string($data['otp'])?trim($data['otp']):'';
            $valid=is_array($pending) && isset($pending['aid'],$pending['created_at'],$pending['secret'])
                && (int)$pending['aid']===$aid && (int)$pending['created_at']>=time()-600 && (int)$pending['created_at']<=time();
            $step=$valid?mucho7_admin_totp_step($pending['secret'],$code):false;
            if(!empty($row['totp_secret']) || $step===false)throw new RuntimeException('Invalid or expired authenticator code. Generate a new setup code and try again.');
            $db->prepare('UPDATE php7_admin_security SET totp_secret=?,last_step=?,auth_version=auth_version+1 WHERE account_id=?')->execute(array($pending['secret'],$step,$aid));
            $codes=generateAdminRecoveryCodes($db,$aid);
        }elseif(in_array($action,array('2fa-disable','recovery-generate'),true)) {
            $code=isset($data['otp']) && is_string($data['otp'])?trim($data['otp']):'';
            if(!mucho7_admin_factor_consume($db,$aid,$row,$code))throw new RuntimeException('Check your 2FA or recovery code.');
            if($action==='2fa-disable') {
                $db->prepare('UPDATE php7_admin_security SET totp_secret=NULL,last_step=-1,auth_version=auth_version+1 WHERE account_id=?')->execute(array($aid));
                $db->prepare('DELETE FROM php7_admin_recovery_codes WHERE account_id=?')->execute(array($aid));
            }else{$codes=generateAdminRecoveryCodes($db,$aid);}
        }else{throw new RuntimeException('Invalid security action.');}
        mucho7_admin_audit($db,$aid,$action,$aid);
        $current=mucho7_admin_security_row($db,$aid);
        $db->commit();
        $_SESSION['auth_version']=(int)$current['auth_version'];
        if(isset($codes))$_SESSION['new_recovery_codes']=$codes;
        if($action==='2fa-enable' || $action==='2fa-disable')unset($_SESSION['pending_totp']);
    }catch(Exception $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
