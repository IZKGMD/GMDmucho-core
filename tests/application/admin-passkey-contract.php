<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);

$service=$root.'/src/Admin/AdminPasskeyService.php';
$migration=$root.'/database/migrations/20260925_007_admin_passkeys.php';
$composer=$root.'/composer.json';
$index=$root.'/public/admin/index.php';

foreach([$service,$migration,$composer,$index] as $file){
    if(!is_file($file)){
        fwrite(STDERR,"Missing required file: {$file}\n");
        exit(1);
    }
}

$s=(string)file_get_contents($service);
$i=(string)file_get_contents($index);
$c=(string)file_get_contents($composer);
$m=(string)file_get_contents($migration);

$checks=[
    'library dependency'=>str_contains($c,'report-uri/passkeys-php'),
    'discoverable registration'=>
        str_contains($s,"'required',\n            'required'") &&
        str_contains($s,'getCreateArgs('),
    'usernameless login'=>
        str_contains($s,"getGetArgs(\n            [],") &&
        str_contains($s,"true\n        );"),
    'challenge single-use'=>
        str_contains($s,'takeChallenge') &&
        str_contains($s,'unset($_SESSION[$key])'),
    'challenge expiry'=>str_contains($s,'SESSION_CHALLENGE_TTL'),
    'opaque user handle'=>
        str_contains($s,'passkey_user_handle') &&
        str_contains($m,'VARBINARY(64)'),
    'credential public key storage'=>
        str_contains($m,'credential_public_key TEXT'),
    'signature counter enforcement'=>
        str_contains($s,'signature_counter') &&
        str_contains($s,'processGet('),
    'user handle binding'=>
        str_contains($s,'hash_equals($storedUserHandle,$responseUserHandle)'),
    'required user verification'=>
        substr_count($s,'true')>3 &&
        str_contains($s,'processCreate('),
    'native credential api'=>str_contains($i,'navigator.credentials.get'),
    'conditional passkey autocomplete'=>str_contains($i,'username webauthn'),
    'passkey audit event'=>str_contains($i,'login.passkey'),
    'csrf for registration'=>substr_count($i,'checkPasskeyCsrf()')>=2 &&
        str_contains($i,"\$passkeyAction==='register-options'") &&
        str_contains($i,"\$passkeyAction==='register-verify'"),
    'rp id configuration'=>str_contains($s,'MUCHO_ADMIN_PASSKEY_RP_ID'),
];

$failed=[];
foreach($checks as $name=>$ok){
    if(!$ok) $failed[]=$name;
}

if($failed){
    fwrite(STDERR,'FAIL: '.implode(', ',$failed)."\n");
    exit(1);
}

echo "PASS: admin passkey contract checks\n";
