<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set administrator password</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#07090f;color:#f4f7ff;font-family:system-ui,sans-serif}
.card{width:min(460px,calc(100% - 32px));padding:28px;border:1px solid #263246;border-radius:18px;background:#0f1520;box-sizing:border-box}
h1{margin:0 0 7px;font-size:24px}p{color:#8996aa;line-height:1.55}
label{display:block;margin:14px 0 7px;color:#bfc9d9;font-size:12px;font-weight:700}
input{width:100%;box-sizing:border-box;padding:12px;border-radius:10px;border:1px solid #2a374b;background:#090d14;color:#fff;font-size:14px}
button{width:100%;margin-top:15px;padding:12px;border:0;border-radius:10px;background:#6f63ff;color:#fff;font-weight:800;cursor:pointer}
.err{margin:12px 0;padding:10px;border-radius:10px;background:#32171b;border:1px solid #653038;color:#ffb7bd}
.ok{margin:12px 0;padding:11px;border-radius:10px;background:#123226;border:1px solid #23583f;color:#8ce7b4}
.small{font-size:11px;color:#69778d}
</style>
</head>
<body>
<main class="card">
<h1>Set your administrator password</h1>
<?php if($row): ?>
<p>Welcome, <b><?=h((string)$row['username'])?></b>. Create your own password before signing in to MuchoCore.</p>
<?php endif; ?>

<?php if($error!==''): ?>
<div class="err"><?=h($error)?></div>
<?php endif; ?>

<?php if($success): ?>
<div class="ok">Password created successfully.</div>
<p><a href="<?=h($script)?>" style="color:#a69fff">Continue to administrator login</a></p>
<?php elseif($row): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="admin-password-setup"><input type="hidden" name="setup" value="<?=h($token)?>">
<label for="password">New password</label>
<input id="password" type="password" name="password" autocomplete="new-password" minlength="10" required>
<label for="password_confirm">Confirm password</label>
<input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" minlength="10" required>
<button>Create password</button>
</form>
<p class="small">This one-time setup link expires in 24 hours.</p>
<?php else: ?>
<p>This password setup link is invalid or expired. Ask the server owner to create a new administrator invitation.</p>
<?php endif; ?>
</main>
</body>
</html>
