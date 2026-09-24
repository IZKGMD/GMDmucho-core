<?php

declare(strict_types=1);

use MuchoCore\Client\WindowsClientPatcher;

function clientPatcherStorage(string $rootDir): string
{
    $preferred = $rootDir . '/storage/admin-client-patcher';

    if (
        (!is_dir($preferred) && @mkdir($preferred, 0700, true)) ||
        is_dir($preferred)
    ) {
        @chmod($preferred, 0700);
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/muchocore-admin-client-patcher';

    if (
        (!is_dir($fallback) && @mkdir($fallback, 0700, true)) ||
        is_dir($fallback)
    ) {
        @chmod($fallback, 0700);
        return $fallback;
    }

    throw new RuntimeException('Client patcher storage is unavailable on this hosting account.');
}

function clientPatcherJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function cleanupClientPatcherFiles(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $now = time();
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > 3600) {
            @unlink($file);
        }
    }
}

function clientPatcherUploadSession(): ?array
{
    $data = $_SESSION['client_patcher_upload'] ?? null;
    return is_array($data) ? $data : null;
}

function clientPatcherAssertUpload(array $session, string $uploadId): void
{
    if (
        !preg_match('/^[a-f0-9]{32}$/', $uploadId) ||
        !hash_equals(
            (string)($session['id'] ?? ''),
            $uploadId
        )
    ) {
        clientPatcherJson(
            ['ok' => false, 'error' => 'Invalid or expired upload session.'],
            400
        );
    }

    $expires = (int)($session['expires'] ?? 0);
    if ($expires <= time()) {
        clientPatcherJson(
            ['ok' => false, 'error' => 'Upload session expired. Start again.'],
            410
        );
    }
}

