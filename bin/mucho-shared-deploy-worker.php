<?php
declare(strict_types=1);

/**
 * MuchoCore shared-hosting FTP deployment worker.
 *
 * The control plane downloads the published shared-hosting release, uploads
 * the verified package over FTP/FTPS, then drives the existing browser
 * installer over HTTPS. FTP and database secrets are stored only in the
 * temporary deployment job and are removed when the job exits.
 */

const JOB_ROOT = '/var/lib/muchocore-control/deploy-jobs';
const REPOSITORY = 'IZKGMD/GMDmucho-core';
const RELEASES_API = 'https://api.github.com/repos/' . REPOSITORY . '/releases/latest';
const MAX_DOWNLOAD_BYTES = 256 * 1024 * 1024;
const HTTP_TIMEOUT = 50;

require_once dirname(__DIR__) . '/vendor/autoload.php';


$jobId = '';
$dispatchOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = substr($arg, 6);
    } elseif ($arg === '--dispatch-queue') {
        $dispatchOnly = true;
    }
}
if (!$dispatchOnly && !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)) {
    exit(2);
}

$dir = JOB_ROOT . '/' . $jobId;
$statusFile = $dir . '/status.json';
$logFile = $dir . '/log.txt';

if (!is_dir($dir)) {
    exit(2);
}

