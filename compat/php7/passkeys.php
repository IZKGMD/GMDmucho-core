<?php
/** WebAuthn binding to native panel principals, preserving password and TOTP. */
declare(strict_types=1);
require_once __DIR__.'/domain.php';
require_once __DIR__.'/admin-ui/SharedPasskeyService.php';
function mucho7_passkeys_install(PDO $db)
{
    mucho7_admin_security_install($db);
    $columns=$db->query('SHOW COLUMNS FROM php7_admin_users')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('passkey_user_handle',$columns,true))$db->exec('ALTER TABLE php7_admin_users ADD COLUMN passkey_user_handle VARBINARY(64) NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS php7_admin_passkeys (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id BIGINT UNSIGNED NOT NULL,
        credential_id VARCHAR(1400) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,user_handle VARBINARY(64) NOT NULL,
        credential_public_key TEXT NOT NULL,signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
        aaguid VARCHAR(64) NULL,label VARCHAR(120) NOT NULL DEFAULT 'Admin Passkey',
        is_backup_eligible TINYINT NULL,is_backed_up TINYINT NULL,is_active TINYINT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,last_used_at TIMESTAMP NULL,
        UNIQUE KEY uq_native_passkey_credential(credential_id),KEY idx_native_passkey_admin(admin_user_id,is_active),
        CONSTRAINT fk_native_passkey_admin FOREIGN KEY(admin_user_id) REFERENCES php7_admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function mucho7_passkey_json($payload,$status=200)
{
    mucho7_admin_clean_response();http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');echo json_encode($payload,JSON_UNESCAPED_SLASHES);exit;
}
function mucho7_admin_establish_session($aid,$factorVersion,$instance)
{
    session_regenerate_id(true);$_SESSION=array('aid'=>$aid,'signed_in'=>time(),'last_seen'=>time(),'auth_version'=>$factorVersion,'admin_registry'=>1,'instance'=>$instance,'csrf'=>bin2hex(random_bytes(24)));
    header('Set-Cookie: '.session_name().'='.rawurlencode(session_id()).'; Path=/; Secure; HttpOnly; SameSite=Strict',false);
}
function mucho7_passkey_action(PDO $db,$user,$action,$root,$settings,$instance,$data)
{
    if(!class_exists('ReportUri\\Passkeys\\WebAuthn')||(!function_exists('openssl_verify')||!function_exists('mb_substr')))throw new RuntimeException('Install the current core build with PHP OpenSSL and mbstring to enable passkeys.');
    if(!tableExists($db,'php7_admin_passkeys'))throw new RuntimeException('Sign in with your password and open Administrators to initialize passkeys.');
    if(!isset($settings['auth_pepper'])||!is_string($settings['auth_pepper'])||$settings['auth_pepper']==='')throw new RuntimeException('Private authentication configuration is missing.');
    $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'';
    if(!mucho7_auth_event($db,'admin-passkey','passkey',$ip,(string)$settings['auth_pepper']))throw new RuntimeException('Too many attempts. Try again in 10 minutes.');
    mucho7_domain_configure($settings,$root,$db);
    $service=new \MuchoCore\Admin\SharedPasskeyService($db,isset($settings['server_name'])?(string)$settings['server_name']:'MuchoCore');
    $operation=substr($action,15);
    if($operation==='login-options')return array('ok'=>true,'options'=>$service->loginOptions());
    $payload=isset($data['credential'])&&is_string($data['credential'])&&strlen($data['credential'])<=65536?json_decode($data['credential'],true):array();
    if(!is_array($payload))throw new RuntimeException('Invalid credential payload.');
    if($operation==='login-verify'){
        $record=$service->verifyLogin($payload);$aid=(int)$record['admin']['id'];
        $principal=mucho7_admin_user($db,$aid);if(!$principal)throw new RuntimeException('Administrator access is unavailable.');
        $version=mucho7_admin_factor_login($db,$aid,isset($data['otp'])&&is_string($data['otp'])?trim($data['otp']):'');
        mucho7_admin_audit($db,$aid,'login.passkey',0);mucho7_admin_establish_session($aid,$version,$instance);
        return array('ok'=>true,'redirect'=>'?page=overview');
    }
    if(!$user||!mucho7_admin_can($db,$user,'self.security'))throw new RuntimeException('Administrator authentication required.');
    $aid=(int)$user['account_id'];
    if($operation==='register-options'||$operation==='revoke'){
        $identity=mucho7_admin_identity($db,$aid);
        if(!$identity||!isset($data['current_password'])||!is_string($data['current_password'])||!password_verify($data['current_password'],$identity['password_hash']))throw new RuntimeException('Check your current password.');
        mucho7_admin_factor_login($db,$aid,isset($data['otp'])&&is_string($data['otp'])?trim($data['otp']):'');
    }
    if($operation==='register-options')return array('ok'=>true,'options'=>$service->registrationOptions($aid,(string)$user['username']));
    if($operation==='register-verify'){$record=$service->verifyRegistration($aid,$payload);mucho7_admin_audit($db,$aid,'passkey.register',(int)$record['id']);return array('ok'=>true);}
    if($operation==='revoke'){
        $id=isset($data['passkey_id'])?(int)$data['passkey_id']:0;
        if(!$service->deleteForAdmin($aid,$id))throw new RuntimeException('Passkey not found.');
        mucho7_admin_audit($db,$aid,'passkey.revoke',$id);return array('ok'=>true);
    }
    throw new RuntimeException('Unknown passkey action.');
}
