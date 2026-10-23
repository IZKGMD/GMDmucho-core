<?php


$maintenance=is_file(
    $root.'/config'.'/maintenance.flag'
);

$regDisabled=is_file(
    $root.'/config'.'/registrations-disabled.flag'
);

?>
<div class="card">
<form method="post">

<p>
<strong>Server branding</strong>
</p>

<p class="muted">
This name replaces the visible server logo on the public pages and player music dashboard. MuchoCore attribution stays in the footer.
</p>

<label style="display:block;margin-bottom:7px">Server name</label>
<input
 type="text"
 name="server_name"
 maxlength="64"
 value="<?=h($branding['server_name'])?>"
 placeholder="My GDPS"
 style="width:min(520px,100%)"
>

<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:14px 0 18px">
 <div>
  <label style="display:block;margin-bottom:7px">Server by</label>
  <input
   type="text"
   name="server_by_name"
   maxlength="64"
   value="<?=h($branding['server_by_name'] ?? '')?>"
   placeholder="Your nickname"
   style="width:100%"
  >
 </div>
 <div>
  <label style="display:block;margin-bottom:7px">Social/profile URL</label>
  <input
   type="url"
   name="social_url"
   maxlength="512"
   value="<?=h($branding['social_url'] ?? '')?>"
   placeholder="https://discord.gg/..."
   style="width:100%"
  >
 </div>
</div>

<div class="muted small" style="margin:-6px 0 18px">
Shown in the public footer as <b>Server by</b>. Leave both fields empty to hide the credit.
</div>

<div style="margin:12px 0 18px;padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:linear-gradient(135deg,#111724,#0d1119)">
 <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.08em">Preview</div>
 <div style="margin-top:6px;font-size:24px;font-weight:900;letter-spacing:-.8px">
  <span style="background:linear-gradient(90deg,#fff,#9687ff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent"><?=h($branding['server_name'])?></span>
  <span style="color:#9687ff"> Control</span>
 </div>
</div>

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="settings-save">
<input type="hidden" name="return" value="settings">

<p>
<label>
<input
 type="checkbox"
 name="maintenance"
 <?=$maintenance?'checked':''?>
>
Maintenance mode
</label>
</p>

<p>
<label>
<input
 type="checkbox"
 name="registrations_disabled"
 <?=$regDisabled?'checked':''?>
>
Disable new registrations
</label>
</p>

<button>Apply</button>
</form>
</div>
<?php