function handleClientPatcherAction(
    PDO $db,
    string $rootDir,
    string $action
): never {
    requireRank(30);

    $dir = clientPatcherStorage($rootDir);
    cleanupClientPatcherFiles($dir);

    set_time_limit(0);
    ignore_user_abort(true);

    if ($action === 'client-patcher-start') {
        $filename = trim((string)($_POST['filename'] ?? ''));
        $totalSize = (int)($_POST['total_size'] ?? 0);
        $serverUrl = (string)($_POST['server_url'] ?? '');

        if (
            $filename === '' ||
            strlen($filename) > 180 ||
            !preg_match('/\.exe$/i', $filename)
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Choose a Windows .exe file.'],
                400
            );
        }

        if ($totalSize < 1024 || $totalSize > 512 * 1024 * 1024) {
            clientPatcherJson(
                [
                    'ok' => false,
                    'error' => 'Executable size must be between 1 KB and 512 MB.'
                ],
                400
            );
        }

        try {
            $server = WindowsClientPatcher::validateServerUrl($serverUrl);
        } catch (Throwable $e) {
            clientPatcherJson(
                ['ok' => false, 'error' => $e->getMessage()],
                400
            );
        }

        $id = bin2hex(random_bytes(16));
        $part = $dir . '/' . $id . '.upload';

        if (@file_put_contents($part, '') === false) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Cannot create temporary upload storage.'],
                507
            );
        }

        $_SESSION['client_patcher_upload'] = [
            'id' => $id,
            'filename' => basename($filename),
            'total_size' => $totalSize,
            'server_url' => $server,
            'part_path' => $part,
            'started_at' => time(),
            'expires' => time() + 3600,
        ];

        clientPatcherJson([
            'ok' => true,
            'upload_id' => $id,
            'next_offset' => 0,
            'chunk_size' => 2 * 1024 * 1024,
        ]);
    }

    if ($action === 'client-patcher-chunk') {
        $session = clientPatcherUploadSession();

        if (!$session) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Upload session not found. Start again.'],
                410
            );
        }

        $uploadId = (string)($_POST['upload_id'] ?? '');
        clientPatcherAssertUpload($session, $uploadId);

        $part = (string)($session['part_path'] ?? '');
        if (
            $part === '' ||
            !is_file($part) ||
            !hash_equals(
                $part,
                $dir . '/' . $uploadId . '.upload'
            )
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Upload storage is invalid.'],
                400
            );
        }

        $offset = (int)($_POST['offset'] ?? -1);
        $totalSize = (int)($session['total_size'] ?? 0);
        $currentSize = filesize($part);

        if ($currentSize === false || $offset !== $currentSize) {
            clientPatcherJson([
                'ok' => false,
                'error' => 'Upload offset mismatch.',
                'expected_offset' => $currentSize === false ? 0 : $currentSize,
            ], 409);
        }

        if (
            !isset($_FILES['chunk']) ||
            ($_FILES['chunk']['error'] ?? -1) !== UPLOAD_ERR_OK
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Upload chunk failed.'],
                400
            );
        }

        $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
        $chunkSize = (int)($_FILES['chunk']['size'] ?? 0);

        if (
            !is_uploaded_file($tmp) ||
            $chunkSize <= 0 ||
            $chunkSize > 2 * 1024 * 1024 ||
            $offset + $chunkSize > $totalSize
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Invalid upload chunk.'],
                400
            );
        }

        $in = @fopen($tmp, 'rb');
        $out = @fopen($part, 'ab');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }

            clientPatcherJson(
                ['ok' => false, 'error' => 'Temporary upload storage is unavailable.'],
                507
            );
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied !== $chunkSize) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Failed to append the upload chunk.'],
                500
            );
        }

        $_SESSION['client_patcher_upload']['expires'] = time() + 3600;

        $next = $offset + $chunkSize;

        clientPatcherJson([
            'ok' => true,
            'next_offset' => $next,
            'complete' => $next === $totalSize,
        ]);
    }

    if ($action === 'client-patcher-finish') {
        $session = clientPatcherUploadSession();

        if (!$session) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Upload session not found. Start again.'],
                410
            );
        }

        $uploadId = (string)($_POST['upload_id'] ?? '');
        clientPatcherAssertUpload($session, $uploadId);

        $part = (string)($session['part_path'] ?? '');
        $totalSize = (int)($session['total_size'] ?? 0);

        if (
            $part === '' ||
            !is_file($part) ||
            !hash_equals($part, $dir . '/' . $uploadId . '.upload')
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Uploaded executable is missing.'],
                400
            );
        }

        $actualSize = filesize($part);

        if ($actualSize !== $totalSize) {
            clientPatcherJson([
                'ok' => false,
                'error' => 'Upload is incomplete.',
                'expected_size' => $totalSize,
                'actual_size' => $actualSize === false ? 0 : $actualSize,
            ], 409);
        }

        $output = $dir . '/' . $uploadId . '-MuchoCore.exe';

        try {
            $report = WindowsClientPatcher::patchFile(
                $part,
                $output,
                (string)$session['server_url']
            );
        } catch (Throwable $e) {
            @unlink($output);
            @unlink($part);
            unset($_SESSION['client_patcher_upload']);

            clientPatcherJson(
                ['ok' => false, 'error' => $e->getMessage()],
                422
            );
        }

        @unlink($part);
        unset($_SESSION['client_patcher_upload']);

        $_SESSION['client_patcher_result'] = [
            'id' => $uploadId,
            'output' => $output,
            'server_url' => (string)$report['server_url'],
            'replacement_count' => (int)$report['replacement_count'],
            'output_size' => (int)$report['output_size'],
            'output_sha256' => (string)$report['output_sha256'],
            'expires' => time() + 3600,
        ];

        audit(
            $db,
            'client-patcher.patch',
            $uploadId,
            [
                'server_host' => (string)(parse_url(
                    (string)$report['server_url'],
                    PHP_URL_HOST
                ) ?: ''),
                'input_size' => (int)$report['input_size'],
                'output_size' => (int)$report['output_size'],
                'replacements' => (int)$report['replacement_count'],
            ]
        );

        clientPatcherJson([
            'ok' => true,
            'download_url' => '/admin/?page=clientpatcher&client_download=' . rawurlencode($uploadId),
            'replacement_count' => (int)$report['replacement_count'],
            'output_size' => (int)$report['output_size'],
            'output_sha256' => (string)$report['output_sha256'],
        ]);
    }

    clientPatcherJson(
        ['ok' => false, 'error' => 'Unknown client patcher action.'],
        400
    );
}

