<?php declare(strict_types=1);requirePermission('client.manage'); ?>
<section class="card">
<h2>Client patcher</h2>
<p>Upload an original Geometry Dash client or a verified URL-only patched Windows EXE, enter your server domain, and download the patched client. APK signing runs on this host; the input file is preserved.</p>
<form id="sharedPatcher" data-endpoint="<?=h($script)?>" data-csrf="<?=h(csrf())?>">
<p><label for="sharedPatcherFile">Original APK or EXE</label><br><input id="sharedPatcherFile" type="file" accept=".apk,.exe" required></p>
<p><label for="sharedPatcherServer">Server domain</label><br><input id="sharedPatcherServer" type="url" required value="<?=h(isset($settings['site_url'])?$settings['site_url']:'')?>" placeholder="https://gdps.example.com"></p>
<p><label><input id="sharedPatcherAlongside" type="checkbox" checked> Install the APK alongside the original game (GD 2.209 ARM64)</label></p>
<p><button id="sharedPatcherStart" type="submit">Prepare client</button> <button id="sharedPatcherReset" type="button" class="gray">Reset job</button></p>
<progress id="sharedPatcherProgress" max="100" value="0" aria-label="Client preparation"></progress>
<p id="sharedPatcherStatus" role="status" aria-live="polite">Choose a file to begin.</p>
<p><a id="sharedPatcherDownload" hidden>Download patched client</a></p>
<pre id="sharedPatcherReport" hidden></pre>
</form>
<details><summary>Compatibility and updates</summary>
<p>APK up to 512 MiB and EXE up to 64 MiB. APK signing requires PHP OpenSSL, zlib, and 64-bit PHP; Java, shell, ZipArchive, and VPS are not required. Windows URL-only patches are restored in memory and must match a supported original SHA-256 before retargeting. Changes to game code, imports, resources or other data are rejected. Keep the game's matching DLLs and Resources folder; patching does not replace Windows runtimes or Steam.</p>
<p>Future APK builds use the same signing key. Back up the private <code>config/php7-apk-signer.json</code> with your backups. A client signed with another key cannot update an existing installation. Installing alongside creates a separate app.</p>
<p>The patcher replaces API URLs; licensing and other client features remain unchanged. Do not add <code>/mc</code> to the domain: the client uses routes at the domain root.</p>
</details>
</section>
<script src="/assets/php7-shared-patcher.js" defer></script>
