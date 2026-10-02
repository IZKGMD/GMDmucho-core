<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (class_exists('Dotenv\\Dotenv') && is_file($root . '/.env')) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

const JOB_ROOT = '/var/lib/muchocore-control/deploy-jobs';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function deployment_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    if (!isset($_SESSION['muchodeploy_nonce'])) {
        $_SESSION['muchodeploy_nonce'] = bin2hex(random_bytes(24));
        $_SESSION['muchodeploy_created_at'] = time();
    }
}

function same_origin_ok(): bool
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return in_array($origin, [
        'https://' . $host,
        'https://muchogdps.space',
    ], true);
}

function deployment_session_ok(): bool
{
    deployment_session_start();

    if (!same_origin_ok()) {
        return false;
    }

    $created = (int)($_SESSION['muchodeploy_created_at'] ?? 0);
    $nonce = (string)($_SESSION['muchodeploy_nonce'] ?? '');

    return $created > 0
        && $created >= time() - 3600
        && preg_match('/^[a-f0-9]{48}$/', $nonce) === 1;
}

function deploy_key_ok(): bool
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = is_string($path) ? rtrim($path, '/') : '/';

    if ($method === 'GET' && $path === '/api/deploy/session') {
        deployment_session_start();
        return same_origin_ok();
    }

    if ($method === 'GET' && $path === '/api/deploy/session/reset') {
        return same_origin_ok();
    }

    if ($method === 'GET' && in_array($path, ['/api/deploy/status', '/api/deploy/log'], true)) {
        return same_origin_ok();
    }

    if ($method === 'POST' && $path === '/api/deploy/browser-finish') {
        return true;
    }

    return deployment_session_ok();
}


function job_id(): string
{
    return gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
}

function job_dir(string $id): string
{
    if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $id)) {
        throw new InvalidArgumentException('Invalid job id.');
    }
    return JOB_ROOT . '/' . $id;
}

function write_json(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    @chmod($path, 0600);
}

function read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function valid_ipv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    $n = sprintf('%u', ip2long($ip));
    foreach ([
        [0, 16777215],
        [167772160, 184549375],
        [2130706432, 2147483647],
        [2851995648, 2852061183],
        [2886729728, 2887778303],
        [3232235520, 3232301055],
    ] as [$low, $high]) {
        if ((int)$n >= $low && (int)$n <= $high) {
            return false;
        }
    }

    return true;
}

function valid_domain(string $domain): bool
{
    return (bool)preg_match(
        '/^(?=.{1,253}$)(?!-)(?:[A-Za-z0-9-]{1,63}\\.)+[A-Za-z]{2,63}$/',
        $domain
    );
}

function shell_quote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}