function read_json_file(string $path): array {
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function write_status(string $path, array $data): void {
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($path, 0600);
}

function log_line(string $path, string $line): void {
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    $statusFile = dirname($path) . '/status.json';
    if (is_file($statusFile)) {
        $status = read_json_file($statusFile);
        $status['heartbeat_at'] = gmdate('c');
        @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($statusFile, 0600);
    }
}

function worker_state(string $statusFile, string $logFile, string $state): void {
    $status = read_json_file($statusFile);
    $status['state'] = $state;
    $status['heartbeat_at'] = gmdate('c');
    write_status($statusFile, $status);
    log_line($logFile, "[MuchoGDPS] State: {$state}\n");
}


function upload_client_pack_to_shared(
    array $config,
    string $ftpPassword,
    string $base,
    string $rootDir,
    string $jobDir,
    string $logFile,
    string $dbPassword,
    string $adminPassword
): void {
    $serverUrl = rtrim((string)$config['account_url'], '/');
    $serverName = (string)($config['gdps_name'] ?? 'Mucho GDPS');

    $manifest = \MuchoCore\Client\DeploymentClientPack::prepare(
        $rootDir,
        $jobDir,
        $serverUrl,
        $serverName
    );

    [$ftp, $usedSecurity, $usedPort] = ftp_open_authenticated($config, $ftpPassword, $logFile);

    $manifestPath = $jobDir . '/tenant-client-manifest.json';
    $tenantManifest = [
        'patch_engine' => '2.0',
        'server_url' => (string)($manifest['server_url'] ?? $serverUrl),
        'server_name' => (string)($manifest['server_name'] ?? $serverName),
        'client_version' => (string)($manifest['client_version'] ?? ''),
        'source_windows_sha256' => (string)($manifest['source_windows_sha256'] ?? ''),
        'source_android_sha256' => (string)($manifest['source_android_sha256'] ?? ''),
        'created_at' => gmdate('c'),
        'windows' => [
            'name' => (string)($manifest['windows']['name'] ?? 'GeometryDash-MuchoGDPS.exe'),
            'size' => (int)($manifest['windows']['size'] ?? 0),
            'sha256' => (string)($manifest['windows']['sha256'] ?? ''),
            'replacement_count' => (int)($manifest['windows']['replacement_count'] ?? 0),
        ],
        'android' => [
            'name' => (string)($manifest['android']['name'] ?? 'GeometryDash-MuchoGDPS.apk'),
            'size' => (int)($manifest['android']['size'] ?? 0),
            'sha256' => (string)($manifest['android']['sha256'] ?? ''),
            'replacement_count' => (int)($manifest['android']['replacement_count'] ?? 0),
        ],
        'archive' => [
            'name' => (string)($manifest['archive']['name'] ?? 'MuchoGDPS-Client-Pack.zip'),
            'size' => (int)($manifest['archive']['size'] ?? 0),
            'sha256' => (string)($manifest['archive']['sha256'] ?? ''),
        ],
    ];

    $encoded = json_encode(
        $tenantManifest,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
    file_put_contents($manifestPath, $encoded, LOCK_EX);
    @chmod($manifestPath, 0600);

    try {
        if (!@ftp_chdir($ftp, $base)) {
            throw new RuntimeException('Unable to return to the shared-hosting web root for client upload.');
        }

        if (!@ftp_chdir($ftp, 'storage')) {
            if (!@ftp_mkdir($ftp, 'storage') || !@ftp_chdir($ftp, 'storage')) {
                throw new RuntimeException('Unable to create shared-hosting storage directory for clients.');
            }
        }

        if (!@ftp_chdir($ftp, 'clients')) {
            if (!@ftp_mkdir($ftp, 'clients') || !@ftp_chdir($ftp, 'clients')) {
                throw new RuntimeException('Unable to create shared-hosting client directory.');
            }
        }

        $files = [
            'windows' => [
                'local' => (string)$manifest['windows']['path'],
                'remote' => 'GeometryDash-MuchoGDPS.exe',
            ],
            'android' => [
                'local' => (string)$manifest['android']['path'],
                'remote' => 'GeometryDash-MuchoGDPS.apk',
            ],
            'archive' => [
                'local' => (string)$manifest['archive']['path'],
                'remote' => 'MuchoGDPS-Client-Pack.zip',
            ],
            'manifest' => [
                'local' => $manifestPath,
                'remote' => 'manifest.json',
            ],
        ];

        foreach ($files as $label => $entry) {
            if (!is_file($entry['local']) || !is_readable($entry['local'])) {
                throw new RuntimeException('Generated ' . $label . ' client file is unavailable.');
            }

            if (!@ftp_put($ftp, $entry['remote'], $entry['local'], FTP_BINARY)) {
                throw new RuntimeException('Failed to upload generated ' . $label . ' client.');
            }

            log_line(
                $logFile,
                '[MuchoGDPS] Uploaded tenant client ' . $entry['remote'] .
                ' via ' . strtoupper($usedSecurity) . ' port ' . $usedPort . ".
"
            );
        }
    \MuchoCore\Deployment\DeploymentDetailsExporter::writeShared(
        $jobDir,
        $config,
        $ftpPassword,
        (string)$usedSecurity,
        (int)$usedPort,
        (string)$base,
        $dbPassword,
        $adminPassword
    );
    log_line($logFile, "[MuchoGDPS] Deployment details TXT generated.\n");
    } finally {
        @ftp_close($ftp);
        @unlink($manifestPath);
    }
}

function cleanup_secrets(string $dir): void {
    foreach (['ftp_password', 'shared_db_password', 'admin_password', 'shared_config', 'shared_cookie', 'shared_archive', 'browser_finalization_payload'] as $file) {
        @unlink($dir . '/' . $file);
    }
}

function dispatch_queued_jobs(string $root): void {
    $lock = JOB_ROOT . '/queue-dispatch.lock';
    if (!@mkdir($lock, 0700)) {
        return;
    }

    try {
        $max = max(1, (int)($_ENV['MUCHO_DEPLOY_MAX_CONCURRENT'] ?? getenv('MUCHO_DEPLOY_MAX_CONCURRENT') ?: 2));
        $now = time();

        foreach (glob(JOB_ROOT . '/*/status.json') ?: [] as $statusFile) {
            $stale = read_json_file($statusFile);
            if (!in_array((string)($stale['status'] ?? ''), ['running', 'browser_completed', 'post_processing'], true)) {
                continue;
            }
            $heartbeat = strtotime((string)($stale['heartbeat_at'] ?? ''));
            $started = strtotime((string)($stale['started_at'] ?? $stale['created_at'] ?? ''));
            $reference = ($heartbeat !== false && $heartbeat > 0) ? $heartbeat : ($started ?: 0);
            if ($reference <= 0 || $reference > $now - 60) {
                continue;
            }

            $staleDir = dirname($statusFile);
            $staleId = (string)($stale['id'] ?? basename($staleDir));
            $stale['status'] = 'failed';
            $stale['exit_code'] = 124;
            $stale['finished_at'] = gmdate('c');
            $stale['timed_out'] = true;
            $stale['timeout_seconds'] = 60;
            @file_put_contents($statusFile, json_encode($stale, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
            @file_put_contents($staleDir . '/log.txt',
                "\n[MuchoGDPS] ERROR: Deployment watchdog marked job {$staleId} failed after 60 seconds without a worker heartbeat.\n"
                . "[MuchoGDPS] The deployment session was reset. Start a new installation after checking the previous error.\n",
                FILE_APPEND | LOCK_EX
            );
        }

        while (true) {
            $running = 0;
            $queued = [];

            foreach (glob(JOB_ROOT . '/*/status.json') ?: [] as $statusFile) {
                $status = read_json_file($statusFile);
                if (in_array((string)($status['status'] ?? ''), ['running', 'starting', 'awaiting_browser', 'browser_completed', 'post_processing'], true)) {
                    $running++;
                } elseif (($status['status'] ?? '') === 'queued') {
                    $queued[] = [$statusFile, $status];
                }
            }

            if ($running >= $max || $queued === []) {
                break;
            }

            usort($queued, static function(array $a, array $b): int {
                $ta = strtotime((string)($a[1]['created_at'] ?? '')) ?: PHP_INT_MAX;
                $tb = strtotime((string)($b[1]['created_at'] ?? '')) ?: PHP_INT_MAX;
                return ($ta <=> $tb) ?: strcmp((string)($a[1]['id'] ?? ''), (string)($b[1]['id'] ?? ''));
            });

            [$statusFile, $status] = $queued[0];
            $id = (string)($status['id'] ?? '');
            $type = strtolower((string)($status['type'] ?? 'shared'));
            $worker = $type === 'vps'
                ? $root . '/bin/mucho-deploy-worker.php'
                : $root . '/bin/mucho-shared-deploy-worker.php';

            if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $id) || !is_file($worker)) {
                $status['status'] = 'failed';
                $status['exit_code'] = 1;
                $status['finished_at'] = gmdate('c');
                @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
                continue;
            }

            $jobDir = dirname($statusFile);
            $status['status'] = 'starting';
            $status['started_at'] = gmdate('c');
            $status['heartbeat_at'] = gmdate('c');
            @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
            @file_put_contents($jobDir . '/log.txt', "[MuchoGDPS] Queue slot available. Starting queued job {$id}.\n", FILE_APPEND | LOCK_EX);

            $cmd = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker)
                . ' --job=' . escapeshellarg($id)
                . ' >> ' . escapeshellarg($jobDir . '/log.txt') . ' 2>&1 & echo $!';
            $output = [];
            $exit = 0;
            @exec($cmd, $output, $exit);
            $pid = (int)($output[0] ?? 0);

            if ($exit !== 0 || $pid <= 0) {
                $status['status'] = 'failed';
                $status['exit_code'] = 1;
                $status['finished_at'] = gmdate('c');
                @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
                continue;
            }

            $status['status'] = 'running';
            $status['pid'] = $pid;
            $status['heartbeat_at'] = gmdate('c');
            @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        }
    } finally {
        @rmdir($lock);
    }
}

function remove_tree(string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function public_ipv4s(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return is_public_ipv4($host) ? [$host] : [];
    }

    $addresses = @gethostbynamel($host);
    if (!is_array($addresses)) {
        return [];
    }

    $result = [];
    foreach ($addresses as $address) {
        if (is_public_ipv4((string)$address)) {
            $result[] = (string)$address;
        }
    }
    return array_values(array_unique($result));
}

function is_public_ipv4(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $n = sprintf('%u', ip2long($ip));
    foreach ([
        [0, 16777215],        // 0.0.0.0/8
        [167772160, 184549375], // 10.0.0.0/8
        [2130706432, 2147483647], // 127.0.0.0/8
        [2851995648, 2852061183], // 169.254.0.0/16
        [2886729728, 2887778303], // 172.16.0.0/12
        [3232235520, 3232301055], // 192.168.0.0/16
        [3221225472, 3221225727], // 192.0.0.0/24
        [3221225984, 3221226239], // 192.0.2.0/24
        [3323068416, 3323068671], // 198.18.0.0/15
        [3325256704, 3325256959], // 198.51.100.0/24
        [3405803776, 3405804031], // 203.0.113.0/24
        [3758096384, 4294967295], // 224.0.0.0/3
    ] as [$low, $high]) {
        if ((int)$n >= $low && (int)$n <= $high) {
            return false;
        }
    }
    return true;
}

function github_get(string $url): string {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize the GitHub HTTP client.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 50,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'MuchoCore-Shared-FTP-Installer/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('GitHub request failed: ' . ($error !== '' ? $error : 'unknown error'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('GitHub returned HTTP ' . $status . '.');
    }
    if (strlen((string)$body) > 2 * 1024 * 1024) {
        throw new RuntimeException('GitHub API response is unexpectedly large.');
    }
    return (string)$body;
}

