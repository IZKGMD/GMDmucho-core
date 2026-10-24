<?php declare(strict_types=1);
$passkeyUser=isset($user)&&$user&&canPermission('self.security');
if($user&&!$passkeyUser)return;
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return;
require_once __DIR__.'/../passkeys.php';
if($passkeyUser)mucho7_passkeys_install($db);
?>
<section class="card" id="sharedPasskeys" data-endpoint="<?=h($script)?>" data-csrf="<?=h(csrf())?>" data-mode="<?=$passkeyUser?'manage':'login'?>">
<h2>Passkeys</h2>
<?php if($passkeyUser):
mucho7_domain_configure($settings,$root,$db);
$passkeyService=new \MuchoCore\Admin\SharedPasskeyService($db,$branding['server_name']);
?>
<p>Use your device account picker or security key to sign in. Your private key stays on your device. Enrolled administrators must still complete 2FA.</p>
<p><label for="passkeyCurrentPassword">Current password</label><br><input id="passkeyCurrentPassword" type="password" autocomplete="current-password"></p>
<p><label for="passkeyLabel">Passkey name</label><br><input id="passkeyLabel" maxlength="120" value="Admin Passkey"></p>
<?php endif ?>
<p><label for="passkeyOtp">2FA or recovery code (if enabled)</label><br><input id="passkeyOtp" autocomplete="one-time-code" maxlength="32"></p>
<button type="button" id="sharedPasskeyStart"><?=$passkeyUser?'Add a passkey':'Continue with passkey'?></button>
<p role="status" aria-live="polite" id="sharedPasskeyStatus"></p>
<?php if($passkeyUser): ?>
<ul><?php foreach($passkeyService->listForAdmin((int)$user['account_id']) as $passkey): ?><li><?=h($passkey['label'])?> · <?=h($passkey['created_at'])?> <button type="button" data-passkey-revoke="<?=(int)$passkey['id']?>">Revoke</button></li><?php endforeach ?></ul>
<p>Adding or revoking a passkey requires your current password and, when enabled, your 2FA or recovery code.</p>
<?php endif ?>
</section>
<script src="/assets/php7-passkeys.js" defer></script>