function request_json(): array
{
    $raw = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function rate_limit_ok(): bool
{
    $ip = preg_replace('/[^0-9a-fA-F:._-]/', '_', client_ip()) ?: 'unknown';
    $bucket = JOB_ROOT . '/rate-' . substr(hash('sha256', $ip), 0, 24) . '.json';
    $now = time();
    $data = read_json($bucket);
    $timestamps = is_array($data['timestamps'] ?? null) ? $data['timestamps'] : [];
    $timestamps = array_values(array_filter(
        $timestamps,
        static fn($t): bool => is_int($t) && $t > $now - 1800
    ));

    if (count($timestamps) >= 3) {
        return false;
    }

    $timestamps[] = $now;
    write_json($bucket, ['timestamps' => $timestamps]);
    return true;
}

function count_running_jobs(): int
{
    $count = 0;
    foreach (glob(JOB_ROOT . '/*/status.json') ?: [] as $statusFile) {
        if ((read_json($statusFile)['status'] ?? '') === 'running') {
            $count++;
        }
    }
    return $count;
}

function cleanup_job_secrets(string $dir): void
{
    foreach (['ssh_password', 'ssh_key', 'admin_password', 'remote_env', 'ftp_password', 'shared_db_password', 'shared_config', 'shared_cookie', 'shared_archive'] as $file) {
        @unlink($dir . '/' . $file);
    }
}

function deployment_php_cli(): string
{
    $candidates = [];

    if (defined('PHP_BINDIR')) {
        $candidates[] = PHP_BINDIR . '/php';
    }

    $env = trim((string)(getenv('MUCHO_PHP_CLI') ?: ''));
    if ($env !== '') {
        $candidates[] = $env;
    }

    $candidates[] = '/usr/local/bin/php';

    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('PHP CLI binary is unavailable for deployment workers.');
}

function deployment_queue_dispatch(): void
{
    $worker = dirname(__DIR__) . '/bin/mucho-shared-deploy-worker.php';
    if (!is_file($worker)) {
        return;
    }

    try {
        $phpCli = deployment_php_cli();
    } catch (Throwable) {
        return;
    }

    $cmd = 'nohup ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($worker) . ' --dispatch-queue >/dev/null 2>&1 &';
    @exec($cmd);
}

function count_queued_jobs(): int
{
    $count = 0;
    foreach (glob(JOB_ROOT . '/*/status.json') ?: [] as $statusFile) {
        $status = read_json($statusFile);
        if (($status['status'] ?? '') === 'queued') {
            $count++;
        }
    }
    return $count;
}

function reset_deployment_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                (string)$params['path'],
                (string)$params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        session_destroy();
    }
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST' && $path === '/api/deploy/browser-finish') {
    $data = request_json();
    $jobId = trim((string)($data['job_id'] ?? ''));
    $token = trim((string)($data['token'] ?? ''));
    $ok = filter_var($data['ok'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)
        || !preg_match('/^[a-f0-9]{64}$/', $token)
        || $ok === null
    ) {
        json_response(['ok' => false, 'error' => 'Invalid browser finalization callback.'], 400);
    }

    $jobDir = JOB_ROOT . '/' . $jobId;
    $statusFile = $jobDir . '/status.json';
    if (!is_file($statusFile)) {
        json_response(['ok' => false, 'error' => 'Deployment job not found.'], 404);
    }

    $status = read_json($statusFile);
    $expectedHash = (string)($status['browser_finalization_token_hash'] ?? '');
    if ($expectedHash === '' || !hash_equals($expectedHash, hash('sha256', $token))) {
        json_response(['ok' => false, 'error' => 'Invalid browser finalization token.'], 403);
    }

    if (!in_array((string)($status['status'] ?? ''), ['awaiting_browser', 'completed'], true)) {
        json_response(['ok' => true, 'already_finalized' => true]);
    }

    $status['status'] = $ok ? 'completed' : 'failed';
    $status['exit_code'] = $ok ? 0 : 1;
    $status['finished_at'] = gmdate('c');
    unset($status['browser_finalization_token_hash']);
    write_json($statusFile, $status);

    @file_put_contents(
        $jobDir . '/log.txt',
        $ok
            ? "[MuchoGDPS] Browser finalization completed successfully.\n"
            : "[MuchoGDPS] Browser finalization reported an installation error.\n",
        FILE_APPEND | LOCK_EX
    );

    json_response(['ok' => true, 'status' => $status['status']]);
}

if (!deploy_key_ok()) {
    json_response(['ok' => false, 'error' => 'Deployment session is missing or expired. Refresh the installer page and try again.'], 403);
}

if ($method === 'GET' && $path === '/api/deploy/session') {
    json_response(['ok' => true, 'expires_in' => 3600, 'stall_timeout' => 60]);
}

if ($method === 'GET' && $path === '/api/deploy/session/reset') {
    reset_deployment_session();
    json_response(['ok' => true, 'reset' => true]);
}