function latest_release(): array {
    $payload = json_decode(github_get(RELEASES_API), true);
    if (!is_array($payload)) {
        throw new RuntimeException('GitHub returned invalid release metadata.');
    }

    $tag = (string)($payload['tag_name'] ?? '');
    if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $m)) {
        throw new RuntimeException('The latest GitHub release is not a stable semantic version.');
    }
    if (($payload['draft'] ?? true) || ($payload['prerelease'] ?? true)) {
        throw new RuntimeException('The latest GitHub release is not published as stable.');
    }

    $version = $m[1];
    $name = 'MuchoCore-v' . $version . '-shared-hosting.zip';
    foreach (is_array($payload['assets'] ?? null) ? $payload['assets'] : [] as $asset) {
        if (!is_array($asset) || (string)($asset['name'] ?? '') !== $name) {
            continue;
        }
        $url = (string)($asset['browser_download_url'] ?? '');
        $digest = strtolower((string)($asset['digest'] ?? ''));
        if (!str_starts_with($url, 'https://github.com/')) {
            throw new RuntimeException('The shared-hosting release asset has an invalid URL.');
        }
        if (!preg_match('/^sha256:[0-9a-f]{64}$/', $digest)) {
            throw new RuntimeException('The shared-hosting release asset has no valid SHA-256 digest.');
        }
        return [
            'tag' => $tag,
            'version' => $version,
            'name' => $name,
            'url' => $url,
            'sha256' => substr($digest, 7),
        ];
    }

    throw new RuntimeException('Release ' . $tag . ' does not contain ' . $name . '.');
}

function local_shared_release(string $rootDir, string $destination): array
{
    $version = trim((string)@file_get_contents($rootDir . '/VERSION'));
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        throw new RuntimeException('The control-plane VERSION file is not a stable semantic version.');
    }

    $builder = $rootDir . '/tools/release/build-shared-hosting.sh';
    if (!is_file($builder) || !is_readable($builder)) {
        throw new RuntimeException('The shared-hosting package builder is unavailable on the control server.');
    }

    // Build into the container's temporary filesystem first. This avoids
    // provider-specific/container-volume permission or visibility quirks when
    // the child shell writes directly into the control-plane job directory.
    $builderOutput = @tempnam(sys_get_temp_dir(), 'muchocore-shared-');
    if ($builderOutput === false) {
        throw new RuntimeException('Unable to allocate a temporary path for the shared-hosting package.');
    }
    @unlink($builderOutput);

    $command = 'VERSION=' . escapeshellarg($version)
        . ' OUTPUT=' . escapeshellarg($builderOutput)
        . ' bash ' . escapeshellarg($builder);
    $process = @proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        @unlink($builderOutput);
        throw new RuntimeException('Unable to start the shared-hosting package builder.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $output = trim(implode("\n", array_filter([
        is_string($stdout) ? trim($stdout) : '',
        is_string($stderr) ? trim($stderr) : '',
    ], static fn(string $value): bool => $value !== '')));

    clearstatcache(true, $builderOutput);
    $builderExists = is_file($builderOutput);
    $builderReadable = $builderExists && is_readable($builderOutput);
    $builderSize = $builderExists ? (int)(@filesize($builderOutput) ?: 0) : 0;

    if ($exit !== 0 || !$builderExists || !$builderReadable || $builderSize < 1024) {
        @unlink($builderOutput);
        throw new RuntimeException(
            'Failed to build the shared-hosting package from the current control-plane source '
            . '(exit code ' . $exit
            . ', temp_file_exists=' . ($builderExists ? 'yes' : 'no')
            . ', temp_file_readable=' . ($builderReadable ? 'yes' : 'no')
            . ', temp_size=' . $builderSize . ').'
            . ($output !== '' ? ' ' . $output : '')
        );
    }

    $destinationPart = $destination . '.part';
    @unlink($destinationPart);
    if (!@copy($builderOutput, $destinationPart)) {
        @unlink($builderOutput);
        throw new RuntimeException('The shared-hosting package was built, but could not be copied into the deployment job directory.');
    }

    clearstatcache(true, $destinationPart);
    if (!is_file($destinationPart) || !is_readable($destinationPart) || (int)(@filesize($destinationPart) ?: 0) !== $builderSize) {
        @unlink($destinationPart);
        @unlink($builderOutput);
        throw new RuntimeException('The shared-hosting package copy into the deployment job directory failed verification.');
    }

    if (!@rename($destinationPart, $destination)) {
        @unlink($destinationPart);
        @unlink($builderOutput);
        throw new RuntimeException('The shared-hosting package was copied but could not be finalized in the deployment job directory.');
    }

    @unlink($builderOutput);
    clearstatcache(true, $destination);

    $destinationExists = is_file($destination);
    $destinationReadable = $destinationExists && is_readable($destination);
    $destinationSize = $destinationExists ? (int)(@filesize($destination) ?: 0) : 0;

    if (!$destinationExists || !$destinationReadable || $destinationSize !== $builderSize) {
        throw new RuntimeException(
            'The built shared-hosting package failed final verification '
            . '(file_exists=' . ($destinationExists ? 'yes' : 'no')
            . ', readable=' . ($destinationReadable ? 'yes' : 'no')
            . ', size=' . $destinationSize
            . ', expected_size=' . $builderSize . ').'
        );
    }

    $sha256 = hash_file('sha256', $destination);
    if (!is_string($sha256) || $sha256 === '') {
        throw new RuntimeException('Could not calculate the local shared-hosting package SHA-256.');
    }

    return [
        'tag' => 'working-tree',
        'version' => $version,
        'name' => 'MuchoCore-v' . $version . '-shared-hosting.zip',
        'url' => '',
        'sha256' => strtolower($sha256),
    ];
}

