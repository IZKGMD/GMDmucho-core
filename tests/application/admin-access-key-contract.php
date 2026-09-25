<?php
declare(strict_types=1);

/*
 * MuchoCore Admin authentication retirement contract test.
 *
 * Access Key authentication is intentionally retired. Password/TOTP and
 * native WebAuthn/FIDO2 passkeys remain the supported Admin Panel methods.
 */

$root=dirname(__DIR__,2);

$index=(string)file_get_contents($root.'/public/admin/index.php');
$migration=(string)file_get_contents($root.'/database/migrations/20260925_008_remove_admin_access_keys.php');

$assert=static function(bool $ok,string $message): void {
    if (!$ok) {
        fwrite(STDERR,"FAIL {$message}\n");
        exit(1);
    }

    echo "PASS {$message}\n";
};

$assert(!str_contains($index,'access_key_hash'),'access key hash removed from inline schema');
$assert(!str_contains($index,'access_key_created_at'),'access key timestamp removed from inline schema');
$assert(!str_contains($index,'newAccessKey'),'access key generator removed');
$assert(!str_contains($index,"action==='access-key-generate'"),'access key generation action removed');
$assert(!str_contains($index,"action==='access-key-revoke'"),'access key revocation action removed');
$assert(!str_contains($index,'name="access_key"'),'access key login field removed');
$assert(!str_contains($index,'Access Key login'),'access key login mode removed');
$assert(!str_contains($index,"'login.access_key'"),'access key audit event removed');

$assert(str_contains($index,'id="passkeyMode"'),'passkey login mode retained');
$assert(str_contains($index,'navigator.credentials.get'),'native passkey login retained');
$assert(str_contains($index,'login-options'),'passkey login options retained');
$assert(str_contains($index,'login-verify'),'passkey verification retained');

$assert(str_contains($migration,'DROP COLUMN'),'legacy access key drop operation present');
$assert(str_contains($migration,'access_key_hash'),'legacy access key hash column is retired');
$assert(str_contains($migration,'access_key_created_at'),'legacy access key timestamp column is retired');
$assert(str_contains($migration,'retired credential cannot remain usable'),'retirement intent documented');

$assert(str_contains($index,'/admin/assets/qrcode.min.js?v=20260925'),'cache-busted local QR renderer');
$assert(str_contains($index,'script.onerror=() =>'),'QR loader fallback');
$assert(str_contains($index,'correctLevel: QRCode.CorrectLevel.L'),'low error correction for compact TOTP QR');

echo "MUCHOCORE_ADMIN_AUTH_RETIREMENT_OK\n";