if ($method === 'POST' && $path === '/api/deploy/start') {
    if (!is_dir(JOB_ROOT) && !@mkdir(JOB_ROOT, 0700, true) && !is_dir(JOB_ROOT)) {
        json_response(['ok' => false, 'error' => 'Deployment storage is unavailable.'], 500);
    }
    @chmod(JOB_ROOT, 0700);

    if (!rate_limit_ok()) {
        json_response(['ok' => false, 'error' => 'Too many deployment attempts from this client. Try again later.'], 429);
    }

    deployment_queue_dispatch();

    $max = max(1, (int)($_ENV['MUCHO_DEPLOY_MAX_CONCURRENT'] ?? getenv('MUCHO_DEPLOY_MAX_CONCURRENT') ?: 2));

    $data = request_json();
    $deploymentType = strtolower(trim((string)($data['type'] ?? 'vps')));

    if ($deploymentType === 'shared') {
        $ftpHost = trim((string)($data['ftp_host'] ?? ''));
        $ftpPort = (int)($data['ftp_port'] ?? 21);
        $ftpUsername = trim((string)($data['ftp_username'] ?? ''));
        $ftpPassword = (string)($data['ftp_password'] ?? '');
        $ftpSecurity = strtolower(trim((string)($data['ftp_security'] ?? 'ftp')));
        $ftpPath = trim((string)($data['ftp_path'] ?? ''));
        $accountUrl = rtrim(trim((string)($data['account_url'] ?? '')), '/');
        $dbHost = trim((string)($data['db_host'] ?? 'localhost'));
        $dbPort = (int)($data['db_port'] ?? 3306);
        $dbName = trim((string)($data['db_name'] ?? ''));
        $dbUser = trim((string)($data['db_user'] ?? ''));
        $dbPassword = (string)($data['db_password'] ?? '');
        $adminPassword = (string)($data['admin_password'] ?? '');

        if ($ftpHost === '' || strlen($ftpHost) > 253 || !preg_match('/^(?=.{1,253}$)(?!-)(?:[A-Za-z0-9-]{1,63}\\.)+[A-Za-z0-9-]{2,63}$/', $ftpHost)) {
            json_response(['ok' => false, 'error' => 'Enter a valid FTP hostname.'], 422);
        }
        if ($ftpPort < 0 || $ftpPort > 65535) {
            json_response(['ok' => false, 'error' => 'Invalid FTP port.'], 422);
        }
        if ($ftpUsername === '' || strlen($ftpUsername) > 128 || preg_match('/[\\x00-\\x1F\\x7F]/', $ftpUsername) === 1) {
            json_response(['ok' => false, 'error' => 'Invalid FTP username.'], 422);
        }
        if ($ftpPassword === '') {
            json_response(['ok' => false, 'error' => 'Enter the FTP password.'], 422);
        }
        if (!in_array($ftpSecurity, ['auto', 'ftp', 'ftps'], true)) {
            json_response(['ok' => false, 'error' => 'Choose Auto Detect, FTP or FTPS.'], 422);
        }
        if ($ftpPath !== '' && (strlen($ftpPath) > 512 || preg_match('/[\\x00]/', $ftpPath) === 1 || preg_match('#(^|/)\\.\\.(/|$)#', str_replace('\\\\', '/', $ftpPath)) === 1)) {
            json_response(['ok' => false, 'error' => 'Invalid remote FTP directory.'], 422);
        }
        $parts = parse_url($accountUrl);
        $accountHost = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || $accountHost === '' || !preg_match('/^(?=.{1,253}$)(?!-)(?:[A-Za-z0-9-]{1,63}\\.)+[A-Za-z0-9-]{2,63}$/', $accountHost) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['path']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            json_response(['ok' => false, 'error' => 'GDPS address must be a public HTTPS hostname such as https://gdps.example.com.'], 422);
        }
        if ($dbHost === '' || strlen($dbHost) > 253 || preg_match('/[\\x00-\\x1F\\x7F]/', $dbHost) === 1) {
            json_response(['ok' => false, 'error' => 'Invalid database host.'], 422);
        }
        if ($dbPort < 1 || $dbPort > 65535 || $dbName === '' || strlen($dbName) > 128 || preg_match('/[\\x00-\\x1F\\x7F]/', $dbName) === 1 || $dbUser === '' || strlen($dbUser) > 128 || preg_match('/[\\x00-\\x1F\\x7F]/', $dbUser) === 1) {
            json_response(['ok' => false, 'error' => 'Invalid database connection values.'], 422);
        }
        if ($dbPassword === '') {
            json_response(['ok' => false, 'error' => 'Enter the database password.'], 422);
        }

        $generatedAdminPassword = false;
        if ($adminPassword === '') {
            $adminPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $generatedAdminPassword = true;
        }
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 200) {
            json_response(['ok' => false, 'error' => 'MuchoCore admin password must be 12–200 characters.'], 422);
        }

        $id = job_id();
        $dir = job_dir($id);
        if (!@mkdir($dir, 0700, true)) {
            json_response(['ok' => false, 'error' => 'Could not create the deployment job.'], 500);
        }

        $queueFull = count_running_jobs() >= $max;
        $status = [
            'id' => $id,
            'type' => 'shared',
            'status' => $queueFull ? 'queued' : 'starting',
            'created_at' => gmdate('c'),
            'queued_at' => $queueFull ? gmdate('c') : null,
            'heartbeat_at' => $queueFull ? null : gmdate('c'),
            'ftp_host' => $ftpHost,
            'ftp_port' => $ftpPort,
            'ftp_security' => $ftpSecurity,
            'ftp_path' => $ftpPath,
            'domain' => $accountHost,
            'admin_user' => 'admin',
            'generated_admin_password' => $generatedAdminPassword,
            'exit_code' => null,
        ];
        write_json($dir . '/status.json', $status);
        file_put_contents($dir . '/ftp_password', $ftpPassword, LOCK_EX);
        @chmod($dir . '/ftp_password', 0600);
        $config = [
            'ftp_host' => $ftpHost,
            'ftp_port' => $ftpPort,
            'ftp_username' => $ftpUsername,
            'ftp_security' => $ftpSecurity,
            'ftp_path' => $ftpPath,
            'account_url' => $accountUrl,
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'admin_user' => 'admin',
        ];
        write_json($dir . '/shared_config', $config);
        file_put_contents($dir . '/shared_db_password', $dbPassword, LOCK_EX);
        @chmod($dir . '/shared_db_password', 0600);
        file_put_contents($dir . '/admin_password', $adminPassword, LOCK_EX);
        @chmod($dir . '/admin_password', 0600);
        file_put_contents($dir . '/log.txt',
            "[MuchoGDPS] Shared-hosting deployment job {$id}\n"
            . "[MuchoGDPS] FTP target: {$ftpHost}:" . ($ftpPort > 0 ? $ftpPort : 0) . " ({$ftpSecurity})\n"
            . "[MuchoGDPS] Remote directory: " . ($ftpPath !== '' ? $ftpPath : 'auto') . "\n"
            . "[MuchoGDPS] GDPS: {$accountUrl}\n"
            . ($queueFull
                ? "[MuchoGDPS] Waiting in deployment queue...\n"
                : "[MuchoGDPS] Starting deployment worker...\n"),
            LOCK_EX);
        @chmod($dir . '/log.txt', 0600);

        $worker = $root . '/bin/mucho-shared-deploy-worker.php';
        if (!is_file($worker)) {
            cleanup_job_secrets($dir);
            @unlink($dir . '/status.json');
            @unlink($dir . '/log.txt');
            @rmdir($dir);
            json_response(['ok' => false, 'error' => 'Shared-hosting deployment worker is not installed.'], 500);
        }

        $queuePosition = 0;
        if ($queueFull) {
            $queuePosition = count_queued_jobs();
            deployment_queue_dispatch();
            $fresh = read_json($dir . '/status.json');
            if (($fresh['status'] ?? '') !== 'running') {
                $queuePosition = count_queued_jobs();
            }
        } else {
            try {
                $phpCli = deployment_php_cli();
            } catch (Throwable) {
                cleanup_job_secrets($dir);
                @unlink($dir . '/status.json');
                @unlink($dir . '/log.txt');
                @rmdir($dir);
                json_response(['ok' => false, 'error' => 'PHP CLI is unavailable for the shared-hosting deployment worker.'], 500);
            }

            $cmd = 'nohup ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($worker)
                . ' --job=' . escapeshellarg($id)
                . ' >> ' . escapeshellarg($dir . '/log.txt') . ' 2>&1 & echo $!';
            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $pid = (int)($output[0] ?? 0);
            if ($exit !== 0 || $pid <= 0) {
                cleanup_job_secrets($dir);
                @unlink($dir . '/status.json');
                @unlink($dir . '/log.txt');
                @rmdir($dir);
                json_response(['ok' => false, 'error' => 'Could not start the shared-hosting deployment worker.'], 500);
            }

            $status['status'] = 'running';
            $status['pid'] = $pid;
            $status['heartbeat_at'] = gmdate('c');
            write_json($dir . '/status.json', $status);
        }

        $_SESSION['muchodeploy_job_id'] = $id;
        json_response([
            'ok' => true,
            'job_id' => $id,
            'status' => $queueFull ? 'queued' : 'running',
            'queue_position' => $queueFull ? $queuePosition : 0,
            'admin_user' => 'admin',
            'admin_password' => $generatedAdminPassword ? $adminPassword : null,
        ], 201);
    }

    $host = trim((string)($data['host'] ?? ''));
    $port = (int)($data['port'] ?? 22);
    $username = trim((string)($data['username'] ?? 'root'));
    $sshPassword = (string)($data['password'] ?? '');
    $sshKey = (string)($data['private_key'] ?? '');
    $domain = strtolower(trim((string)($data['domain'] ?? '')));
    $adminUser = trim((string)($data['admin_user'] ?? 'admin'));
    $adminPassword = (string)($data['admin_password'] ?? '');

    if (!valid_ipv4($host)) {
        json_response(['ok' => false, 'error' => 'Enter a public IPv4 address for the VPS.'], 422);
    }
    if ($port < 1 || $port > 65535) {
        json_response(['ok' => false, 'error' => 'Invalid SSH port.'], 422);
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/', $username) || $username !== 'root') {
        json_response(['ok' => false, 'error' => 'The web installer currently requires SSH access as root.'], 422);
    }
    if ($sshPassword === '' && trim($sshKey) === '') {
        json_response(['ok' => false, 'error' => 'Provide an SSH password or a private key.'], 422);
    }
    if (!valid_domain($domain)) {
        json_response(['ok' => false, 'error' => 'Enter a public GDPS hostname such as gdps.example.com.'], 422);
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/', $adminUser)) {
        json_response(['ok' => false, 'error' => 'Invalid MuchoCore admin username.'], 422);
    }

    $generatedAdminPassword = false;
    if ($adminPassword === '') {
        $adminPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $generatedAdminPassword = true;
    }
    if (strlen($adminPassword) < 12 || strlen($adminPassword) > 200) {
        json_response(['ok' => false, 'error' => 'MuchoCore admin password must be 12–200 characters.'], 422);
    }

    $id = job_id();
    $dir = job_dir($id);
    if (!@mkdir($dir, 0700, true)) {
        json_response(['ok' => false, 'error' => 'Could not create the deployment job.'], 500);
    }

    $queueFull = count_running_jobs() >= $max;
    $status = [
        'id' => $id,
        'type' => 'vps',
        'status' => $queueFull ? 'queued' : 'starting',
        'created_at' => gmdate('c'),
        'queued_at' => $queueFull ? gmdate('c') : null,
        'heartbeat_at' => $queueFull ? null : gmdate('c'),
        'host' => $host,
        'port' => $port,
        'domain' => $domain,
        'admin_user' => $adminUser,
        'generated_admin_password' => $generatedAdminPassword,
        'exit_code' => null,
    ];
    write_json($dir . '/status.json', $status);

    if ($sshPassword !== '') {
        file_put_contents($dir . '/ssh_password', $sshPassword, LOCK_EX);
        @chmod($dir . '/ssh_password', 0600);
    }
    if ($sshKey !== '') {
        file_put_contents($dir . '/ssh_key', str_replace(["\r\n", "\r"], "\n", $sshKey), LOCK_EX);
        @chmod($dir . '/ssh_key', 0600);
    }
    file_put_contents($dir . '/admin_password', $adminPassword, LOCK_EX);
    @chmod($dir . '/admin_password', 0600);

    $env = "MUCHO_DOMAIN=" . shell_quote($domain) . "\n"
         . "MUCHO_ADMIN_USER=" . shell_quote($adminUser) . "\n"
         . "MUCHO_ADMIN_PASSWORD=" . shell_quote($adminPassword) . "\n"
         . "MUCHO_TRANSPORT_MODE='direct'\n"
         . "MUCHO_GD_VERSIONS='all'\n";
    file_put_contents($dir . '/remote_env', $env, LOCK_EX);
    @chmod($dir . '/remote_env', 0600);

    file_put_contents(
        $dir . '/log.txt',
        "[MuchoGDPS] Deployment job {$id}\n"
        . "[MuchoGDPS] Target: {$host}:{$port}\n"
        . "[MuchoGDPS] Domain: {$domain}\n"
        . "[MuchoGDPS] Compatibility: all\n"
        . "[MuchoGDPS] Waiting for SSH connection...\n",
        LOCK_EX
    );
    @chmod($dir . '/log.txt', 0600);

    $worker = $root . '/bin/mucho-deploy-worker.php';
    if (!is_file($worker)) {
        cleanup_job_secrets($dir);
        json_response(['ok' => false, 'error' => 'Deployment worker is not installed.'], 500);
    }

    $queuePosition = 0;
    if ($queueFull) {
        $queuePosition = count_queued_jobs();
        deployment_queue_dispatch();
        $fresh = read_json($dir . '/status.json');
        if (($fresh['status'] ?? '') !== 'running') {
            $queuePosition = count_queued_jobs();
        }
    } else {
        $cmd = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker)
            . ' --job=' . escapeshellarg($id) . ' > /dev/null 2>&1 & echo $!';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $pid = (int)($output[0] ?? 0);

        if ($exit !== 0 || $pid <= 0) {
            cleanup_job_secrets($dir);
            @unlink($dir . '/status.json');
            @unlink($dir . '/log.txt');
            @rmdir($dir);
            json_response(['ok' => false, 'error' => 'Could not start the deployment worker.'], 500);
        }

        $status['status'] = 'running';
        $status['pid'] = $pid;
        $status['heartbeat_at'] = gmdate('c');
        write_json($dir . '/status.json', $status);
    }

    $_SESSION['muchodeploy_job_id'] = $id;
    json_response([
        'ok' => true,
        'job_id' => $id,
        'status' => $queueFull ? 'queued' : 'running',
        'queue_position' => $queueFull ? $queuePosition : 0,
        'admin_user' => $adminUser,
        'admin_password' => $generatedAdminPassword ? $adminPassword : null,
    ], 201);
}