function download_release(string $url, string $destination): int {
    $handle = @fopen($destination, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to create the release archive.');
    }

    $bytes = 0;
    try {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the release downloader.');
        }
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 50,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MuchoCore-Shared-FTP-Installer/1.0',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($handle, &$bytes): int {
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > MAX_DOWNLOAD_BYTES) {
                    return 0;
                }
                $written = fwrite($handle, $chunk);
                return $written === false ? 0 : $written;
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $error !== '') {
            throw new RuntimeException(
                $bytes > MAX_DOWNLOAD_BYTES
                    ? 'The shared-hosting release exceeds the download safety limit.'
                    : 'Release download failed: ' . ($error !== '' ? $error : 'unknown transfer error')
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub returned HTTP ' . $status . ' while downloading the release.');
        }
    } finally {
        fclose($handle);
    }

    if ($bytes < 1) {
        throw new RuntimeException('The downloaded release archive is empty.');
    }
    return $bytes;
}

function validate_archive(ZipArchive $zip, string $version): void {
    $required = [
        'muchocore/.htaccess',
        'muchocore/VERSION',
        'muchocore/public/index.php',
        'muchocore/public/shared-install.php',
        'muchocore/vendor/autoload.php',
    ];
    $found = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $raw = (string)$zip->getNameIndex($i);
        $name = str_replace('\\', '/', $raw);
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0")
            || preg_match('#(^|/)\.\.?(/|$)#', $name) === 1) {
            throw new RuntimeException('The release archive contains an unsafe path.');
        }
        if (!str_starts_with($name, 'muchocore/')) {
            throw new RuntimeException('The release archive contains an unexpected top-level path.');
        }
        $found[$name] = true;
    }

    foreach ($required as $path) {
        if (!isset($found[$path])) {
            throw new RuntimeException('The release archive is incomplete: missing ' . $path . '.');
        }
    }

    $embedded = trim((string)$zip->getFromName('muchocore/VERSION'));
    if ($embedded !== $version) {
        throw new RuntimeException('The archive VERSION does not match the release tag.');
    }
}

function ftp_connect_public(string $host, int $port, bool $secure): \FTP\Connection {
    $ips = public_ipv4s($host);
    if ($ips === []) {
        throw new RuntimeException('The FTP hostname does not resolve to a public IPv4 address.');
    }
    $ip = $ips[0];

    $ftp = $secure
        ? @ftp_ssl_connect($ip, $port, 8)
        : @ftp_connect($ip, $port, 8);

    if ($ftp === false) {
        throw new RuntimeException('Could not connect to the FTP server.');
    }

    @ftp_set_option($ftp, FTP_TIMEOUT_SEC, 12);
    @ftp_set_option($ftp, FTP_AUTOSEEK, true);
    return $ftp;
}

function ftp_open_authenticated(array $config, string $password, string $logFile): array {
    $host = (string)$config['ftp_host'];
    $requestedSecurity = strtolower(trim((string)$config['ftp_security']));
    $requestedPort = (int)$config['ftp_port'];

    $candidates = [];
    if ($requestedSecurity === 'auto' || $requestedSecurity === '') {
        $ports = $requestedPort > 0
            ? array_values(array_unique([$requestedPort, 21, 990]))
            : [21, 990];
        foreach ($ports as $port) {
            foreach ([false, true] as $secure) {
                $candidates[] = [$secure, $port];
            }
        }
    } else {
        $port = $requestedPort > 0 ? $requestedPort : ($requestedSecurity === 'ftps' ? 21 : 21);
        $candidates[] = [$requestedSecurity === 'ftps', $port];
    }

    $seen = [];
    $lastError = 'No FTP connection method succeeded.';
    foreach ($candidates as [$secure, $port]) {
        $key = ($secure ? 'ftps' : 'ftp') . ':' . $port;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        log_line($logFile, "[MuchoGDPS] Trying " . strtoupper($secure ? 'FTPS' : 'FTP') . " on {$host}:{$port}...\n");
        try {
            $ftp = ftp_connect_public($host, $port, $secure);
            if (!@ftp_login($ftp, (string)$config['ftp_username'], $password)) {
                throw new RuntimeException('FTP authentication failed.');
            }
            if (!@ftp_pasv($ftp, true)) {
                throw new RuntimeException('FTP passive mode was refused.');
            }

            log_line($logFile, "[MuchoGDPS] Connected using " . strtoupper($secure ? 'FTPS' : 'FTP') . " on port {$port}.\n");
            return [$ftp, $secure ? 'ftps' : 'ftp', $port];
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            if (isset($ftp) && $ftp instanceof \FTP\Connection) {
                @ftp_close($ftp);
            }
            unset($ftp);
            log_line($logFile, "[MuchoGDPS] Method failed: {$lastError}\n");
        }
    }

    throw new RuntimeException($lastError);
}

function ensure_remote_dir(\FTP\Connection $ftp, string $root, string $relative, array &$known): void {
    $parts = array_values(array_filter(
        explode('/', trim(str_replace('\\', '/', $relative), '/')),
        static fn(string $v): bool => $v !== ''
    ));
    $base = $root !== '' ? $root : '.';
    $current = $base;

    foreach ($parts as $part) {
        if ($part === '.' || $part === '..' || preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
            throw new RuntimeException('Unsafe remote FTP path component.');
        }

        $current = rtrim($current, '/') . '/' . $part;
        if (!isset($known[$current])) {
            if (!@ftp_chdir($ftp, $current)) {
                if (!@ftp_mkdir($ftp, $current) || !@ftp_chdir($ftp, $current)) {
                    throw new RuntimeException('Unable to create remote directory: ' . $current);
                }
            }
            $known[$current] = true;
        }

        if (!@ftp_chdir($ftp, $base)) {
            throw new RuntimeException('Unable to restore the FTP web-root directory.');
        }
    }
}

