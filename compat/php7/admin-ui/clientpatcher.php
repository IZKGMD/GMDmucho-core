<?php declare(strict_types=1);requirePermission('client.manage'); ?>
<p class="mc-page-lead">Connect a supported Geometry Dash client to your server. Prepare an APK or Windows EXE and download the result.</p>
<div class="mc-two-panel">
<section class="card">
<div class="mc-section-label">Client preparation</div>
<h2>Patch a client</h2>
<form id="sharedPatcher" data-endpoint="<?=h($script)?>" data-csrf="<?=h(csrf())?>">
<div class="mc-form-field mc-file-picker">
<label for="sharedPatcherFile">Client file</label>
<input id="sharedPatcherFile" type="file" accept=".apk,.exe" required aria-describedby="sharedPatcherFileHelp">
<small id="sharedPatcherFileHelp">Original or verified URL-only patched client. APK up to 512 MiB · EXE up to 64 MiB.</small>
</div>
<div class="mc-form-field">
<label for="sharedPatcherServer">Server domain</label>
<input id="sharedPatcherServer" type="url" required value="<?=h(isset($settings['site_url'])?$settings['site_url']:'')?>" placeholder="https://gdps.example.com" aria-describedby="sharedPatcherServerHelp">
<small id="sharedPatcherServerHelp">Use your domain without /mc. The client connects to routes at the domain root.</small>
</div>
<label class="mc-check-line"><input id="sharedPatcherAlongside" type="checkbox" checked><span>Install the APK alongside the original game <small>(GD 2.209 ARM64)</small></span></label>
<div class="mc-form-actions"><button id="sharedPatcherStart" type="submit">Prepare client</button><button id="sharedPatcherReset" type="button" class="gray">Reset job</button></div>
<progress id="sharedPatcherProgress" max="100" value="0" aria-label="Client preparation"></progress>
<p id="sharedPatcherStatus" role="status" aria-live="polite">Choose a file to begin.</p>
<p><a id="sharedPatcherDownload" hidden>Download patched client</a></p>
<pre id="sharedPatcherReport" hidden></pre>
</form>
</section>
<section class="card">
<div class="mc-section-label">Before you start</div>
<h2>A few things to check</h2>
<ol class="mc-help-list">
<li><strong>Choose a supported build</strong>Verified Windows URL-only patches can be retargeted. Other changes to the EXE are rejected.</li>
<li><strong>Keep the game files together</strong>Put the downloaded EXE in the game folder with its matching DLLs and Resources.</li>
<li><strong>Keep your APK signing key</strong>Future APK updates must use the same signing key. Installing alongside creates a separate app.</li>
</ol>
<details class="mc-disclosure"><summary>Compatibility and signing</summary>
<p>APK signing requires PHP OpenSSL, zlib, and 64-bit PHP. Java, shell, ZipArchive, and VPS are not required.</p>
<p>Windows URL-only patches are restored in memory and must match a supported original SHA-256 before retargeting. Changes to game code, imports, resources or other data are rejected.</p>
<p>Back up the private <code>config/php7-apk-signer.json</code> with your backups. A client signed with another key cannot update an existing installation.</p>
<p>The patcher replaces API URLs. Licensing and other client features remain unchanged; patching does not replace Windows runtimes or Steam.</p>
</details>
</section>
</div>
<script src="/assets/php7-shared-patcher.js" defer></script>