if (($method === 'GET' || $method === 'POST') && in_array($path, ['/api/deploy/status', '/api/deploy/log'], true)) {
    $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
    try {
        $dir = job_dir($id);
    } catch (Throwable) {
        json_response(['ok' => false, 'error' => 'Invalid job id.'], 422);
    }

    if (!is_dir($dir)) {
        json_response(['ok' => false, 'error' => 'Deployment job not found.'], 404);
    }

    $status = read_json($dir . '/status.json');
    if (session_status() === PHP_SESSION_ACTIVE && ($status['status'] ?? '') !== 'failed') {
        $_SESSION['muchodeploy_job_id'] = $id;
    }

    deployment_queue_dispatch();
    $status = read_json($dir . '/status.json');

    if ($path === '/api/deploy/log') {
        $log = is_file($dir . '/log.txt') ? (string)file_get_contents($dir . '/log.txt') : '';
        if (strlen($log) > 500000) {
            $log = substr($log, -500000);
        }
        json_response([
            'ok' => true,
            'job_id' => $id,
            'status' => $status['status'] ?? 'unknown',
            'queue_position' => ($status['status'] ?? '') === 'queued' ? count_queued_jobs() : 0,
            'heartbeat_at' => $status['heartbeat_at'] ?? null,
            'timed_out' => (bool)($status['timed_out'] ?? false),
            'log' => $log,
            'exit_code' => $status['exit_code'] ?? null,
        ]);
    }

    json_response([
        'ok' => true,
        'job_id' => $id,
        'status' => $status['status'] ?? 'unknown',
        'queue_position' => ($status['status'] ?? '') === 'queued' ? count_queued_jobs() : 0,
        'heartbeat_at' => $status['heartbeat_at'] ?? null,
        'timed_out' => (bool)($status['timed_out'] ?? false),
        'exit_code' => $status['exit_code'] ?? null,
        'finished_at' => $status['finished_at'] ?? null,
        'domain' => $status['domain'] ?? null,
        'admin_user' => $status['admin_user'] ?? null,
    ]);
}

json_response(['ok' => false, 'error' => 'Not found.'], 404);
