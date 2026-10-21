<?php

declare(strict_types=1);

use MuchoCore\Client\AndroidClientPatcher;
use MuchoCore\Client\ParallelApkUpload;

function handleAndroidPatcherAction(
    PDO $db,
    string $rootDir,
    string $action
): never {
    requireRank(30);

    $dir = clientPatcherStorage($rootDir);
    cleanupClientPatcherFiles($dir);

    set_time_limit(0);
    ignore_user_abort(true);

    if ($action === 'android-patcher-start') {
        $filename = trim((string)($_POST['filename'] ?? ''));
        $totalSize = (int)($_POST['total_size'] ?? 0);
        $serverUrl = (string)($_POST['server_url'] ?? '');
        $installAlongside = (string)($_POST['install_alongside'] ?? '1') === '1';

        if (
            $filename === '' ||
            strlen($filename) > 180 ||
            !preg_match('/\.apk$/i', $filename)
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Choose an Android .apk file.'],
                400
            );
        }

        if ($totalSize < 1024 || $totalSize > 512 * 1024 * 1024) {
            clientPatcherJson(
                [
                    'ok' => false,
                    'error' => 'APK size must be between 1 KB and 512 MB.'
                ],
                400
            );
        }

        try {
            $server = \MuchoCore\Client\WindowsClientPatcher::validateServerUrl($serverUrl);
        } catch (Throwable $e) {
            clientPatcherJson(
                ['ok' => false, 'error' => $e->getMessage()],
                400
            );
        }

        $id = bin2hex(random_bytes(16));
        $part = $dir . '/' . $id . '.apk.upload';
        // Parallel uploads are explicitly negotiated; older browsers still
        // use the unchanged sequential protocol.
        $parallel = (string)($_POST['supports_parallel'] ?? '') === '1';
        try {
            $partBytes = $parallel ? ParallelApkUpload::recommendedPartBytes() : 8 * 1024 * 1024;
            if ($parallel) {
                ParallelApkUpload::prepare($part, $totalSize);
            } elseif (@file_put_contents($part, '') === false) {
                throw new RuntimeException('Cannot create temporary APK storage.');
            }
        } catch (Throwable $error) {
            clientPatcherJson(['ok' => false, 'error' => $error->getMessage()], 507);
        }

        $_SESSION['android_patcher_upload'] = [
            'id' => $id,
            'filename' => basename($filename),
            'total_size' => $totalSize,
            'server_url' => $server,
            'install_alongside' => $installAlongside,
            'part_path' => $part,
            'upload_mode' => $parallel ? 'parallel-v1' : 'sequential',
            'part_size' => $partBytes,
            'started_at' => time(),
            'expires' => time() + 4 * 3600,
        ];

        clientPatcherJson([
            'ok' => true,
            'upload_id' => $id,
            'next_offset' => 0,
            'chunk_size' => $partBytes,
            'upload_mode' => $parallel ? 'parallel-v1' : 'sequential',
            'max_parallel' => $parallel ? 3 : 1,
        ]);
    }

    // Let a reconnecting browser verify committed bytes before retrying.
    // This also recovers from a lost HTTP response after a successful write.
    if ($action === 'android-patcher-status') {
        $session = $_SESSION['android_patcher_upload'] ?? null;
        $id = (string)($_POST['upload_id'] ?? '');
        if (!is_array($session) ||
            !preg_match('/^[a-f0-9]{32}$/', $id) ||
            !hash_equals((string)($session['id'] ?? ''), $id) ||
            (int)($session['expires'] ?? 0) <= time()) {
            clientPatcherJson(['ok' => false, 'error' => 'Upload session expired.'], 410);
        }
        $part = $dir . '/' . $id . '.apk.upload';
        if (!hash_equals((string)($session['part_path'] ?? ''), $part) ||
            !is_file($part)) {
            clientPatcherJson(['ok' => false, 'error' => 'Upload not found.'], 404);
        }
        if (($session['upload_mode'] ?? '') === 'parallel-v1') {
            $size = (int)$session['total_size'];
            $partSize = (int)$session['part_size'];
            $done = ParallelApkUpload::completed($part, $size, $partSize);
            clientPatcherJson([
                'ok' => true,
                'upload_mode' => 'parallel-v1',
                'completed_parts' => $done,
            ]);
        }
        clearstatcache(true, $part);
        $offset = filesize($part);
        if ($offset === false || $offset > (int)$session['total_size']) {
            clientPatcherJson(['ok' => false, 'error' => 'Invalid upload state.'], 409);
        }
        clientPatcherJson(['ok' => true, 'next_offset' => $offset]);
    }

    if ($action === 'android-patcher-part') {
        $session = $_SESSION['android_patcher_upload'] ?? null;
        $uploadId = (string)($_POST['upload_id'] ?? '');
        if (!is_array($session) || !preg_match('/^[a-f0-9]{32}$/', $uploadId) ||
            !hash_equals((string)($session['id'] ?? ''), $uploadId) ||
            ($session['upload_mode'] ?? '') !== 'parallel-v1' ||
            (int)($session['expires'] ?? 0) <= time()) {
            clientPatcherJson(['ok' => false, 'error' => 'Invalid or expired APK upload session.'], 410);
        }
        $part = $dir . '/' . $uploadId . '.apk.upload';
        if (!hash_equals((string)($session['part_path'] ?? ''), $part) || !is_file($part)) {
            clientPatcherJson(['ok' => false, 'error' => 'APK upload storage unavailable.'], 404);
        }
        $indexString = (string)($_POST['part_index'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]{0,3})$/D', $indexString)) {
            clientPatcherJson(['ok' => false, 'error' => 'Invalid APK part index.'], 400);
        }
        $index = (int)$indexString;
        $totalSize = (int)$session['total_size'];
        $partSize = (int)$session['part_size'];
        if (!isset($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? -1) !== UPLOAD_ERR_OK) {
            clientPatcherJson(['ok' => false, 'error' => 'APK part upload rejected by PHP or reverse proxy.'], 400);
        }
        $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
        $inputSize = (int)($_FILES['chunk']['size'] ?? 0);
        if (!is_uploaded_file($tmp) || $inputSize <= 0 ||
            $inputSize > ParallelApkUpload::MAX_PART_BYTES) {
            clientPatcherJson(['ok' => false, 'error' => 'Invalid APK upload data.'], 400);
        }

        // Release the PHP session lock before slow disk writes. Otherwise
        // concurrent browser requests for this same admin are serialized.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            $new = ParallelApkUpload::store(
                $part, $totalSize, $partSize, $index, $tmp, $inputSize
            );
            clientPatcherJson(['ok' => true, 'part_index' => $index, 'new' => $new]);
        } catch (Throwable $error) {
            clientPatcherJson(['ok' => false, 'error' => $error->getMessage()], 409);
        }
    }

    if ($action === 'android-patcher-chunk') {
        $session = $_SESSION['android_patcher_upload'] ?? null;
        if (!is_array($session) || ($session['upload_mode'] ?? 'sequential') !== 'sequential') {
            clientPatcherJson(
                ['ok' => false, 'error' => 'APK upload session not found. Start again.'],
                410
            );
        }

        $uploadId = (string)($_POST['upload_id'] ?? '');
        if (
            !preg_match('/^[a-f0-9]{32}$/', $uploadId) ||
            !hash_equals((string)($session['id'] ?? ''), $uploadId)
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Invalid or expired APK upload session.'],
                400
            );
        }

        if ((int)($session['expires'] ?? 0) <= time()) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'APK upload session expired. Start again.'],
                410
            );
        }

        $part = (string)($session['part_path'] ?? '');
        if (
            $part === '' ||
            !is_file($part) ||
            !hash_equals($part, $dir . '/' . $uploadId . '.apk.upload')
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'APK upload storage is invalid.'],
                400
            );
        }

        $offset = (int)($_POST['offset'] ?? -1);
        $totalSize = (int)($session['total_size'] ?? 0);
        $currentSize = filesize($part);

        if ($currentSize === false || $offset !== $currentSize) {
            clientPatcherJson([
                'ok' => false,
                'error' => 'APK upload offset mismatch.',
                'expected_offset' => $currentSize === false ? 0 : $currentSize,
            ], 409);
        }

        if (
            !isset($_FILES['chunk']) ||
            ($_FILES['chunk']['error'] ?? -1) !== UPLOAD_ERR_OK
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'APK upload chunk failed.'],
                400
            );
        }

        $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
        $chunkSize = (int)($_FILES['chunk']['size'] ?? 0);

        if (
            !is_uploaded_file($tmp) ||
            $chunkSize <= 0 ||
            $chunkSize > 8 * 1024 * 1024 ||
            $offset + $chunkSize > $totalSize
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Invalid APK upload chunk.'],
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
                ['ok' => false, 'error' => 'Temporary APK storage is unavailable.'],
                507
            );
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied !== $chunkSize) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Failed to store the complete APK chunk.'],
                500
            );
        }

        clearstatcache(true, $part);
        $next = filesize($part);
        if ($next === false) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Cannot determine APK upload size.'],
                500
            );
        }

        $_SESSION['android_patcher_upload']['expires'] = time() + 3600;
        clientPatcherJson([
            'ok' => true,
            'next_offset' => $next,
            'complete' => $next === $totalSize,
        ]);
    }

    if ($action === 'android-patcher-finish') {
        $session = $_SESSION['android_patcher_upload'] ?? null;
        if (!is_array($session)) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'APK upload session not found.'],
                410
            );
        }

        $uploadId = (string)($_POST['upload_id'] ?? '');
        if (
            !preg_match('/^[a-f0-9]{32}$/', $uploadId) ||
            !hash_equals((string)($session['id'] ?? ''), $uploadId)
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'Invalid APK upload session.'],
                400
            );
        }

        $part = (string)($session['part_path'] ?? '');
        $totalSize = (int)($session['total_size'] ?? 0);

        $parallel = ($session['upload_mode'] ?? '') === 'parallel-v1';
        if (
            !is_file($part) ||
            ($parallel
                ? !ParallelApkUpload::isComplete(
                    $part, $totalSize, (int)$session['part_size']
                )
                : filesize($part) !== $totalSize)
        ) {
            clientPatcherJson(
                ['ok' => false, 'error' => 'APK upload is incomplete.'],
                409
            );
        }

        $output = $dir . '/' . bin2hex(random_bytes(16)) . '.apk';

        try {
            $report = AndroidClientPatcher::patchFile(
                $part,
                $output,
                (string)$session['server_url'],
                null,
                (bool)($session['install_alongside'] ?? true)
            );
        } catch (Throwable $e) {
            @unlink($part);
            if ($parallel) {
                ParallelApkUpload::cleanup($part, $totalSize, (int)$session['part_size']);
            }
            unset($_SESSION['android_patcher_upload']);

            audit(
                $db,
                'android-client-patcher.failed',
                null,
                ['error' => $e->getMessage()]
            );

            clientPatcherJson(
                ['ok' => false, 'error' => $e->getMessage()],
                422
            );
        }

        @unlink($part);
        if ($parallel) {
            ParallelApkUpload::cleanup($part, $totalSize, (int)$session['part_size']);
        }
        unset($_SESSION['android_patcher_upload']);

        $_SESSION['android_patcher_result'] = [
            'id' => basename($output, '.apk'),
            'output' => $output,
            'server_url' => $report['server_url'],
            'replacement_count' => $report['replacement_count'],
            'patched_entries' => count($report['patched_entries']),
            'input_size' => $report['input_size'],
            'output_size' => $report['output_size'],
            'input_sha256' => $report['input_sha256'],
            'output_sha256' => $report['output_sha256'],
            'package_id' => (string)($report['package_id'] ?? ''),
            'source_build' => (string)($report['source_build'] ?? 'Legacy Android GD'),
            'expires' => time() + 900,
        ];

        audit(
            $db,
            'android-client-patcher.patch',
            (string)$report['server_url'],
            [
                'replacement_count' => $report['replacement_count'],
                'patched_entries' => count($report['patched_entries']),
                'input_size' => $report['input_size'],
                'output_size' => $report['output_size'],
                'input_sha256' => $report['input_sha256'],
                'output_sha256' => $report['output_sha256'],
                'signed' => true,
            ]
        );

        clientPatcherJson([
            'ok' => true,
            'server_url' => $report['server_url'],
            'replacement_count' => $report['replacement_count'],
            'patched_entries' => count($report['patched_entries']),
            'input_size' => $report['input_size'],
            'output_size' => $report['output_size'],
            'output_sha256' => $report['output_sha256'],
            'signed' => true,
            'package_id' => (string)($report['package_id'] ?? ''),
            'source_build' => (string)($report['source_build'] ?? 'Legacy Android GD'),
            'download_url' =>
                '/admin/?android_download=' .
                rawurlencode(basename($output, '.apk')),
        ]);
    }

    clientPatcherJson(
        ['ok' => false, 'error' => 'Unknown Android patcher action.'],
        400
    );
}

