<?php
/** Synthetic authenticator exercises actual ES256/CBOR verification in MariaDB. */
require_once $root.'/compat/php7/passkeys.php';
if(!function_exists('openssl_sign')){
    if(getenv('SHARED_PASSKEY_REQUIRE_OPENSSL'))throw new RuntimeException('OpenSSL required for passkey verification tests.');
    echo "SHARED_PASSKEY_CRYPTO_SKIPPED_LOCAL_RUNTIME_WITHOUT_OPENSSL\n";return;
}
function pk64($bytes){return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
function pkCborHead($type,$n){return $n<24?chr(($type<<5)|$n):($n<256?chr(($type<<5)|24).chr($n):chr(($type<<5)|25).pack('n',$n));}
function pkInt($n){return pkCborHead($n<0?1:0,$n<0?-$n-1:$n);}
function pkBytes($bytes){return pkCborHead(2,strlen($bytes)).$bytes;}
function pkText($bytes){return pkCborHead(3,strlen($bytes)).$bytes;}
function pkReject($callback,$why){$rejected=false;try{$callback();}catch(Throwable $e){$rejected=true;}upgradeCheck($rejected,$why);}
$settings['auth_pepper']=str_repeat('fixture-only-',4);
mucho7_passkeys_install($db);mucho7_domain_configure($settings,$stage,$db);
$service=new \MuchoCore\Admin\SharedPasskeyService($db,'Fixture');$aid=(int)$principal['account_id'];
$fixtureKey=getenv('SHARED_PASSKEY_TEST_KEY');
$key=$fixtureKey&&is_file($fixtureKey)?openssl_pkey_get_private(file_get_contents($fixtureKey)):openssl_pkey_new(array('private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1'));
upgradeCheck($key!==false,'Unable to create synthetic authenticator key');
$pem=openssl_pkey_get_details($key)['key'];$der=base64_decode(preg_replace('/-----[^-]+-----|\s/','',$pem));$point=substr($der,-65);
upgradeCheck(strlen($point)===65&&$point[0]==="\x04",'Invalid synthetic EC point');
$cose="\xa5".pkInt(1).pkInt(2).pkInt(3).pkInt(-7).pkInt(-1).pkInt(1).pkInt(-2).pkBytes(substr($point,1,32)).pkInt(-3).pkBytes(substr($point,33,32));
$credential=random_bytes(32);$rpHash=hash('sha256','fixture.invalid',true);
$registration=function($passkeyOptions,$origin='https://fixture.invalid',$flags=69,$hash=null)use($credential,$cose,$rpHash){
    $passkeyOptions=json_decode(json_encode($passkeyOptions));$client=json_encode(array('type'=>'webauthn.create','challenge'=>$passkeyOptions->publicKey->challenge,'origin'=>$origin));
    $auth=($hash===null?$rpHash:$hash).chr($flags).pack('N',0).str_repeat("\0",16).pack('n',strlen($credential)).$credential.$cose;
    $attestation="\xa3".pkText('fmt').pkText('none').pkText('attStmt')."\xa0".pkText('authData').pkBytes($auth);
    return array('clientDataJSON'=>pk64($client),'attestationObject'=>pk64($attestation),'label'=>'Synthetic fixture');
};
$passkeyOptions=$service->registrationOptions($aid,'Owner');$handle=json_decode(json_encode($passkeyOptions))->publicKey->user->id;
upgradeCheck(in_array(-7,array_column(json_decode(json_encode($passkeyOptions),true)['publicKey']['pubKeyCredParams'],'alg'),true),'P-256 passkeys were not advertised by the native runtime');
$payload=$registration($passkeyOptions);$registered=$service->verifyRegistration($aid,$payload);
upgradeCheck($registered['id']>0&&count($service->listForAdmin($aid))===1,'Credential was not stored for native principal');
pkReject(function()use($service,$aid,$payload){$service->verifyRegistration($aid,$payload);},'Registration challenge replay accepted');
foreach(array(array('https://attacker.invalid',69,null),array('https://fixture.invalid',65,null),array('https://fixture.invalid',69,hash('sha256','wrong.invalid',true))) as $bad){
    $badPayload=$registration($service->registrationOptions($aid,'Owner'),$bad[0],$bad[1],$bad[2]);
    pkReject(function()use($service,$aid,$badPayload){$service->verifyRegistration($aid,$badPayload);},'Invalid origin, UV, or RP hash accepted');
}
$assertion=function($counter=1,$userHandle=null)use($service,$rpHash,$credential,$handle,$key){
    $passkeyOptions=json_decode(json_encode($service->loginOptions()));
    $client=json_encode(array('type'=>'webauthn.get','challenge'=>$passkeyOptions->publicKey->challenge,'origin'=>'https://fixture.invalid'));
    $auth=$rpHash."\x05".pack('N',$counter);$signature='';
    upgradeCheck(openssl_sign($auth.hash('sha256',$client,true),$signature,$key,OPENSSL_ALGO_SHA256),'Synthetic signing failed');
    return array('rawId'=>pk64($credential),'userHandle'=>$userHandle===null?$handle:$userHandle,'clientDataJSON'=>pk64($client),'authenticatorData'=>pk64($auth),'signature'=>pk64($signature));
};
$login=$assertion();$record=$service->verifyLogin($login);
upgradeCheck($record['admin']['id']===$aid&&!$record['requires_totp'],'Native principal login binding failed');
pkReject(function()use($service,$login){$service->verifyLogin($login);},'Assertion challenge replay accepted');
$bad=$assertion(2);$bad['signature']=pk64('not a valid signature');
pkReject(function()use($service,$bad){$service->verifyLogin($bad);},'Forged assertion signature accepted');
$bad=$assertion(2,pk64(random_bytes(32)));
pkReject(function()use($service,$bad){$service->verifyLogin($bad);},'Another user handle accepted');
$bad=$assertion(1);pkReject(function()use($service,$bad){$service->verifyLogin($bad);},'Signature counter rollback accepted');
$bad=$assertion(2);$_SESSION['passkey_login']['created_at']=time()-301;
pkReject(function()use($service,$bad){$service->verifyLogin($bad);},'Expired challenge accepted');
upgradeCheck(!$service->deleteForAdmin($aid+1000,(int)$registered['id']),'Another principal revoked a credential');
$db->exec('UPDATE accounts SET is_banned=1 WHERE account_id=1');$bad=$assertion(2);
pkReject(function()use($db,$stage,$settings,$bad){mucho7_passkey_action($db,null,'shared-passkey-login-verify',$stage,$settings,'fixture',array('credential'=>json_encode($bad)));},'Banned linked game account obtained admin access');
$db->exec('UPDATE accounts SET is_banned=0 WHERE account_id=1');
$db->prepare('UPDATE php7_admin_security SET totp_secret=?,last_step=-1 WHERE account_id=?')->execute(array('JBSWY3DPEHPK3PXP',$aid));
$bad=$assertion(3);
pkReject(function()use($db,$stage,$settings,$bad){mucho7_passkey_action($db,null,'shared-passkey-login-verify',$stage,$settings,'fixture',array('credential'=>json_encode($bad),'otp'=>'invalid'));},'Passkey bypassed enabled 2FA');
$login=$assertion(4);$record=$service->verifyLogin($login);upgradeCheck($record['requires_totp'],'Passkey lost 2FA requirement');
$db->prepare('UPDATE php7_admin_security SET totp_secret=NULL,last_step=-1 WHERE account_id=?')->execute(array($aid));
pkReject(function()use($db,$principal,$stage,$settings){mucho7_passkey_action($db,$principal,'shared-passkey-register-options',$stage,$settings,'fixture',array('current_password'=>'wrong'));},'Passkey enrollment bypassed password reauthentication');
upgradeCheck($service->deleteForAdmin($aid,(int)$registered['id']),'Owner could not revoke own credential');
$panelId=900001;
$db->prepare("INSERT INTO php7_admin_users(id,account_id,username,password_hash,role) VALUES(?,NULL,'PasskeyPanelOnly',?,'viewer')")->execute(array($panelId,$hash));
$panelOptions=$service->registrationOptions($panelId,'PasskeyPanelOnly');$panelHandle=json_decode(json_encode($panelOptions))->publicKey->user->id;
$panelCredential=$service->verifyRegistration($panelId,$registration($panelOptions));
$panelRecord=$service->verifyLogin($assertion(1,$panelHandle));
upgradeCheck($panelRecord['admin']['id']===$panelId&&$panelRecord['admin']['username']==='PasskeyPanelOnly'&&$panelRecord['admin']['role']==='viewer','Passkey confused a panel principal with a game account');
upgradeCheck(mucho7_admin_user($db,$panelId)['game_account_id']===null,'Standalone passkey required a matching game account');
$service->deleteForAdmin($panelId,(int)$panelCredential['id']);$db->exec('DELETE FROM php7_admin_users WHERE id='.$panelId);
echo "PHP7_SHARED_PASSKEY_ES256_ORIGIN_UV_RP_REPLAY_COUNTER_IDENTITY_2FA_OK\n";
