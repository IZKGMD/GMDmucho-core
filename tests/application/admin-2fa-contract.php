<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Google Authenticator / TOTP contract test.
 */

$root=dirname(__DIR__,2);

$index=file_get_contents($root.'/public/admin/index.php');
$qr=file_get_contents($root.'/public/admin/assets/qrcode.min.js');
$license=file_get_contents($root.'/public/admin/assets/qrcodejs.LICENSE.txt');

$assert=static function(bool $ok,string $message): void {
    if (!$ok) {
        fwrite(STDERR,"FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
};

$assert(str_contains($index,'function totpProvisioningUri('),'otpauth URI helper');
$assert(str_contains($index,"otpauth://totp/"),'otpauth TOTP scheme');
$assert(str_contains($index,"algorithm=SHA1"),'SHA1 TOTP provisioning');
$assert(str_contains($index,"digits=6"),'six digit provisioning');
$assert(str_contains($index,"period=30"),'30 second provisioning');
$assert(str_contains($index,'$_SESSION[\'pending_totp\']=['),'pending TOTP setup state');
$assert(str_contains($index,"'created_at'=>time()"),'setup creation timestamp');
$assert(str_contains($index,'(time()-$createdAt)>600'),'10 minute setup expiry');
$assert(str_contains($index,'verifyTotp($secret,$code)'),'server-side TOTP confirmation');
$assert(str_contains($index,'/admin/assets/qrcode.min.js'),'local QR renderer');
$assert(str_contains($index,'new QRCode(qr'),'QR generated in browser');
$assert(str_contains($index,'The secret is not sent to a QR-code service'),'no external QR service');
$assert(str_contains($index,'Google Authenticator'),'Google Authenticator UI');
$assert(str_contains($index,'2fa-cancel'),'cancel setup flow');
$assert(str_contains($index,'2fa.disable'),'2FA disable audit');
$assert(strlen($qr)>10000,'bundled QRCode.js payload');
$assert(str_contains($license,'MIT License'),'QRCode.js MIT license included');

echo "MUCHOCORE_ADMIN_2FA_OK\n";
