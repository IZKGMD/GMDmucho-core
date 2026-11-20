<?php
/** Public owner-only emergency recovery form; the secret stays server-side. */
$success=isset($_GET['emergency_status']) && $_GET['emergency_status']==='changed';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Emergency administrator recovery · MuchoCore</title>
<style nonce="<?=h($adminNonce)?>">
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#10131c;color:#edf2fc;font:15px system-ui,sans-serif;padding:20px;box-sizing:border-box}
main{max-width:530px;width:100%;padding:28px;background:#191f2c;border:1px solid #334055;border-radius:16px;box-sizing:border-box}
h1{margin:0 0 16px;font-size:25px}p{line-height:1.65;color:#b6c1d4}label{display:block;margin-top:14px;margin-bottom:6px}
input{width:100%;box-sizing:border-box;padding:12px;background:#0c1321;color:#fff;border:1px solid #4a5870;border-radius:8px;font:inherit}
button{width:100%;padding:13px;background:#9288e9;border:0;border-radius:8px;font:inherit;font-weight:700;margin-top:20px;cursor:pointer}
code{overflow-wrap:anywhere}a{color:#bdb7ff}.notice{color:#d7e5a5}
</style></head><body><main>
<h1>Emergency administrator recovery</h1>
<?php if($success):?>
<p class="notice">Owner password changed. The old recovery code, 2FA, passkeys and sessions have been revoked. Sign in using your new password, then enroll 2FA again.</p>
<?php else:?>
<p>Only the owner of this hosting account can use this procedure. In your FTP file manager, open <code>mc/config/php7-emergency-code.php</code> as text and copy the 96-character code from the second line. Never upload it to a public page or share it with support.</p>
<p>The file is created automatically after an owner exists. The code changes after every successful recovery. This method restores the existing owner; it never creates a new administrator or clears game data.</p>
<?php if($error!==''):?><p role="alert"><?=h($error)?></p><?php endif;?>
<form method="post" action="<?=h($script)?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="admin-emergency-reset">
<label for="code">Private FTP recovery code</label>
<input id="code" name="emergency_code" type="password" minlength="96" maxlength="96" pattern="[0-9a-f]{96}" autocomplete="off" required>
<label for="password">New owner password (10–72 bytes)</label>
<input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required>
<label for="confirmation">Repeat new password</label>
<input id="confirmation" name="confirmation" type="password" autocomplete="new-password" minlength="10" maxlength="72" required>
<p>This emergency operation also removes the owner's old authenticator, passkeys and active browser sessions. Only use it if normal recovery is unavailable.</p>
<button type="submit">Restore existing owner access</button>
</form>
<?php endif;?>
<p><a href="<?=h($script)?>">Return to sign in</a></p>
</main></body></html>