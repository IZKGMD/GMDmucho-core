<?php
/** Public password recovery and email-verification pages. */
$kind = isset($_GET['email_recovery']) ? 'request'
    : (isset($_GET['email_verify']) ? 'verify'
    : (isset($_GET['email_reset']) ? 'reset' : 'status'));
$status = isset($_GET['email_status']) && is_string($_GET['email_status'])
    ? $_GET['email_status'] : '';
$token = $kind === 'verify' ? (isset($_GET['email_verify']) ? $_GET['email_verify'] : '')
    : ($kind === 'reset' ? (isset($_GET['email_reset']) ? $_GET['email_reset'] : '') : '');
$allowed = array('sent'=>'If that email is verified and email delivery is available, a recovery link will arrive shortly.',
    'verified'=>'Recovery email confirmed. You can now recover your administrator password.',
    'changed'=>'Password changed. Sign in with your new password; 2FA is still required.',
    'invalid'=>'This link has expired, has been used, or is invalid.',
    'verifyqueued'=>'Verification message submitted to the mail relay. Check your inbox and spam folder.');
$title = $kind === 'verify' ? 'Confirm recovery email'
    : ($kind === 'reset' ? 'Set a new administrator password'
    : ($kind === 'request' ? 'Forgot your password?' : 'Administrator recovery'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?=h($title)?> · MuchoCore</title>
<style nonce="<?=h($adminNonce)?>">
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b101a;color:#eaf0f9;font-family:system-ui,sans-serif;padding:20px;box-sizing:border-box}
main{width:min(460px,100%);border:1px solid #28364c;border-radius:18px;background:#151d2d;padding:28px;box-sizing:border-box}
h1{margin:0 0 12px;font-size:26px}p{color:#a5b4ca;line-height:1.55}
label{display:block;font-size:13px;margin-top:16px;margin-bottom:7px}
input{width:100%;box-sizing:border-box;background:#0a1120;color:#edf2ff;border:1px solid #39465c;border-radius:9px;padding:12px;font:inherit}
button{display:block;width:100%;padding:13px;border:0;background:#8578ff;color:white;border-radius:9px;font-weight:700;margin-top:22px;cursor:pointer}
a{color:#b6b4ff}.alert{border:1px solid #5e415c;padding:12px;border-radius:10px;color:#f5d9e8}
</style>
</head>
<body><main>
<h1><?=h($title)?></h1>
<?php if($error!==''):?><p class="alert" role="alert"><?=h($error)?></p><?php endif ?>
<?php if($status!=='' && isset($allowed[$status])): ?>
<p><?=h($allowed[$status])?></p>
<?php elseif($kind==='verify' && mucho7_admin_mail_valid_token($token)): ?>
<p>Confirm that this address may be used for future password recovery.</p>
<form method="post" action="<?=h($script)?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="admin-mail-verify-confirm">
<input type="hidden" name="token" value="<?=h($token)?>">
<button type="submit">Confirm recovery email</button>
</form>
<?php elseif($kind==='reset' && mucho7_admin_mail_valid_token($token)): ?>
<p>The one-time link expires after 20 minutes. Choose a new password (10–72 bytes). On linked GD owner accounts this changes the game password too.</p>
<form method="post" action="<?=h($script)?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="admin-mail-reset-apply">
<input type="hidden" name="token" value="<?=h($token)?>">
<label for="password">New password</label>
<input id="password" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="72" required>
<label for="confirmation">Confirm password</label>
<input id="confirmation" type="password" name="confirmation" autocomplete="new-password" minlength="10" maxlength="72" required>
<button type="submit">Change password</button>
</form>
<?php elseif($kind==='request'): ?>
<p>Enter the verified recovery email for your administrator account. We will not disclose whether an account exists.</p>
<form method="post" action="<?=h($script)?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="admin-mail-reset-request">
<label for="email">Recovery email</label>
<input id="email" name="email" type="email" maxlength="254" autocomplete="email" required>
<button type="submit">Send recovery link</button>
</form>
<?php else: ?>
<p class="alert">This link is invalid or has already expired.</p>
<?php endif ?>
<p><a href="<?=h($script)?>">Return to administrator sign in</a></p>
</main></body></html>