function handleClientPatcherDownload(string $rootDir): never
{
    requireRank(30);

    $id = (string)($_GET['client_download'] ?? '');
    $result = $_SESSION['client_patcher_result'] ?? null;

    if (
        !is_array($result) ||
        !preg_match('/^[a-f0-9]{32}$/', $id) ||
        !hash_equals((string)($result['id'] ?? ''), $id) ||
        (int)($result['expires'] ?? 0) <= time()
    ) {
        http_response_code(404);
        exit('Download not found.');
    }

    $file = (string)($result['output'] ?? '');

    if (
        $file === '' ||
        !hash_equals(
            $file,
            clientPatcherStorage($rootDir) . '/' . $id . '-MuchoCore.exe'
        ) ||
        !is_file($file)
    ) {
        http_response_code(404);
        exit('Download not found.');
    }

    $downloadName = 'GeometryDash-MuchoCore.exe';
    $size = filesize($file);

    header('Content-Type: application/vnd.microsoft.portable-executable');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    readfile($file);

    @unlink($file);
    unset($_SESSION['client_patcher_result']);
    exit;
}

function renderClientPatcherPage(PDO $db): void
{
    $result = $_SESSION['client_patcher_result'] ?? null;
    $serverDefault = (string)(
        getenv('MUCHO_ACCOUNT_URL') ?: ''
    );
    ?>
<style>
.mc-patcher{display:grid;gap:14px}
.mc-patcher-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:14px}
.mc-patcher .card{padding:20px}
.mc-patcher h2{margin-top:0}
.mc-patcher h3{margin:0 0 8px}
.mc-patcher .lead{color:#aab5c7;line-height:1.65;font-size:13px}
.mc-patcher-steps{display:grid;gap:9px}
.mc-patcher-step{display:grid;grid-template-columns:34px 1fr;gap:10px;align-items:start;padding:11px 0;border-bottom:1px solid #202836}
.mc-patcher-step:last-child{border-bottom:0}
.mc-patcher-num{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#201b42;border:1px solid #403575;color:#b4aaff;font-weight:900}
.mc-patcher code{padding:2px 5px;border-radius:5px;background:#0a0f17;border:1px solid #273144}
.mc-patcher .warning{padding:12px 13px;border-radius:10px;background:#352916;border:1px solid #624b22;color:#ffd98a;line-height:1.55;font-size:12px}
.mc-patcher .success{padding:12px 13px;border-radius:10px;background:#163527;border:1px solid #2d6046;color:#89edb7;line-height:1.55;font-size:12px}
.mc-patcher-form{display:grid;gap:12px}
.mc-patcher-form label{display:grid;gap:6px;color:#a9b4c5;font-size:11px;font-weight:750}
.mc-patcher-form input[type="text"]{width:100%;min-height:42px}
.mc-drop{border:1px dashed #4a5270;border-radius:14px;padding:28px 18px;text-align:center;background:#0d121b;cursor:pointer;transition:.15s}
.mc-drop:hover,.mc-drop.drag{border-color:#887cff;background:#131a27}
.mc-drop strong{display:block;font-size:15px;color:#eef2ff}
.mc-drop span{display:block;margin-top:6px;color:#758197;font-size:11px}
.mc-file-name{margin-top:10px;color:#b9c3d3;font-size:11px;word-break:break-all}
.mc-progress{height:9px;border-radius:99px;overflow:hidden;background:#252d3c}
.mc-progress i{display:block;height:100%;width:0;background:linear-gradient(90deg,#7764ff,#43d7cf);transition:width .12s}
.mc-status{min-height:20px;color:#8f9bb0;font-size:11px}
.mc-result{display:grid;gap:8px;margin-top:10px}
.mc-result .rowline{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #202836;font-size:11px}
.mc-result .rowline:last-child{border-bottom:0}
@media(max-width:900px){.mc-patcher-grid{grid-template-columns:1fr}}
</style>

<div class="mc-patcher">
    <div class="mc-patcher-grid">
        <section class="card">
            <h2>🌐 Web Client Patcher</h2>
            <p class="lead">
                Patch a Windows Geometry Dash executable directly from the admin panel.
                The patcher runs in PHP, uses small upload chunks, and does not require
                Python, Docker, shell access, or server-side executable tools.
            </p>

            <div class="mc-patcher-steps">
                <div class="mc-patcher-step">
                    <div class="mc-patcher-num">1</div>
                    <div>
                        <h3>Enter your GDPS URL</h3>
                        <div class="muted small">
                            Use the public server root only, for example
                            <code>https://gdps.example.com</code>.
                            Do not add <code>/database</code>.
                        </div>
                    </div>
                </div>

                <div class="mc-patcher-step">
                    <div class="mc-patcher-num">2</div>
                    <div>
                        <h3>Upload the original EXE</h3>
                        <div class="muted small">
                            Select your original Windows
                            <code>GeometryDash.exe</code>.
                            Maximum file size: <b>512 MB</b>.
                        </div>
                    </div>
                </div>

                <div class="mc-patcher-step">
                    <div class="mc-patcher-num">3</div>
                    <div>
                        <h3>Patch the client</h3>
                        <div class="muted small">
                            The server uploads the file in <b>2 MB chunks</b> and patches
                            known Geometry Dash server URL layouts without loading the
                            entire executable into PHP memory.
                        </div>
                    </div>
                </div>

                <div class="mc-patcher-step">
                    <div class="mc-patcher-num">4</div>
                    <div>
                        <h3>Download your client</h3>
                        <div class="muted small">
                            Download <code>GeometryDash-MuchoCore.exe</code> and keep your
                            original executable unchanged.
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>🛡️ Shared-hosting friendly</h2>

            <div class="success">
                No Python, Composer package, Docker, <code>exec()</code>,
                <code>shell_exec()</code>, or external binary is required for patching.
            </div>

            <div style="margin-top:12px" class="muted small">
                Temporary files are kept in a private server directory and expire
                automatically. The generated client is streamed to the browser.
            </div>

            <div style="margin-top:16px" class="warning">
                <b>Important:</b> the patcher only changes supported server URL patterns.
                It does not change game data or your GDPS database. Because the EXE is
                modified, any original Windows digital signature will no longer represent
                the patched file.
            </div>

            <div style="margin-top:16px" class="muted small">
                Make sure the hosting account has enough free disk space for the uploaded
                executable and a temporary output copy.
            </div>
        </section>
    </div>

    <section class="card">
        <h2>⚡ Patch your client</h2>

        <form id="mcClientPatcherForm" class="mc-patcher-form">
            <label>
                Your GDPS server URL
                <input
                    id="mcPatcherServer"
                    type="text"
                    value="<?=h($serverDefault)?>"
                    placeholder="https://gdps.example.com"
                    autocomplete="url"
                    spellcheck="false"
                    required
                >
            </label>

            <label for="mcPatcherFile" class="mc-drop" id="mcPatcherDrop">
                <strong>📦 Drop GeometryDash.exe here</strong>
                <span>or tap to choose a Windows .exe file</span>
                <div class="mc-file-name" id="mcPatcherFileName">No file selected</div>
                <input
                    id="mcPatcherFile"
                    type="file"
                    accept=".exe,application/vnd.microsoft.portable-executable"
                    style="display:none"
                    required
                >
            </label>

            <div class="mc-progress"><i id="mcPatcherProgress"></i></div>
            <div class="mc-status" id="mcPatcherStatus"></div>
            <div id="mcPatcherResult"></div>

            <button type="submit" id="mcPatcherButton">
                🚀 Upload &amp; Patch Client
            </button>
        </form>
    </section>
</div>

<script>
(() => {
    const form = document.getElementById('mcClientPatcherForm');
    const server = document.getElementById('mcPatcherServer');
    const fileInput = document.getElementById('mcPatcherFile');
    const drop = document.getElementById('mcPatcherDrop');
    const fileName = document.getElementById('mcPatcherFileName');
    const progress = document.getElementById('mcPatcherProgress');
    const status = document.getElementById('mcPatcherStatus');
    const result = document.getElementById('mcPatcherResult');
    const button = document.getElementById('mcPatcherButton');
    const csrf = <?=json_encode(csrf(), JSON_UNESCAPED_SLASHES)?>;
    const CHUNK_SIZE = 2 * 1024 * 1024;

    let selected = null;

    function setStatus(text) {
        status.textContent = text || '';
    }

    function setProgress(percent) {
        progress.style.width = Math.max(0, Math.min(100, percent)) + '%';
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'",'&#039;');
    }

    function showResult(data) {
        result.innerHTML = '<div class="success mc-result">' +
            '<div><b>✅ Patch completed.</b></div>' +
            '<div class="rowline"><span>Replacements</span><b>' +
                escapeHtml(data.replacement_count) +
            '</b></div>' +
            '<div class="rowline"><span>Output size</span><b>' +
                escapeHtml((Number(data.output_size) / 1048576).toFixed(2)) +
                ' MB</b></div>' +
            '<div class="rowline"><span>SHA-256</span><code style="word-break:break-all">' +
                escapeHtml(data.output_sha256) +
            '</code></div>' +
            '<a class="btn" href="' + escapeHtml(data.download_url) + '">' +
                '⬇️ Download GeometryDash-MuchoCore.exe' +
            '</a>' +
        '</div>';
    }

    fileInput.addEventListener('change', () => {
        selected = fileInput.files?.[0] || null;
        fileName.textContent = selected
            ? selected.name + ' · ' + (selected.size / 1048576).toFixed(2) + ' MB'
            : 'No file selected';
    });

    drop.addEventListener('dragover', e => {
        e.preventDefault();
        drop.classList.add('drag');
    });

    drop.addEventListener('dragleave', () => {
        drop.classList.remove('drag');
    });

    drop.addEventListener('drop', e => {
        e.preventDefault();
        drop.classList.remove('drag');

        const file = e.dataTransfer.files?.[0];
        if (!file) return;

        const transfer = new DataTransfer();
        transfer.items.add(file);
        fileInput.files = transfer.files;
        selected = file;
        fileName.textContent =
            file.name + ' · ' + (file.size / 1048576).toFixed(2) + ' MB';
    });

    async function api(action, fields = {}, blob = null) {
        const body = new FormData();
        body.append('csrf', csrf);
        body.append('action', action);

        Object.entries(fields).forEach(([key, value]) => {
            body.append(key, String(value));
        });

        if (blob) {
            body.append('chunk', blob, 'chunk.bin');
        }

        const response = await fetch('/admin/?page=clientpatcher', {
            method: 'POST',
            credentials: 'same-origin',
            body
        });

        const data = await response.json().catch(() => ({
            ok:false,
            error:'Invalid server response.'
        }));

        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Request failed.');
        }

        return data;
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        result.innerHTML = '';
        setProgress(0);

        if (!selected) {
            setStatus('Choose GeometryDash.exe first.');
            return;
        }

        if (!/\.exe$/i.test(selected.name)) {
            setStatus('Please select a Windows .exe file.');
            return;
        }

        if (selected.size > 512 * 1024 * 1024) {
            setStatus('Maximum supported executable size is 512 MB.');
            return;
        }

        button.disabled = true;

        try {
            setStatus('Creating upload session…');

            const start = await api(
                'client-patcher-start',
                {
                    filename: selected.name,
                    total_size: selected.size,
                    server_url: server.value.trim()
                }
            );

            let offset = Number(start.next_offset || 0);
            const uploadId = start.upload_id;

            while (offset < selected.size) {
                const end = Math.min(offset + CHUNK_SIZE, selected.size);
                const chunk = selected.slice(offset, end);

                setStatus(
                    'Uploading ' +
                    (offset / 1048576).toFixed(1) +
                    ' / ' +
                    (selected.size / 1048576).toFixed(1) +
                    ' MB…'
                );

                const data = await api(
                    'client-patcher-chunk',
                    {
                        upload_id: uploadId,
                        offset
                    },
                    chunk
                );

                offset = Number(data.next_offset || end);
                setProgress((offset / selected.size) * 90);
            }

            setStatus('Patching executable…');

            const finished = await api(
                'client-patcher-finish',
                {upload_id: uploadId}
            );

            setProgress(100);
            setStatus('Patch complete. Your download is ready.');
            showResult(finished);
        } catch (error) {
            setStatus(error?.message || 'Patch failed.');
            setProgress(0);
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
    <?php
}