function handleAndroidPatcherDownload(string $rootDir): never
{
    requireRank(30);

    $id = strtolower(trim((string)($_GET['android_download'] ?? '')));

    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        http_response_code(400);
        exit('Invalid download.');
    }

    $result = $_SESSION['android_patcher_result'] ?? null;

    if (!is_array($result)) {
        http_response_code(404);
        exit('Download not found.');
    }

    $storedId = strtolower((string)($result['id'] ?? ''));

    if (
        $storedId === '' ||
        !hash_equals($storedId, $id)
    ) {
        http_response_code(404);
        exit('Download not found.');
    }

    if ((int)($result['expires'] ?? 0) <= time()) {
        $expired = (string)($result['output'] ?? '');
        if ($expired !== '' && is_file($expired)) {
            @unlink($expired);
        }

        unset($_SESSION['android_patcher_result']);
        http_response_code(410);
        exit('Download expired.');
    }

    $file = (string)($result['output'] ?? '');
    $storage = clientPatcherStorage($rootDir);

    if ($file === '' || !is_file($file)) {
        unset($_SESSION['android_patcher_result']);
        http_response_code(404);
        exit('Patched APK no longer exists.');
    }

    $realFile = realpath($file);
    $realStorage = realpath($storage);

    if (
        $realFile === false ||
        $realStorage === false ||
        !str_starts_with(
            $realFile,
            rtrim($realStorage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        )
    ) {
        unset($_SESSION['android_patcher_result']);
        http_response_code(404);
        exit('Download path is invalid.');
    }

    $size = filesize($realFile);

    if ($size === false || $size < 1024) {
        @unlink($realFile);
        unset($_SESSION['android_patcher_result']);
        http_response_code(410);
        exit('Patched APK is unavailable.');
    }

    if (
        !is_readable($realFile) ||
        !is_file($realFile)
    ) {
        http_response_code(404);
        exit('Patched APK is not readable.');
    }

    audit(
        $GLOBALS['db'],
        'android-client-patcher.download',
        basename($realFile)
    );

    header('Content-Type: application/vnd.android.package-archive');
    header(
        'Content-Disposition: attachment; filename="GeometryDash-MuchoCore.apk"'
    );
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $sent = readfile($realFile);

    if ($sent === false) {
        http_response_code(500);
        exit('Unable to read patched APK.');
    }

    @unlink($realFile);
    unset($_SESSION['android_patcher_result']);
    exit;
}


