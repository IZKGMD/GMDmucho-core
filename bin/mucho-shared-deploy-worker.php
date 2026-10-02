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
const HTTP_TIMEOUT = 600;

$jobId = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = substr($arg, 6);
    }
}
if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)) {
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
}

function cleanup_secrets(string $dir): void {
    foreach (['ftp_password', 'shared_db_password', 'admin_password', 'shared_config', 'shared_cookie', 'shared_archive'] as $file) {
        @unlink($dir . '/' . $file);
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
        CURLOPT_TIMEOUT => 60,
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
            CURLOPT_TIMEOUT => 300,
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
        ? @ftp_ssl_connect($ip, $port, 30)
        : @ftp_connect($ip, $port, 30);

    if ($ftp === false) {
        throw new RuntimeException('Could not connect to the FTP server.');
    }

    @ftp_set_option($ftp, FTP_TIMEOUT_SEC, 30);
    @ftp_set_option($ftp, FTP_AUTOSEEK, true);
    return $ftp;
}

function ensure_remote_dir(\FTP\Connection $ftp, string $root, string $relative, array &$known): void {
    $parts = array_values(array_filter(
        explode('/', trim(str_replace('\\', '/', $relative), '/')),
        static fn(string $v): bool => $v !== ''
    ));
    $base = $root !== '' ? $root : '.';

    foreach ($parts as $part) {
        if ($part === '.' || $part === '..' || preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
            throw new RuntimeException('Unsafe remote FTP path component.');
        }

        $current = $base . '/' . $part;
        if (!isset($known[$current])) {
            if (!@ftp_chdir($ftp, $current)) {
                if (!@ftp_mkdir($ftp, $current) && !@ftp_chdir($ftp, $current)) {
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

function upload_tree(\FTP\Connection $ftp, string $localRoot, string $base, string $logFile): int {
    $knownDirs = [$base => true];
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
        if ($relative === 'public/ftp-install.php') {
            continue;
        }
        $files[] = [$item->getPathname(), $relative];
    }
    usort($files, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));

    foreach ($files as [$local, $relative]) {
        $dir = dirname($relative);
        if ($dir !== '.' && $dir !== '') {
            ensure_remote_dir($ftp, $base, $dir, $knownDirs);
        }

        $remote = str_replace('\\', '/', $relative);
        if (!@ftp_put($ftp, $remote, $local, FTP_BINARY)) {
            throw new RuntimeException('Failed to upload: ' . $relative);
        }
        $count++;
        if (($count % 25) === 0) {
            log_line($logFile, "[MuchoGDPS] Uploaded {$count} files...\\n");
        }
    }

    return $count;
}

function installer_csrf(string $html): string {
    $patterns = [
        '/name=["\']csrf["\'][^>]*value=["\']([^"\']+)["\']/i',
        '/value=["\']([^"\']+)["\'][^>]*name=["\']csrf["\']/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m) === 1 && isset($m[1]) && $m[1] !== '') {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
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
    write_status($statusFile, $status);

    $release = latest_release();
    log_line($logFile, "[MuchoGDPS] Stable release: {$release['tag']}\n");
    log_line($logFile, "[MuchoGDPS] Package: {$release['name']}\n");

    $archive = $dir . '/shared_archive';
    $bytes = download_release($release['url'], $archive);
    $actual = hash_file('sha256', $archive);
    if (!is_string($actual) || !hash_equals($release['sha256'], strtolower($actual))) {
        throw new RuntimeException('Release SHA-256 verification failed. The package was not uploaded.');
    }
    log_line($logFile, "[MuchoGDPS] Verified release SHA-256. Downloaded " . number_format($bytes) . " bytes.\n");

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

    log_line($logFile, "[MuchoGDPS] Connecting to FTP " . $config['ftp_host'] . ":" . $config['ftp_port'] . " (" . strtoupper($config['ftp_security']) . ")...\n");
    $ftp = ftp_connect_public(
        (string)$config['ftp_host'],
        (int)$config['ftp_port'],
        (string)$config['ftp_security'] === 'ftps'
    );

    try {
        @ftp_pasv($ftp, true);

        $username = (string)$config['ftp_username'];
        if (!@ftp_login($ftp, $username, $ftpPassword)) {
            throw new RuntimeException('FTP login failed. Check the FTP username/password and hosting account.');
        }

        $remotePath = trim((string)$config['ftp_path']);
        if ($remotePath !== '' && $remotePath !== '.' && !@ftp_chdir($ftp, $remotePath)) {
            throw new RuntimeException('The configured FTP remote directory does not exist or is not accessible.');
        }

        $base = @ftp_pwd($ftp);
        if (!is_string($base) || $base === '') {
            throw new RuntimeException('Unable to determine the FTP web-root directory.');
        }
        log_line($logFile, "[MuchoGDPS] FTP working directory: {$base}\n");

        $uploaded = upload_tree($ftp, $localRoot, $base, $logFile);
        log_line($logFile, "[MuchoGDPS] Uploaded {$uploaded} files.\n");
    } finally {
        @ftp_close($ftp);
    }

    log_line($logFile, "[MuchoGDPS] FTP upload complete.\n");
    run_remote_installer($config, $dbPassword, $adminPassword, $dir . '/shared_cookie', $logFile);

    $status = read_json_file($statusFile);
    $status['status'] = 'completed';
    $status['exit_code'] = 0;
    $status['finished_at'] = gmdate('c');
    write_status($statusFile, $status);
    log_line($logFile, "\n[MuchoGDPS] Shared-hosting deployment completed successfully.\n");
    log_line($logFile, "[MuchoGDPS] GDPS: " . rtrim((string)$config['account_url'], '/') . "\n");
    log_line($logFile, "[MuchoGDPS] Admin: " . rtrim((string)$config['account_url'], '/') . "/admin/ (user: admin)\n");
} catch (Throwable $e) {
    log_line($logFile, "\n[MuchoGDPS] ERROR: " . $e->getMessage() . "\n");
    $status = read_json_file($statusFile);
    $status['status'] = 'failed';
    $status['exit_code'] = 1;
    $status['finished_at'] = gmdate('c');
    write_status($statusFile, $status);
} finally {
    cleanup_secrets($dir);
    @unlink($dir . '/extracted');
    @unlink($dir . '/shared_archive');
}
exit(0);