function safe_remote_candidate(string $path): bool {
    $path = str_replace('\\', '/', trim($path));
    return $path === '' || (strlen($path) <= 512
        && !str_contains($path, "\0")
        && preg_match('#(^|/)\.\.?(/|$)#', $path) !== 1);
}

function provider_default_web_root(string $ftpHost): ?string {
    $host = strtolower(trim($ftpHost));
    if ($host === 'ftpupload.net' || $host === 'ftp.epizy.com' || str_ends_with($host, '.epizy.com')) {
        return 'htdocs';
    }
    return null;
}

function provider_requires_browser_finalization(string $ftpHost): bool {
    $host = strtolower(trim($ftpHost));
    return $host === 'ftpupload.net'
        || $host === 'ftp.epizy.com'
        || str_ends_with($host, '.epizy.com');
}

function create_browser_finalization_payload(
    array $config,
    string $dbPassword,
    string $adminPassword,
    string $jobDir
): array {
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + 900;
    $payloadPath = $jobDir . '/browser_finalization_payload';
    $payload = [
        'token' => $token,
        'expires_at' => $expiresAt,
        'job_id' => basename($jobDir),
        'control_url' => 'https://muchogdps.space',
        'db_host' => (string)$config['db_host'],
        'db_port' => (string)$config['db_port'],
        'db_name' => (string)$config['db_name'],
        'db_user' => (string)$config['db_user'],
        'db_pass' => $dbPassword,
        'account_url' => rtrim((string)$config['account_url'], '/'),
        'gdps_name' => (string)($config['gdps_name'] ?? ''),
        'admin_pass' => $adminPassword,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        throw new RuntimeException('Unable to prepare the browser finalization payload.');
    }
    if (@file_put_contents($payloadPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the browser finalization payload.');
    }
    @chmod($payloadPath, 0600);
    return [$token, $expiresAt, $payloadPath];
}

function upload_browser_finalization_payload(
    \FTP\Connection $ftp,
    string $base,
    string $token,
    string $payloadPath
): void {
    if (!@ftp_chdir($ftp, $base)) {
        throw new RuntimeException('Unable to return to the FTP web-root directory before browser finalization.');
    }
    if (!@ftp_chdir($ftp, 'storage')) {
        if (!@ftp_mkdir($ftp, 'storage') || !@ftp_chdir($ftp, 'storage')) {
            throw new RuntimeException('Unable to create the storage directory for browser finalization.');
        }
    }
    $remote = '.mucho-auto-' . $token . '.json';
    if (!@ftp_put($ftp, $remote, $payloadPath, FTP_BINARY)) {
        throw new RuntimeException('Unable to upload the browser finalization payload.');
    }
    if (!@ftp_chdir($ftp, $base)) {
        throw new RuntimeException('Unable to restore the FTP web-root directory after browser finalization.');
    }
}


function detect_web_root(
    \FTP\Connection $ftp,
    string $configuredBase,
    string $accountUrl,
    string $ip,
    string $jobDir,
    string $logFile
): string {
    $host = strtolower((string)(parse_url($accountUrl)['host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('Unable to determine the GDPS hostname for web-root detection.');
    }

    $candidates = ['.','htdocs','www','public_html','httpdocs','public'];
    $domainCandidates = [
        'domains/' . $host,
        'domains/' . $host . '/public_html',
        'www/' . $host,
        'public_html/' . $host,
    ];
    $candidates = array_values(array_unique(array_merge($candidates, $domainCandidates)));

    $token = bin2hex(random_bytes(12));
    $markerName = '.muchocore-root-probe-' . $token . '.txt';
    $markerLocal = $jobDir . '/' . $markerName;
    file_put_contents($markerLocal, $token, LOCK_EX);
    @chmod($markerLocal, 0600);

    try {
        foreach ($candidates as $candidate) {
            if (!safe_remote_candidate($candidate)) {
                continue;
            }
            if (!@ftp_chdir($ftp, $configuredBase)) {
                throw new RuntimeException('Unable to restore the FTP base directory during web-root detection.');
            }
            if ($candidate !== '.' && !@ftp_chdir($ftp, $candidate)) {
                continue;
            }

            $candidateBase = @ftp_pwd($ftp);
            if (!is_string($candidateBase) || $candidateBase === '') {
                continue;
            }

            log_line($logFile, "[MuchoGDPS] Probing web root: {$candidate}...\n");
            if (!@ftp_put($ftp, $markerName, $markerLocal, FTP_ASCII)) {
                log_line($logFile, "[MuchoGDPS] Probe upload failed for {$candidate}.\n");
                continue;
            }

            try {
                $probeUrl = rtrim($accountUrl, '/') . '/' . rawurlencode($markerName) . '?m=' . $token;
                $response = http_request($probeUrl, $ip, $jobDir . '/shared_cookie', null);
                if ($response['status'] === 200 && trim($response['body']) === $token) {
                    @ftp_delete($ftp, $markerName);
                    log_line($logFile, "[MuchoGDPS] Auto-detected web root: {$candidateBase}\n");
                    return $candidateBase;
                }
            } finally {
                @ftp_delete($ftp, $markerName);
            }
        }
    } finally {
        @unlink($markerLocal);
        @ftp_chdir($ftp, $configuredBase);
    }

    throw new RuntimeException(
        'Could not auto-detect the web root. Choose the hosting web-root directory manually and start a new deployment.'
    );
}

function upload_tree(\FTP\Connection $ftp, string $localRoot, string $base, string $logFile): int {
    if (!@ftp_chdir($ftp, $base)) {
        throw new RuntimeException('Unable to switch to the selected FTP web-root directory before upload: ' . $base);
    }

    $actualBase = @ftp_pwd($ftp);
    if (!is_string($actualBase) || $actualBase === '') {
        throw new RuntimeException('Unable to verify the selected FTP web-root directory before upload.');
    }

    log_line($logFile, "[MuchoGDPS] Uploading into web root: {$actualBase}\n");
    $knownDirs = [$actualBase => true];
    $count = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $files = [];
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($localRoot) + 1));
        if (
            $relative === 'public/ftp-install.php'
            || $relative === 'public/index.html'
            || str_starts_with($relative, 'public/install/')
            || $relative === 'public/install'
            || str_starts_with($relative, 'public/deploy/')
            || $relative === 'public/deploy'
            || $relative === 'public/deploy.php'
        ) {
            // The installer/deployment UI belongs to the central MuchoGDPS
            // control plane, never to an installed tenant GDPS.
            continue;
        }
        $files[] = [$item->getPathname(), $relative];
    }
    usort($files, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));

    foreach ($files as [$local, $relative]) {
        // Always reset CWD to the selected web root. FTP servers keep CWD state
        // between operations, so relative paths are otherwise resolved from the
        // directory used for the previous file.
        if (!@ftp_chdir($ftp, $actualBase)) {
            throw new RuntimeException('Unable to restore the FTP web-root directory before upload: ' . $actualBase);
        }

        $dir = dirname($relative);
        if ($dir !== '.' && $dir !== '') {
            ensure_remote_dir($ftp, $actualBase, $dir, $knownDirs);
            if (!@ftp_chdir($ftp, $actualBase)) {
                throw new RuntimeException('Unable to restore the FTP web-root directory after preparing: ' . $dir);
            }
        }

        $remote = str_replace('\\', '/', $relative);
        if (!@ftp_put($ftp, $remote, $local, FTP_BINARY)) {
            throw new RuntimeException('Failed to upload: ' . $relative);
        }

        // Keep the next iteration deterministic even when ftp_put changes CWD
        // on a provider-specific FTP implementation.
        if (!@ftp_chdir($ftp, $actualBase)) {
            throw new RuntimeException('Unable to restore the FTP web-root directory after upload.');
        }

        $count++;
        if (($count % 25) === 0) {
            log_line($logFile, "[MuchoGDPS] Uploaded {$count} files...\n");
        }
    }

    return $count;
}
function installer_csrf(string $html): string {
    if (preg_match_all('/<input\\b[^>]*>/i', $html, $inputs) === false) {
        throw new RuntimeException('Unable to parse the shared installer response.');
    }

    foreach ($inputs[0] as $input) {
        $attributes = [];
        $name = '';
        $value = '';
        if (preg_match("/name\\\\s*=\\\\s*[\"']([^\"']+)[\"']/i", $input, $nameMatch) === 1) {
            $name = strtolower((string)$nameMatch[1]);
        }
        if (preg_match("/value\\\\s*=\\\\s*[\"']([^\"']*)[\"']/i", $input, $valueMatch) === 1) {
            $value = html_entity_decode((string)$valueMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($name === 'csrf' && $value !== '') {
            return $value;
        }
    }

    $clean = trim(preg_replace('/\\s+/', ' ', strip_tags($html)) ?? '');
    foreach ([
        'MuchoCore is already installed',
        'Unable to start the installer session',
        'Another MuchoCore shared-hosting installation is already running',
    ] as $marker) {
        if ($clean !== '' && stripos($clean, $marker) !== false) {
            throw new RuntimeException('Shared installer response: ' . $marker . '.');
        }
    }

    throw new RuntimeException('Could not obtain the shared installer CSRF token.');
}

function http_request(string $url, string $ip, string $cookieFile, ?array $post = null): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize the target HTTP client.');
    }

    $parsed = parse_url($url);
    $host = is_array($parsed) ? strtolower((string)($parsed['host'] ?? '')) : '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RESOLVE => [$host . ':443:' . $ip],
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_USERAGENT => 'MuchoCore-Shared-FTP-Installer/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&', PHP_QUERY_RFC3986));
    }

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Target website request failed: ' . ($error !== '' ? $error : 'unknown error'));
    }

    return ['status' => $status, 'body' => (string)$body];
}