function renderAndroidPatcherSection(PDO $db): void
{
    ?>
<section class="card" style="margin-top:14px">
    <h2>🤖 Android APK Patcher</h2>
    <p class="lead" style="color:#aab5c7;line-height:1.65;font-size:13px">
        Patch a Geometry Dash Android APK from the same admin page. The patcher searches
        native libraries and common client resource files for supported Geometry Dash server
        URL layouts and rebuilds the APK as a new archive.
    </p>

    <div class="warning" style="margin:12px 0">
        <b>Important:</b> the patched APK is signed with a separate MuchoCore key.
        For the verified GD 2.209 ARM64 build, side-by-side installation also changes the
        application ID to <code>com.muchogd.geometryjump</code>. Your official Geometry Dash
        installation stays installed; the two apps have separate data and saves.
    </div>

    <div class="mc-patcher-steps">
        <div class="mc-patcher-step">
            <div class="mc-patcher-num">1</div>
            <div>
                <h3>Enter your GDPS URL</h3>
                <div class="muted small">
                    Use the public server root only, for example
                    <code>https://gdps.example.com</code>.
                </div>
            </div>
        </div>
        <div class="mc-patcher-step">
            <div class="mc-patcher-num">2</div>
            <div>
                <h3>Upload your APK</h3>
                <div class="muted small">
                    Original Geometry Dash APK, up to <b>512 MB</b>.
                </div>
            </div>
        </div>
        <div class="mc-patcher-step">
            <div class="mc-patcher-num">3</div>
            <div>
                <h3>Patch & rebuild</h3>
                <div class="muted small">
                    Uploads use <b>adaptive 512 KiB–8 MiB chunks</b> to reduce request overhead on mobile connections.
                </div>
            </div>
        </div>
    </div>

    <form id="mcAndroidPatcherForm" class="mc-patcher-form" style="margin-top:16px">
        <label>
            Your GDPS server URL
            <input
                id="mcAndroidPatcherServer"
                type="text"
                value="<?=h((string)(getenv('MUCHO_ACCOUNT_URL') ?: ''))?>"
                placeholder="https://gdps.example.com"
                autocomplete="url"
                spellcheck="false"
                required
            >
        </label>

        <label style="display:flex;align-items:center;gap:10px;font-size:13px">
            <input type="checkbox" id="mcAndroidPatcherAlongside" checked
                style="width:18px;height:18px;flex-shrink:0">
            Install alongside the original Geometry Dash (GD 2.209 ARM64)
        </label>

        <label for="mcAndroidPatcherFile" class="mc-drop" id="mcAndroidPatcherDrop">
            <strong>📱 Drop your Geometry Dash .apk here</strong>
            <span>or tap to choose an Android APK</span>
            <div class="mc-file-name" id="mcAndroidPatcherFileName">No file selected</div>
            <input
                id="mcAndroidPatcherFile"
                type="file"
                accept=".apk,application/vnd.android.package-archive"
                style="display:none"
                required
            >
        </label>

        <div class="mc-progress"><i id="mcAndroidPatcherProgress"></i></div>
        <div class="mc-status" id="mcAndroidPatcherStatus"></div>
        <div id="mcAndroidPatcherResult"></div>

        <button type="submit" id="mcAndroidPatcherButton">
            🤖 Upload &amp; Patch APK
        </button>
    </form>
</section>

<script>
(() => {
    const form = document.getElementById('mcAndroidPatcherForm');
    const server = document.getElementById('mcAndroidPatcherServer');
    const fileInput = document.getElementById('mcAndroidPatcherFile');
    const alongside = document.getElementById('mcAndroidPatcherAlongside');
    const drop = document.getElementById('mcAndroidPatcherDrop');
    const fileName = document.getElementById('mcAndroidPatcherFileName');
    const progress = document.getElementById('mcAndroidPatcherProgress');
    const status = document.getElementById('mcAndroidPatcherStatus');
    const result = document.getElementById('mcAndroidPatcherResult');
    const button = document.getElementById('mcAndroidPatcherButton');
    const csrf = <?=json_encode(csrf(), JSON_UNESCAPED_SLASHES)?>;
    const MIN_CHUNK_SIZE = 512 * 1024;
    const MAX_CHUNK_SIZE = 8 * 1024 * 1024;
    let selected = null;

    const escapeHtml = value => String(value)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'",'&#039;');

    const setStatus = text => {
        status.textContent = text || '';
    };

    const setProgress = percent => {
        progress.style.width =
            Math.max(0, Math.min(100, percent)) + '%';
    };

    fileInput.addEventListener('change', () => {
        selected = fileInput.files?.[0] || null;
        fileName.textContent = selected
            ? selected.name + ' · ' + (selected.size / 1048576).toFixed(2) + ' MB'
            : 'No file selected';
    });

    drop.addEventListener('dragover', event => {
        event.preventDefault();
        drop.classList.add('drag');
    });

    drop.addEventListener('dragleave', () => {
        drop.classList.remove('drag');
    });

    drop.addEventListener('drop', event => {
        event.preventDefault();
        drop.classList.remove('drag');

        const file = event.dataTransfer.files?.[0];
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
            ok: false,
            error: 'Invalid server response.'
        }));

        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Request failed.');
        }

        return data;
    }

    // Three HTTPS requests can move independent ranges simultaneously.
    // Older MuchoCore servers return no upload_mode: use the original
    // sequential uploader below without attempting unsupported actions.
    async function uploadParallelParts(file, start, maxPartSize) {
        const partCount = Math.ceil(file.size / maxPartSize);
        const concurrent = Math.max(1, Math.min(3, Number(start.max_parallel) || 1));
        let nextPart = 0;
        let committedBytes = 0;
        let stopped = false;
        const committed = new Set();
        const uploadStarted = Date.now();

        const record = index => {
            if (committed.has(index)) return;
            committed.add(index);
            const length = Math.min(maxPartSize, file.size - index * maxPartSize);
            committedBytes += length;
            setProgress((committedBytes / file.size) * 85);
            const elapsed = Math.max(0.1, (Date.now() - uploadStarted) / 1000);
            const speed = committedBytes / elapsed / 1048576;
            const eta = speed > 0 ? Math.ceil((file.size - committedBytes) /
                (speed * 1048576)) : 0;
            setStatus('Uploaded ' + (committedBytes / 1048576).toFixed(1) +
                ' / ' + (file.size / 1048576).toFixed(1) +
                ' MiB · ' + speed.toFixed(2) + ' MiB/s · ' +
                (eta < 60 ? eta + 's' : Math.ceil(eta / 60) + 'min') +
                ' left · ' + concurrent + ' streams');
        };

        async function worker() {
            while (!stopped) {
                const index = nextPart++;
                if (index >= partCount) return;
                const begin = index * maxPartSize;
                const end = Math.min(begin + maxPartSize, file.size);
                const blob = file.slice(begin, end);
                let acknowledged = false;
                let lastError = null;

                for (let attempt = 1; attempt <= 3 && !acknowledged; attempt++) {
                    if (stopped) return;
                    try {
                        const answer = await api('android-patcher-part', {
                            upload_id: start.upload_id,
                            part_index: index
                        }, blob);
                        if (Number(answer.part_index) !== index) {
                            throw new Error('Invalid APK part acknowledgement.');
                        }
                        acknowledged = true;
                    } catch (error) {
                        lastError = error;
                        // A missing HTTP acknowledgement is not evidence that
                        // the server lost data: ask for its committed parts.
                        try {
                            const checkpoint = await api('android-patcher-status', {
                                upload_id: start.upload_id
                            });
                            if (checkpoint.upload_mode !== 'parallel-v1' ||
                                !Array.isArray(checkpoint.completed_parts)) {
                                throw new Error('Unsupported APK upload status response.');
                            }
                            acknowledged = checkpoint.completed_parts.includes(index);
                        } catch (checkError) {
                            if (/Unsupported APK upload status/.test(checkError.message || '')) {
                                throw checkError;
                            }
                        }
                        if (!acknowledged && attempt < 3) {
                            setStatus('Connection interrupted. Retrying APK part ' +
                                (index + 1) + '/' + partCount + '…');
                            await new Promise(resolve => setTimeout(resolve, 500 * attempt));
                        }
                    }
                }
                if (!acknowledged) {
                    stopped = true;
                    throw lastError || new Error('APK upload failed for part ' + (index + 1));
                }
                record(index);
            }
        }

        const workers = await Promise.allSettled(
            Array.from({length: Math.min(concurrent, partCount)}, () => worker())
        );
        const failure = workers.find(entry => entry.status === 'rejected');
        if (failure) throw failure.reason;
        if (committed.size !== partCount) {
            throw new Error('APK upload did not confirm every part.');
        }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        result.innerHTML = '';
        setProgress(0);

        if (!selected) {
            setStatus('Choose an APK first.');
            return;
        }

        if (!/\.apk$/i.test(selected.name)) {
            setStatus('Please select an Android .apk file.');
            return;
        }

        if (selected.size > 512 * 1024 * 1024) {
            setStatus('Maximum supported APK size is 512 MB.');
            return;
        }

        button.disabled = true;

        try {
            setStatus('Creating APK upload session…');

            const start = await api(
                'android-patcher-start',
                {
                    filename: selected.name,
                    total_size: selected.size,
                    server_url: server.value.trim(),
                    install_alongside: alongside.checked ? '1' : '0',
                    supports_parallel: '1'
                }
            );

            let offset = Number(start.next_offset || 0);
            const uploadId = start.upload_id;
            // Negotiate size with the backend: old deployments may cap 512 KiB.
            const serverChunkSize = Number(start.chunk_size) || MIN_CHUNK_SIZE;
            const maxChunkSize = Math.min(MAX_CHUNK_SIZE,
                Math.max(MIN_CHUNK_SIZE, serverChunkSize));
            let chunkSize = Math.min(maxChunkSize, 2 * 1024 * 1024);
            const uploadStarted = Date.now();

            if (start.upload_mode === 'parallel-v1') {
                await uploadParallelParts(selected, start, maxChunkSize);
            } else {
            while (offset < selected.size) {
                const end = Math.min(offset + chunkSize, selected.size);
                const chunkStarted = Date.now();
                const chunk = selected.slice(offset, end);

                setStatus(
                    'Uploading ' +
                    (offset / 1048576).toFixed(1) +
                    ' / ' +
                    (selected.size / 1048576).toFixed(1) +
                    ' MB…'
                );

                let data = null;
                let lastError = null;

                for (let attempt = 1; attempt <= 3; attempt++) {
                    try {
                        data = await api(
                            'android-patcher-chunk',
                            { upload_id: uploadId, offset },
                            chunk
                        );
                        break;
                    } catch (error) {
                        lastError = error;
                        // A lost acknowledgement does not imply lost bytes.
                        // Check the committed offset before re-uploading.
                        try {
                            const checkpoint = await api(
                                'android-patcher-status',
                                { upload_id: uploadId }
                            );
                            const confirmed = Number(checkpoint.next_offset);
                            if (!Number.isSafeInteger(confirmed) ||
                                confirmed < offset || confirmed > end) {
                                throw new Error('Unexpected upload offset. Refresh and try again.');
                            }
                            if (confirmed > offset) {
                                data = { next_offset: confirmed };
                                break;
                            }
                        } catch (checkError) {
                            if (/Unexpected upload offset/.test(checkError.message || '')) {
                                throw checkError;
                            }
                        }
                        if (attempt < 3) {
                            setStatus('Network interrupted. Retrying upload ' + attempt + '/2…');
                            await new Promise(resolve =>
                                setTimeout(resolve, 700 * attempt)
                            );
                        }
                    }
                }

                if (!data) {
                    throw lastError || new Error('APK upload chunk failed.');
                }

                const next = Number(data.next_offset);
                if (!Number.isSafeInteger(next) || next <= offset || next > end) {
                    throw new Error('Invalid upload acknowledgement.');
                }
                offset = next;
                setProgress((offset / selected.size) * 85);
                const elapsed = Math.max(1, (Date.now() - uploadStarted) / 1000);
                const speed = offset / elapsed / 1048576;
                const eta = speed > 0 ?
                    Math.ceil((selected.size - offset) / (speed * 1048576)) : 0;
                setStatus('Uploaded ' + (offset / 1048576).toFixed(1) +
                    ' / ' + (selected.size / 1048576).toFixed(1) +
                    ' MiB · ' + speed.toFixed(2) + ' MiB/s · about ' +
                    (eta < 60 ? eta + 's' : Math.ceil(eta / 60) + 'min') + ' left');
                const chunkSeconds = (Date.now() - chunkStarted) / 1000;
                if (chunkSeconds > 22) {
                    chunkSize = Math.max(MIN_CHUNK_SIZE, Math.floor(chunkSize / 2));
                } else if (chunkSeconds < 7 && chunkSize < maxChunkSize) {
                    chunkSize = Math.min(maxChunkSize, chunkSize * 2);
                }
            }
            }

            setStatus('Patching and rebuilding APK…');

            const finished = await api(
                'android-patcher-finish',
                {upload_id: uploadId}
            );

            setProgress(100);
            setStatus('APK patched, aligned, signed and verified.');
            result.innerHTML =
                '<div class="warning mc-result">' +
                '<div><b>✅ APK patch completed.</b></div>' +
                '<div class="rowline"><span>Detected build</span><b>' +
                    escapeHtml(finished.source_build || 'Android GD') +
                '</b></div>' +
                '<div class="rowline"><span>Android app ID</span><code>' +
                    escapeHtml(finished.package_id || 'Original package') +
                '</code></div>' +
                '<div class="rowline"><span>Patched entries</span><b>' +
                    escapeHtml(finished.patched_entries) +
                '</b></div>' +
                '<div class="rowline"><span>Replacements</span><b>' +
                    escapeHtml(finished.replacement_count) +
                '</b></div>' +
                '<div class="rowline"><span>Output size</span><b>' +
                    (Number(finished.output_size) / 1048576).toFixed(2) +
                    ' MB</b></div>' +
                '<div class="rowline"><span>SHA-256</span><code style="word-break:break-all">' +
                    escapeHtml(finished.output_sha256) +
                '</code></div>' +
                '<div><b>Signed output:</b> the APK is ready for installation.</div>' +
                '<a class="btn" href="' + escapeHtml(finished.download_url) + '">' +
                    '⬇️ Download patched APK' +
                '</a>' +
                '</div>';
        } catch (error) {
            setStatus(error?.message || 'APK patch failed.');
            setProgress(0);
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
    <?php
}
