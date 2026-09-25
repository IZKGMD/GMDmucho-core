<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Access Key contract test.
 */

$root=dirname(__DIR__,2);

$index=file_get_contents($root.'/public/admin/index.php');
$migration=file_get_contents($root.'/database/migrations/20260925_001_admin_access_keys.php');

$assert=static function(bool $ok,string $message): void {
    if (!$ok) {
        fwrite(STDERR,"FAIL {$message}
");
        exit(1);
    }
    echo "PASS {$message}
";
};

$assert(str_contains($index,'function newAccessKey(): string'),'access key generator');
$assert(str_contains($index,"return 'MUCHO-'.strtoupper(bin2hex(random_bytes(24)));"),'cryptographically random access key');
$assert(str_contains($index,'access_key_hash VARCHAR(255) NULL'),'inline admin schema access key hash');
$assert(str_contains($index,'access_key_created_at TIMESTAMP NULL'),'inline access key timestamp');
$assert(str_contains($index,"action==='access-key-generate'"),'access key generation action');
$assert(str_contains($index,"action==='access-key-revoke'"),'access key revocation action');
$assert(str_contains($index,'password_hash($accessKey,PASSWORD_DEFAULT)'),'only access key hash is persisted');
$assert(str_contains($index,'password_verify(
                $accessKey,
                $row['access_key_hash']'),'access key authentication');
$assert(str_contains($index,"'login.access_key'"),'access key login audit event');
$assert(str_contains($index,'name="access_key"'),'access key login field');
$assert(str_contains($index,'Access Key replaces the password, not the second factor.'),'2FA remains required with access key');
$assert(
    str_contains($index,'$accessKey!==\'\' && $user===\'\''),
    'key-only login path'
);
$assert(str_contains($index,'/admin/assets/qrcode.min.js?v=20260925'),'cache-busted local QR renderer');
$assert(str_contains($index,'script.onerror=() =>'),'QR loader fallback');
$assert(str_contains($index,'correctLevel: QRCode.CorrectLevel.L'),'low error correction for compact TOTP QR');
$assert(str_contains($migration,'ADD COLUMN access_key_hash'),'access key hash migration');
$assert(str_contains($migration,'ADD COLUMN access_key_created_at'),'access key timestamp migration');

echo "MUCHOCORE_ADMIN_ACCESS_KEY_OK
";
