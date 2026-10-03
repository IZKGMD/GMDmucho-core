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

function deployment_session_job_ok(string $jobId): bool
{
    return deployment_session_ok()
        && hash_equals(
            (string)($_SESSION['muchodeploy_job_id'] ?? ''),
            $jobId
        );
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
        return deployment_session_ok();
    }

    if (($method === 'GET' || $method === 'POST') && in_array($path, ['/api/deploy/client-pack', '/api/deploy/details'], true)) {
        return same_origin_ok();
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

    try {
        $startLock = deployment_start_lock();
        register_shutdown_function(static function () use (&$startLock): void {
            release_deployment_start_lock($startLock);
        });
    } catch (Throwable) {
        json_response(['ok' => false, 'error' => 'Deployment start is temporarily unavailable.'], 503);
    }

    if (!rate_limit_ok()) {
        @flock($startLock, LOCK_UN);
        @fclose($startLock);
        json_response(['ok' => false, 'error' => 'Too many deployment attempts from this client. Try again later.'], 429);
    }

    $max = max(1, (int)($_ENV['MUCHO_DEPLOY_MAX_CONCURRENT'] ?? getenv('MUCHO_DEPLOY_MAX_CONCURRENT') ?: 2));

    $data = request_json();
    $gdpsName = trim((string)($data['gdps_name'] ?? ''));
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
        $adminUser = trim((string)($data['admin_user'] ?? 'admin'));
        $adminPassword = (string)($data['admin_password'] ?? '');
        $adminPasswordConfirm = (string)($data['admin_password_confirm'] ?? '');

        if ($gdpsName === '' || mb_strlen($gdpsName, 'UTF-8') > 64 || preg_match('/[\\x00-\\x1F\\x7F]/u', $gdpsName) === 1) {
            json_response(['ok' => false, 'error' => 'Enter a GDPS name up to 64 characters.'], 422);
        }

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
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/', $adminUser)) {
            json_response(['ok' => false, 'error' => 'Invalid MuchoCore admin username. Use 1–32 letters, numbers, underscores or hyphens, starting with a letter or underscore.'], 422);
        }

        $generatedAdminPassword = false;
        if ($adminPassword === '') {
            $adminPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $generatedAdminPassword = true;
        } elseif (!hash_equals($adminPassword, $adminPasswordConfirm)) {
            json_response(['ok' => false, 'error' => 'Admin passwords do not match.'], 422);
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
            'state' => $queueFull ? 'Waiting for deployment slot' : 'Starting deployment worker',
            'created_at' => gmdate('c'),
            'queued_at' => $queueFull ? gmdate('c') : null,
            'heartbeat_at' => $queueFull ? null : gmdate('c'),
            'gdps_name' => $gdpsName,
            'ftp_host' => $ftpHost,
            'ftp_port' => $ftpPort,
            'ftp_security' => $ftpSecurity,
            'ftp_path' => $ftpPath,
            'domain' => $accountHost,
            'admin_user' => $adminUser,
            'generated_admin_password' => $generatedAdminPassword,
            'exit_code' => null,
        ];
        write_json($dir . '/status.json', $status);
        file_put_contents($dir . '/ftp_password', $ftpPassword, LOCK_EX);
        @chmod($dir . '/ftp_password', 0600);
        $config = [
            'gdps_name' => $gdpsName,
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
            'admin_user' => $adminUser,
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
            $queuePosition = queued_job_position($id);
            release_deployment_start_lock($startLock);
            deployment_queue_dispatch();
            $fresh = read_json($dir . '/status.json');
            if (($fresh['status'] ?? '') !== 'running') {
                $queuePosition = queued_job_position($id);
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

            release_deployment_start_lock($startLock);
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
            'admin_user' => $adminUser,
            'admin_password' => $generatedAdminPassword ? $adminPassword : null,
        ], 201);
    }

    $gdpsName = trim((string)($data['gdps_name'] ?? ''));
    $host = trim((string)($data['host'] ?? ''));
    $port = (int)($data['port'] ?? 22);
    $username = trim((string)($data['username'] ?? 'root'));
    $sshPassword = (string)($data['password'] ?? '');
    $sshKey = (string)($data['private_key'] ?? '');
    $domain = strtolower(trim((string)($data['domain'] ?? '')));
    $adminUser = trim((string)($data['admin_user'] ?? 'admin'));
    $adminPassword = (string)($data['admin_password'] ?? '');

    if ($gdpsName === '' || mb_strlen($gdpsName, 'UTF-8') > 64 || preg_match('/[\x00-\x1F\x7F]/u', $gdpsName) === 1) {
        json_response(['ok' => false, 'error' => 'Enter a GDPS name up to 64 characters.'], 422);
    }

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
        'state' => $queueFull ? 'Waiting for deployment slot' : 'Starting deployment worker',
        'created_at' => gmdate('c'),
        'queued_at' => $queueFull ? gmdate('c') : null,
        'heartbeat_at' => $queueFull ? null : gmdate('c'),
        'gdps_name' => $gdpsName,
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
         . "MUCHO_SERVER_NAME=" . shell_quote($gdpsName) . "\n"
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

