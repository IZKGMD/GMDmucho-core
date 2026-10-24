<?php
$me=mucho7_admin_security_row($db,(int)$user['account_id']);
$me['username']=$user['username'];
$recoveryCodes=isset($_SESSION['new_recovery_codes'])?$_SESSION['new_recovery_codes']:array();
unset($_SESSION['new_recovery_codes']);
$unusedRecoveryCodes=countUnusedAdminRecoveryCodes($db,(int)$user['account_id']);
?>
<div class="boxgrid">

<div class="card admin-2fa-card">
<style>
.admin-2fa-card{position:relative;overflow:hidden}
.admin-2fa-card .security-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 9px;border-radius:999px;background:#123226;border:1px solid #23583f;color:#78e5ad;font-size:11px;font-weight:800}
.admin-2fa-card .security-dot{width:7px;height:7px;border-radius:50%;background:#42df9e;box-shadow:0 0 0 4px rgba(66,223,158,.12)}
.admin-2fa-card .setup-grid{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:center;margin-top:15px}
.admin-2fa-card .qr-box{display:grid;place-items:center;padding:13px;border-radius:14px;background:#fff;border:1px solid #fff;min-height:220px}
.admin-2fa-card .qr-box #totpQr canvas,.admin-2fa-card .qr-box #totpQr img{max-width:100%;height:auto}
.admin-2fa-card .qr-placeholder{width:100%;min-height:194px;display:grid;place-items:center;text-align:center;color:#667184;font-size:11px;background:#f7f8fb;border-radius:8px}
.admin-2fa-card .secret-box{display:flex;align-items:center;gap:8px;margin:8px 0 11px}
.admin-2fa-card .secret-value{flex:1;min-width:0;font:700 13px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;word-break:break-all;padding:11px 12px;border:1px solid #29364a;border-radius:10px;background:#090d14;color:#dde4f0}
.admin-2fa-card .copy-btn{background:#20293a!important;color:#cbd5e5!important;border:1px solid #303d52!important}
.admin-2fa-card .step{display:flex;gap:10px;margin:9px 0}
.admin-2fa-card .step-no{flex:0 0 24px;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:#272149;color:#b9b0ff;font-size:11px;font-weight:850}
.admin-2fa-card .step-text{font-size:12px;color:#9aa6b9;line-height:1.45}
.admin-2fa-card .step-text b{color:#e4e9f2}
.admin-2fa-card .verify-row{display:flex;gap:8px;margin-top:10px}
.admin-2fa-card .verify-row input{flex:1;min-width:0;text-align:center;letter-spacing:.28em;font-weight:800}
.admin-2fa-card .warning{margin-top:12px;padding:10px 11px;border-radius:10px;background:#241f12;border:1px solid #4a4026;color:#cfc08f;font-size:10px;line-height:1.45}
.admin-2fa-card .disabled-state{margin-top:13px;padding:13px;border-radius:12px;background:#101720;border:1px solid #283346}
.admin-2fa-card .disabled-state b{display:block;margin-bottom:4px}
@media(max-width:640px){.admin-2fa-card .setup-grid{grid-template-columns:1fr}.admin-2fa-card .qr-box{max-width:245px;margin:auto}.admin-2fa-card .verify-row{flex-direction:column}.admin-2fa-card .copy-btn{min-height:40px}}
</style>

<?php
$pendingRaw=$_SESSION['pending_totp'] ?? null;
$pendingSecret=is_array($pendingRaw)
    ? (string)($pendingRaw['secret'] ?? '')
    : (string)($pendingRaw ?? '');
$pendingCreated=is_array($pendingRaw)
    ? (int)($pendingRaw['created_at'] ?? 0)
    : 0;
$pendingValid=
    $pendingSecret!=='' &&
    (
        $pendingCreated<=0 ||
        (time()-$pendingCreated)<=600
    );
$totpIssuer=trim((string)$branding['server_name']) ?: 'MuchoCore';
$totpUri=$pendingValid
    ? totpProvisioningUri(
        $pendingSecret,
        (string)($me['username'] ?? admin()['username']),
        $totpIssuer
    )
    : '';
?>

<div class="row" style="justify-content:space-between;align-items:center">
    <div>
        <h2 style="margin:0">Google Authenticator</h2>
        <small>Time-based one-time passwords (TOTP)</small>
    </div>
    <?php if(!empty($me['totp_secret'])): ?>
        <span class="security-badge"><span class="security-dot"></span> Protected</span>
    <?php else: ?>
        <span class="badge">Not enabled</span>
    <?php endif; ?>
</div>

<?php if(!empty($me['totp_secret'])): ?>

<div class="disabled-state" style="background:#123226;border-color:#23583f">
    <b class="ok">Two-factor authentication is active.</b>
    <small>Sign-in requires a current 6-digit code from your authenticator app.</small>
</div>

<div class="warning">
Keep access to your authenticator device. Disabling 2FA removes the extra sign-in factor from this administrator account.
</div>

<form method="post" style="margin-top:12px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="2fa-disable"><label class="admin-auth-field">Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label class="admin-auth-field">2FA or recovery code<input name="otp" autocomplete="one-time-code" required></label>
<input type="hidden" name="return" value="admins">
<button class="red">Disable 2FA</button>
</form>

<?php elseif($pendingValid): ?>

<div class="setup-grid">
    <div class="qr-box">
        <div id="totpQr" aria-label="Authenticator QR code"></div>
        <div id="totpQrStatus" class="qr-placeholder" hidden>QR renderer is loading…</div>
    </div>

    <div>
        <div class="step">
            <span class="step-no">1</span>
            <div class="step-text"><b>Open Google Authenticator</b><br>Tap <b>+</b> and choose <b>Scan a QR code</b>.</div>
        </div>
        <div class="step">
            <span class="step-no">2</span>
            <div class="step-text"><b>Scan this code</b><br>The setup is local; the secret is not sent to a QR-code service.</div>
        </div>
        <div class="step">
            <span class="step-no">3</span>
            <div class="step-text"><b>Confirm the 6-digit code</b><br>Enter the current code below to activate 2FA.</div>
        </div>

        <div style="margin-top:12px">
            <small>Manual setup key</small>
            <div class="secret-box">
                <div class="secret-value" id="totpSecret"><?=h($pendingSecret)?></div>
                <button type="button" class="copy-btn" id="copyTotpSecret">Copy</button>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="2fa-enable">
            <input type="hidden" name="return" value="admins">
            <div class="verify-row">
                <input
                    name="otp"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="000000"
                    required
                >
                <button>Enable 2FA</button>
            </div>
        </form>

        <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="2fa-cancel">
            <input type="hidden" name="return" value="admins">
            <button type="submit" class="gray">Cancel setup</button>
        </form>
    </div>
</div>

<div class="warning">
This setup key is the recovery credential for your TOTP factor. Store it privately and never post it publicly. The setup QR expires after 10 minutes.
</div>

<script>
(() => {
    const uri = <?=json_encode($totpUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
    const qr = document.getElementById('totpQr');
    const status = document.getElementById('totpQrStatus');

    const renderQr = () => {
        if (!qr || !uri) return;
        if (typeof QRCode === 'undefined') {
            if (status) {
                status.hidden = false;
                status.textContent = 'Loading QR renderer…';
            }
            const script=document.createElement('script');
            script.src=<?=json_encode(mucho7_admin_ui_url('/admin/assets/qrcode.min.js?v=20260925'))?>;
            script.onload=renderQr;
            script.onerror=() => {
                if (status) {
                    status.hidden=false;
                    status.textContent='QR renderer failed to load. Use the manual setup key below.';
                }
            };
            document.head.appendChild(script);
            return;
        }

        qr.textContent='';
        new QRCode(qr, {
            text: uri,
            width: 240,
            height: 240,
            colorDark: '#111827',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.L
        });

        if (status) status.hidden=true;
    };

    renderQr();

    document.getElementById('copyTotpSecret')?.addEventListener('click', async () => {
        const secret = document.getElementById('totpSecret')?.textContent?.trim() || '';
        try {
            await navigator.clipboard.writeText(secret);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = secret;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }

        const button = document.getElementById('copyTotpSecret');
        if (button) {
            const original = button.textContent;
            button.textContent = 'Copied';
            setTimeout(() => button.textContent = original, 1200);
        }
    });
})();
</script>

<?php elseif($pendingSecret!=='' && !$pendingValid): ?>

<div class="warning">
The previous authenticator setup expired. Generate a new QR code to continue.
</div>

<form method="post" style="margin-top:10px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="2fa-generate"><label class="admin-auth-field">Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<input type="hidden" name="return" value="admins">
<button>Generate new QR code</button>
</form>

<?php else: ?>

<div class="disabled-state">
    <b>Protect this administrator account.</b>
    <small>Use Google Authenticator or another TOTP-compatible authenticator app. The setup flow will show a QR code and a manual key.</small>
</div>

<form method="post" style="margin-top:12px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="2fa-generate"><label class="admin-auth-field">Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<input type="hidden" name="return" value="admins">
<button>Set up Google Authenticator</button>
</form>

<?php endif; ?>

</div>

<div class="card admin-recovery-card" style="margin-top:13px">
<style>
.admin-recovery-card{position:relative;overflow:hidden}
.admin-recovery-card .recovery-badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;background:#183046;border:1px solid #285579;color:#9fd5ff;font-size:11px;font-weight:800}
.admin-recovery-card .recovery-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
.admin-recovery-card .recovery-code{padding:10px 11px;border:1px solid #2a374b;border-radius:9px;background:#0b1018;color:#eef2ff;font:750 12px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;text-align:center}
.admin-recovery-card .recovery-note{margin-top:11px;color:#7f8ba0;font-size:10px;line-height:1.5}
@media(max-width:640px){.admin-recovery-card .recovery-grid{grid-template-columns:1fr}}
</style>
<div class="row" style="justify-content:space-between;align-items:center">
    <div>
        <h2 style="margin:0">MFA Recovery Codes</h2>
        <small>One-time fallback codes for the authenticator factor</small>
    </div>
    <?php if(!empty($me['totp_secret'])): ?>
        <span class="recovery-badge"><?=h((string)$unusedRecoveryCodes)?> left</span>
    <?php else: ?>
        <span class="badge">Requires 2FA</span>
    <?php endif; ?>
</div>

<?php if(!empty($recoveryCodes)): ?>
<div class="warning" style="margin-top:13px">
    Save these codes now. They are shown once and are stored only as password hashes.
</div>
<div class="recovery-grid">
<?php foreach($recoveryCodes as $code): ?>
    <div class="recovery-code"><?=h($code)?></div>
<?php endforeach; ?>
</div>
<div class="key-actions" style="margin-top:11px">
    <button type="button" class="copy-btn" id="copyRecoveryCodes">Copy all codes</button>
</div>
<script>
document.getElementById('copyRecoveryCodes')?.addEventListener('click',async()=>{
    const values=[...document.querySelectorAll('.recovery-code')].map(x=>x.textContent.trim()).join('\n');
    try{await navigator.clipboard.writeText(values)}catch{
        const ta=document.createElement('textarea');
        ta.value=values; ta.style.position='fixed'; ta.style.opacity='0';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    const b=document.getElementById('copyRecoveryCodes');
    if(b){const old=b.textContent;b.textContent='Copied';setTimeout(()=>b.textContent=old,1200)}
});
</script>
<?php elseif(!empty($me['totp_secret'])): ?>
<div class="disabled-state" style="margin-top:12px">
    <b><?=h((string)$unusedRecoveryCodes)?> unused recovery codes remain.</b>
    <small>Regenerating codes immediately revokes the previous unused set.</small>
</div>
<form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?=csrf()?>">
    <input type="hidden" name="action" value="recovery-generate"><label class="admin-auth-field">Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label class="admin-auth-field">2FA or recovery code<input name="otp" autocomplete="one-time-code" required></label>
    <input type="hidden" name="return" value="admins">
    <button>Generate new recovery codes</button>
</form>
<?php else: ?>
<div class="disabled-state" style="margin-top:12px">
    <b>Recovery codes become available with 2FA.</b>
    <small>When Google Authenticator is enabled, MuchoCore generates a fresh one-time recovery set.</small>
</div>
<?php endif; ?>
<div class="recovery-note">Each code can be used once. Store them offline or in a password manager; never commit them to the repository.</div>
</div>

</div>