function wait_for_browser_finalization(
    int $expiresAt,
    string $logFile,
    string $statusFile
): void {
    while (time() < $expiresAt) {
        $status = read_json_file($statusFile);
        $current = (string)($status['status'] ?? '');

        if (in_array($current, ['browser_completed', 'completed'], true)) {
            log_line($logFile, "[MuchoGDPS] Browser finalization completed; deployment job confirmed.
");
            return;
        }

        if ($current === 'failed') {
            throw new RuntimeException('Browser finalization reported an installation error.');
        }

        log_line($logFile, "[MuchoGDPS] Waiting for browser finalization...
");
        sleep(2);
    }

    throw new RuntimeException('Browser finalization did not complete before the authorization window expired.');
}

function run_remote_installer(array $config, string $dbPassword, string $adminPassword, string $cookieFile, string $logFile): void {
    $accountUrl = rtrim((string)$config['account_url'], '/');
    $parsed = parse_url($accountUrl);
    $host = strtolower((string)($parsed['host'] ?? ''));
    $ips = public_ipv4s($host);
    if ($ips === []) {
        throw new RuntimeException('The GDPS hostname does not resolve to a public IPv4 address.');
    }
    $ip = $ips[0];

    log_line($logFile, "[MuchoGDPS] Opening the remote shared installer over HTTPS...\n");
    $page = http_request($accountUrl . '/shared-install.php', $ip, $cookieFile);
    if ($page['status'] < 200 || $page['status'] >= 400) {
        throw new RuntimeException('The remote shared installer returned HTTP ' . $page['status'] . '.');
    }

    $csrf = installer_csrf($page['body']);
    log_line($logFile, "[MuchoGDPS] Remote installer session established. Starting database setup...\n");

    $post = [
        'csrf' => $csrf,
        'db_host' => (string)$config['db_host'],
        'db_port' => (string)$config['db_port'],
        'db_name' => (string)$config['db_name'],
        'db_user' => (string)$config['db_user'],
        'db_pass' => $dbPassword,
        'account_url' => $accountUrl,
        'gdps_name' => (string)($config['gdps_name'] ?? 'Mucho GDPS'),
        'admin_pass' => $adminPassword,
        'admin_pass2' => $adminPassword,
    ];

    $result = http_request($accountUrl . '/shared-install.php', $ip, $cookieFile, $post);
    if ($result['status'] < 200 || $result['status'] >= 400) {
        throw new RuntimeException('The remote installer returned HTTP ' . $result['status'] . ' during installation.');
    }

    if (str_contains($result['body'], 'MuchoCore is installed')) {
        log_line($logFile, "[MuchoGDPS] Remote installer reported a completed installation.\n");
    } else {
        log_line($logFile, "[MuchoGDPS] Remote installer finished without its success page; checking /health...\n");
    }

    $health = http_request($accountUrl . '/health', $ip, $cookieFile);
    if ($health['status'] < 200 || $health['status'] >= 400 || trim($health['body']) !== '1') {
        throw new RuntimeException('The shared host was uploaded successfully, but /health did not return 1.');
    }

    log_line($logFile, "[MuchoGDPS] Remote /health check returned 1.\n");
}

if ($dispatchOnly) {
    dispatch_queued_jobs(dirname(__DIR__));
    exit(0);
}

$status = read_json_file($statusFile);
$config = read_json_file($dir . '/shared_config');
$ftpPassword = (string)@file_get_contents($dir . '/ftp_password');
$dbPassword = (string)@file_get_contents($dir . '/shared_db_password');
$adminPassword = (string)@file_get_contents($dir . '/admin_password');

try {
    if ($ftpPassword === '' || $dbPassword === '' || $adminPassword === '' || $config === []) {
        throw new RuntimeException('Shared deployment job secrets or configuration are missing.');
    }

    if (!extension_loaded('ftp')) {
        throw new RuntimeException('PHP FTP support is not enabled in the MuchoGDPS deployment worker.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZIP support is not enabled in the MuchoGDPS deployment worker.');
    }

    $status['status'] = 'running';
    $status['started_at'] = $status['started_at'] ?? gmdate('c');
    $status['heartbeat_at'] = gmdate('c');
    write_status($statusFile, $status);
    $status['state'] = 'Starting deployment worker';
    write_status($statusFile, $status);

    log_line($logFile, "[MuchoGDPS] Worker started. Watchdog: 60s without heartbeat.\n");
    $archive = $dir . '/shared_archive';
    $sourceMode = strtolower(trim((string)(getenv('MUCHO_SHARED_DEPLOY_SOURCE') ?: 'stable')));

    if ($sourceMode === 'working-tree' || $sourceMode === 'local') {
        worker_state($statusFile, $logFile, 'Building the shared-hosting package from the current control-plane source');
        log_line($logFile, "[MuchoGDPS] Shared deployment source: current control-plane working tree.\n");
        $release = local_shared_release(dirname(__DIR__), $archive);
        $bytes = filesize($archive);
        log_line($logFile, "[MuchoGDPS] Local package version: {$release['version']} (working-tree).\n");
        log_line($logFile, "[MuchoGDPS] Built package SHA-256: {$release['sha256']}\n");
        log_line($logFile, "[MuchoGDPS] Built " . number_format((int)$bytes) . " bytes from the current MuchoCore source.\n");
    } else {
        worker_state($statusFile, $logFile, 'Fetching stable release metadata from GitHub');
        log_line($logFile, "[MuchoGDPS] Fetching latest stable release metadata from GitHub...\\n");
        $release = latest_release();
        log_line($logFile, "[MuchoGDPS] Stable release: {$release['tag']}\\n");
        log_line($logFile, "[MuchoGDPS] Package: {$release['name']}\\n");

        worker_state($statusFile, $logFile, 'Downloading the verified release package');
        log_line($logFile, "[MuchoGDPS] Downloading {$release['name']} from GitHub...\\n");
        $bytes = download_release($release['url'], $archive);
        worker_state($statusFile, $logFile, 'Verifying release SHA-256');
        $actual = hash_file('sha256', $archive);
        if (!is_string($actual) || !hash_equals($release['sha256'], strtolower($actual))) {
            throw new RuntimeException('Release SHA-256 verification failed. The package was not uploaded.');
        }
        log_line($logFile, "[MuchoGDPS] Verified release SHA-256. Downloaded " . number_format($bytes) . " bytes.\\n");
    }

    worker_state($statusFile, $logFile, 'Validating and extracting the shared-hosting package');
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Unable to open the verified shared-hosting archive.');
    }
    try {
        validate_archive($zip, $release['version']);
        $stage = $dir . '/extracted';
        @mkdir($stage, 0700, true);
        if (!@mkdir($stage . '/muchocore', 0700, true) && !is_dir($stage . '/muchocore')) {
            throw new RuntimeException('Unable to prepare the release extraction directory.');
        }
        if (!$zip->extractTo($stage)) {
            throw new RuntimeException('Unable to extract the verified shared-hosting archive.');
        }
    } finally {
        $zip->close();
    }

    $localRoot = $dir . '/extracted/muchocore';
    if (!is_dir($localRoot)) {
        throw new RuntimeException('The extracted shared-hosting package is missing its muchocore directory.');
    }

    worker_state($statusFile, $logFile, 'Connecting to shared hosting over FTP');
    log_line($logFile, "[MuchoGDPS] Connecting to shared hosting FTP...\n");
    [$ftp, $usedSecurity, $usedPort] = ftp_open_authenticated($config, $ftpPassword, $logFile);
    $browserFinalization = null;

    try {
        $remotePath = trim((string)$config['ftp_path']);
        worker_state($statusFile, $logFile, 'Selecting the shared-hosting web root');
        $configuredBase = @ftp_pwd($ftp);
        if (!is_string($configuredBase) || $configuredBase === '') {
            throw new RuntimeException('Unable to determine the FTP base directory.');
        }

        if ($remotePath !== '' && $remotePath !== '.' && strtolower($remotePath) !== 'auto') {
            if (!@ftp_chdir($ftp, $remotePath)) {
                throw new RuntimeException('The configured FTP web-root directory does not exist or is not accessible.');
            }
            $base = @ftp_pwd($ftp);
            if (!is_string($base) || $base === '') {
                throw new RuntimeException('Unable to determine the configured FTP web-root directory.');
            }
            log_line($logFile, "[MuchoGDPS] Using configured web root: {$base}\n");
        } else {
            $parsed = parse_url((string)$config['account_url']);
            $accountHost = strtolower((string)($parsed['host'] ?? ''));
            $ips = public_ipv4s($accountHost);
            if ($ips === []) {
                throw new RuntimeException('The GDPS hostname does not resolve to a public IPv4 address.');
            }
            $providerRoot = provider_default_web_root((string)$config['ftp_host']);
            if ($providerRoot !== null && @ftp_chdir($ftp, $configuredBase) && @ftp_chdir($ftp, $providerRoot)) {
                $base = @ftp_pwd($ftp);
                if (!is_string($base) || $base === '') {
                    throw new RuntimeException('Unable to determine the provider web-root directory.');
                }
                @ftp_chdir($ftp, $configuredBase);
                log_line($logFile, "[MuchoGDPS] Provider profile detected; using web root {$providerRoot}.
");
            } else {
                $base = detect_web_root(
                    $ftp,
                    $configuredBase,
                    (string)$config['account_url'],
                    $ips[0],
                    $dir,
                    $logFile
                );
            }
        }

        worker_state($statusFile, $logFile, 'Uploading MuchoCore to the shared-hosting web root');
        $uploaded = upload_tree($ftp, $localRoot, $base, $logFile);
        if (provider_requires_browser_finalization((string)$config['ftp_host'])) {
            [$browserToken, $browserExpiresAt, $browserPayloadPath] = create_browser_finalization_payload(
                $config,
                $dbPassword,
                $adminPassword,
                $dir
            );
            upload_browser_finalization_payload($ftp, $base, $browserToken, $browserPayloadPath);
            $browserFinalization = [
                'token' => $browserToken,
                'expires_at' => $browserExpiresAt,
                'url' => rtrim((string)$config['account_url'], '/')
                    . '/shared-install.php?mucho_auto=' . rawurlencode($browserToken),
            ];
            $status = read_json_file($statusFile);
            $status['status'] = 'awaiting_browser';
            $status['state'] = 'Waiting for browser finalization in the browser';
            $status['browser_finalization_url'] = $browserFinalization['url'];
            $status['browser_finalization_expires_at'] = gmdate('c', $browserExpiresAt);
            $status['browser_finalization_token_hash'] = hash('sha256', $browserToken);
            $status['heartbeat_at'] = gmdate('c');
            write_status($statusFile, $status);
            log_line($logFile, "[MuchoGDPS] InfinityFree browser security detected; final installation will continue in the user's browser.\n");
            log_line($logFile, "[MuchoGDPS] Browser finalization URL: {$browserFinalization['url']}\n");
        }
        log_line($logFile, "[MuchoGDPS] Uploaded {$uploaded} files via " . strtoupper($usedSecurity) . " port {$usedPort}.\n");
    } finally {
        @ftp_close($ftp);
    }

    log_line($logFile, "[MuchoGDPS] FTP upload complete.\n");

    // Generate and upload tenant clients before browser finalization so InfinityFree/browser
    // authorization delays cannot prevent the patched client pack from reaching the GDPS.
    $rootDir = dirname(__DIR__);
    worker_state($statusFile, $logFile, 'Generating patched Windows and Android clients');
    log_line($logFile, "[MuchoGDPS] Generating clients for this GDPS...\n");
    $androidSource = \MuchoCore\Client\DeploymentClientPack::androidSource($rootDir);
    if ($androidSource !== '') {
        $size = @filesize($androidSource);
        log_line(
            $logFile,
            "[MuchoGDPS] Android base APK source: {$androidSource}" .
            ($size !== false ? " (" . number_format((int)$size) . " bytes)" : "") . ".\n"
        );
    } else {
        log_line(
            $logFile,
            "[MuchoGDPS] Android base APK source not found in the container; checking configured and patched/apk candidates.\n"
        );
    }
    upload_client_pack_to_shared(
        $config,
        $ftpPassword,
        (string)$base,
        $rootDir,
        $dir,
        $logFile,
        $dbPassword,
        $adminPassword
    );
    log_line($logFile, "[MuchoGDPS] Tenant clients uploaded to /storage/clients/.\n");

    if ($browserFinalization !== null) {
        log_line($logFile, "[MuchoGDPS] State: Waiting for browser finalization in the browser\n");
        log_line($logFile, "[MuchoGDPS] Waiting for browser finalization; the worker will verify /health automatically.\n");
        wait_for_browser_finalization(
            (int)$browserFinalization['expires_at'],
            $logFile,
            $statusFile
        );
    } else {
        worker_state($statusFile, $logFile, 'Running the remote MuchoCore installer and health check');
        run_remote_installer($config, $dbPassword, $adminPassword, $dir . '/shared_cookie', $logFile);
    }

    worker_state($statusFile, $logFile, 'Post-processing deployment results');
    $status = read_json_file($statusFile);
    if (($status['timed_out'] ?? false) === true) {
        cleanup_secrets($dir);
        dispatch_queued_jobs(dirname(__DIR__));
        exit(124);
    }

    $status['status'] = 'post_processing';
    $status['exit_code'] = 0;
    $status['finished_at'] = null;
    write_status($statusFile, $status);

    $status = read_json_file($statusFile);
    worker_state($statusFile, $logFile, 'Finalizing deployment');
    $status['status'] = 'completed';
    $status['state'] = 'Deployment completed successfully';
    $status['exit_code'] = 0;
    $status['finished_at'] = gmdate('c');
    write_status($statusFile, $status);

    log_line($logFile, "\n[MuchoGDPS] Shared-hosting deployment completed successfully.\n");
    log_line($logFile, "[MuchoGDPS] GDPS: " . rtrim((string)$config['account_url'], '/') . "\n");
    log_line($logFile, "[MuchoGDPS] Admin: " . rtrim((string)$config['account_url'], '/') . "/admin/ (user: admin)\n");
} catch (Throwable $e) {
    worker_state($statusFile, $logFile, 'Deployment failed');
    log_line($logFile, "\n[MuchoGDPS] ERROR: " . $e->getMessage() . "\n");
    $status = read_json_file($statusFile);
    $status['status'] = 'failed';
    $status['exit_code'] = 1;
    $status['finished_at'] = gmdate('c');
    write_status($statusFile, $status);
} finally {
    cleanup_secrets($dir);
    remove_tree($dir . '/extracted');
    @unlink($dir . '/shared_archive');
    dispatch_queued_jobs(dirname(__DIR__));
}
exit(((string)(read_json_file($statusFile)['status'] ?? '')) === 'completed' ? 0 : 1);