if ($method === 'GET' && $path === '/api/deploy/details') {
    $id = trim((string)($_GET['id'] ?? ''));
    $token = trim((string)($_GET['token'] ?? ''));

    try {
        $dir = job_dir($id);
    } catch (Throwable) {
        json_response(['ok' => false, 'error' => 'Invalid deployment job.'], 422);
    }

    if (!is_dir($dir) || !is_file($dir . '/status.json')) {
        json_response(['ok' => false, 'error' => 'Deployment job not found.'], 404);
    }

    $status = read_json($dir . '/status.json');
    if (($status['status'] ?? '') !== 'completed') {
        json_response(['ok' => false, 'error' => 'The deployment is not complete yet.'], 409);
    }

    $storedToken = is_file($dir . '/deployment-details-token')
        ? trim((string)@file_get_contents($dir . '/deployment-details-token'))
        : '';

    if (
        !preg_match('/^[a-f0-9]{64}$/', $token) ||
        $storedToken === '' ||
        !hash_equals($storedToken, $token)
    ) {
        json_response(['ok' => false, 'error' => 'Invalid deployment details token.'], 403);
    }

    $file = $dir . '/deployment-details.txt';
    if (!is_file($file) || !is_readable($file)) {
        json_response(['ok' => false, 'error' => 'Deployment details are unavailable.'], 404);
    }

    $size = filesize($file);
    if ($size === false || $size < 1) {
        json_response(['ok' => false, 'error' => 'Deployment details are unavailable.'], 404);
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="MuchoGDPS-Deployment-Details.txt"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');

    if (@readfile($file) === false) {
        http_response_code(500);
        exit('Unable to read deployment details.');
    }
    exit;
}

if (($method === 'GET' || $method === 'POST') && $path === '/api/deploy/client-pack') {
    handle_client_pack_request($root);
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

    if (!deployment_session_job_ok($id)) {
        json_response(['ok' => false, 'error' => 'Deployment session does not own this job.'], 403);
    }

    $status = read_json($dir . '/status.json');

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
            'queue_position' => ($status['status'] ?? '') === 'queued' ? queued_job_position($id) : 0,
            'heartbeat_at' => $status['heartbeat_at'] ?? null,
            'state' => $status['state'] ?? null,
            'timed_out' => (bool)($status['timed_out'] ?? false),
            'log' => $log,
            'exit_code' => $status['exit_code'] ?? null,
        ]);
    }

    json_response([
        'ok' => true,
        'job_id' => $id,
        'status' => $status['status'] ?? 'unknown',
        'queue_position' => ($status['status'] ?? '') === 'queued' ? queued_job_position($id) : 0,
        'heartbeat_at' => $status['heartbeat_at'] ?? null,
        'state' => $status['state'] ?? null,
        'timed_out' => (bool)($status['timed_out'] ?? false),
        'exit_code' => $status['exit_code'] ?? null,
        'finished_at' => $status['finished_at'] ?? null,
        'domain' => $status['domain'] ?? null,
        'admin_user' => $status['admin_user'] ?? null,
        'client_pack_token' => ($status['status'] ?? '') === 'completed'
            ? (function () use ($dir): ?string {
                try {
                    return \MuchoCore\Client\DeploymentClientPack::token($dir);
                } catch (Throwable) {
                    return null;
                }
            })()
            : null,
        'deployment_details_token' => ($status['status'] ?? '') === 'completed'
            ? (function () use ($dir): ?string {
                try {
                    return \MuchoCore\Deployment\DeploymentDetailsExporter::token($dir);
                } catch (Throwable) {
                    return null;
                }
            })()
            : null,
    ]);
}

json_response(['ok' => false, 'error' => 'Not found.'], 404);
