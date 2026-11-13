<?php
declare(strict_types=1);

$index=file_get_contents(__DIR__.'/../../public/admin/index.php');
$migration=file_get_contents(__DIR__.'/../../database/migrations/20260925_009_admin_password_setup.php');
$consoleReset=file_get_contents(__DIR__.'/../../bin/mucho-sync-admin.php');
$consoleRecovery=file_get_contents(__DIR__.'/../../bin/mucho-owner-recovery-link.php');

$checks=[
    'password setup columns' =>
        str_contains($migration,'password_must_set')
        && str_contains($migration,'password_setup_token_hash')
        && str_contains($migration,'password_setup_expires_at'),
    'generated setup token' =>
        str_contains($index,'random_bytes(48)')
        && str_contains($index,"hash('sha256',\$setupToken)"),
    'no password field on admin creation' =>
        !str_contains($index,'name="password" placeholder="Password"')
        && str_contains($index,'The administrator will create their own password'),
    'one-time setup lookup and expiry' =>
        str_contains($index,'password_setup_token_hash=:token')
        && str_contains($index,'password_setup_expires_at>UTC_TIMESTAMP()'),
    'setup clears token after password creation' =>
        str_contains($index,'password_must_set=0')
        && str_contains($index,'password_setup_token_hash=NULL')
        && str_contains($index,'password_setup_expires_at=NULL'),
    'setup password change atomically revokes old API sessions' =>
        str_contains($index, "db->beginTransaction();")
        && str_contains($index, 'UPDATE mucho_admin_client_tokens')
        && str_contains($index, 'revoked_at=UTC_TIMESTAMP()')
        && str_contains($index, "db->commit();"),
    'explicit CLI reset revokes sessions and closes unfinished setup' =>
        str_contains($consoleReset, 'UPDATE mucho_admin_client_tokens')
        && str_contains($consoleReset, 'password_must_set=0')
        && str_contains($consoleReset, 'password_setup_token_hash=NULL')
        && str_contains($consoleReset, 'db->beginTransaction();'),
    'console owner recovery revokes tokens when issuing a new link' =>
        str_contains($consoleRecovery, 'UPDATE mucho_admin_client_tokens')
        && str_contains($consoleRecovery, 'db->beginTransaction();')
        && str_contains($consoleRecovery, 'db->commit();'),
    'strong password requirements' =>
        str_contains($index,'Password must be at least 10 characters.'),
    'invite link shown once' =>
        str_contains($index,"admin_setup_invite")
        && str_contains($index,'The link expires in 24 hours and is shown only once.'),
];

$failed=[];
foreach($checks as $name=>$ok){
    if(!$ok) $failed[]=$name;
}

if($failed){
    fwrite(STDERR,"FAIL: ".implode(', ',$failed).PHP_EOL);
    exit(1);
}

echo "PASS: admin password setup contract checks".PHP_EOL;
