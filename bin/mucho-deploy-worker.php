<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

const INSTALL_REF = 'v1.1.0';

$jobId = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = substr($arg, 6);
    }
}
if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)) {
    exit(2);
}

$dir = '/var/lib/muchocore-control/deploy-jobs/' . $jobId;
$statusFile = $dir . '/status.json';
$logFile = $dir . '/log.txt';
if (!is_dir($dir)) {
    exit(2);
}

function status_read(string $path): array {
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function status_write(string $path, array $data): void {
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($path, 0600);
}
function log_line(string $path, string $line): void {
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    $statusFile = dirname($path) . '/status.json';
    if (is_file($statusFile)) {
        $status = status_read($statusFile);
        $status['heartbeat_at'] = gmdate('c');
        @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($statusFile, 0600);
    }
}
function shell_quote(string $value): string {
    return "'" . str_replace("'", "'\\''", $value) . "'";
}
function shell_command(string $command): void
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException(trim(implode("\n", $output)) ?: 'Remote command failed.');
    }
}

function upload_tenant_clients(
    string $rootDir,
    string $jobDir,
    string $domain,
    string $serverName,
    string $host,
    int $port,
    string $sshPassword,
    string $sshKey,
    string $knownHosts,
    string $adminUser,
    string $logFile
): void {
    $manifest = \MuchoCore\Client\DeploymentClientPack::prepare(
        $rootDir,
        $jobDir,
        'https://' . $domain,
        $serverName
    );

    $manifestPath = $jobDir . '/tenant-client-manifest.json';
    $tenantManifest = [
        'server_url' => (string)($manifest['server_url'] ?? ('https://' . $domain)),
        'server_name' => (string)($manifest['server_name'] ?? $serverName),
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
    ];

    file_put_contents(
        $manifestPath,
        json_encode($tenantManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    @chmod($manifestPath, 0600);

    $sshOptions =
        ' -o StrictHostKeyChecking=accept-new' .
        ' -o UserKnownHostsFile=' . shell_quote($knownHosts) .
        ' -p ' . shell_quote((string)$port);

    $remote = 'root@' . $host;

    $sshPrefix = $sshPassword !== ''
        ? 'sshpass -f ' . shell_quote($jobDir . '/ssh_password') . ' ssh'
        : 'ssh -i ' . shell_quote($sshKey) . ' -o IdentitiesOnly=yes';

    shell_command(
        $sshPrefix . $sshOptions . ' ' . shell_quote($remote) . ' ' .
        shell_quote('mkdir -p /opt/mucho-core/storage/clients && chmod 750 /opt/mucho-core/storage/clients')
    );

    $scpPrefix = $sshPassword !== ''
        ? 'sshpass -f ' . shell_quote($jobDir . '/ssh_password') . ' scp'
        : 'scp -i ' . shell_quote($sshKey) . ' -o IdentitiesOnly=yes';

    $scpOptions =
        ' -o StrictHostKeyChecking=accept-new' .
        ' -o UserKnownHostsFile=' . shell_quote($knownHosts) .
        ' -P ' . shell_quote((string)$port);

    $files = [
        (string)$manifest['windows']['path'],
        (string)$manifest['android']['path'],
        $manifestPath,
    ];

    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Generated tenant client is unavailable.');
        }
    }

    shell_command(
        $scpPrefix . $scpOptions . ' ' .
        implode(' ', array_map('shell_quote', $files)) . ' ' .
        shell_quote($remote . ':/opt/mucho-core/storage/clients/')
    );

    shell_command(
        $sshPrefix . $sshOptions . ' ' . shell_quote($remote) . ' ' .
        shell_quote(
            'chmod 640 /opt/mucho-core/storage/clients/GeometryDash-MuchoGDPS.exe ' .
            '/opt/mucho-core/storage/clients/GeometryDash-MuchoGDPS.apk ' .
            '/opt/mucho-core/storage/clients/manifest.json'
        )
    );

    log_line($logFile, "[MuchoGDPS] Generated tenant clients uploaded to {$remote}:/opt/mucho-core/storage/clients/\n");
}

function cleanup_secrets(string $dir): void {
    foreach (['ssh_password','ssh_key','admin_password','remote_env'] as $file) {
        @unlink($dir . '/' . $file);
    }
}

$status = status_read($statusFile);
$host = (string)($status['host'] ?? '');
$port = (int)($status['port'] ?? 22);
$domain = (string)($status['domain'] ?? '');
$adminUser = (string)($status['admin_user'] ?? 'admin');

$sshPassword = is_file($dir . '/ssh_password') ? (string)file_get_contents($dir . '/ssh_password') : '';
$sshKey = is_file($dir . '/ssh_key') ? (string)file_get_contents($dir . '/ssh_key') : '';
$remoteEnv = is_file($dir . '/remote_env') ? (string)file_get_contents($dir . '/remote_env') : '';

log_line($logFile, "[MuchoGDPS] Connecting with SSH...\n");

$knownHosts = $dir . '/known_hosts';
$sshBase = [
    '-o', $sshPassword !== '' ? 'BatchMode=no' : 'BatchMode=yes',
    '-o', 'StrictHostKeyChecking=accept-new',
    '-o', 'ConnectTimeout=10',
    '-o', 'ServerAliveInterval=15',
    '-o', 'ServerAliveCountMax=3',
    '-o', 'UserKnownHostsFile=' . $knownHosts,
    '-p', (string)$port,
    'root@' . $host,
];

if ($sshPassword !== '') {
    $cmd = 'sshpass -f ' . shell_quote($dir . '/ssh_password') . ' ssh';
} else {
    $cmd = 'ssh -i ' . shell_quote($dir . '/ssh_key') . ' -o IdentitiesOnly=yes';
}
foreach ($sshBase as $part) {
    $cmd .= ' ' . shell_quote($part);
}

$remoteCommand =
    'set -e; '
    . 'umask 077; '
    . 'T="/tmp/.muchocore-web-env-$$"; '
    . 'cat > "$T"; '
    . 'set -a; . "$T"; set +a; rm -f "$T"; '
    . 'curl -4fsSL --retry 3 --connect-timeout 5 --max-time 60 '
    . shell_quote('https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/' . INSTALL_REF . '/install-remote.sh')
    . ' | bash -s -- --ref=' . shell_quote(INSTALL_REF)
    . ' --domain="$MUCHO_DOMAIN" --server-name="$MUCHO_SERVER_NAME" --gd-versions="$MUCHO_GD_VERSIONS"';

$cmd .= ' ' . shell_quote($remoteCommand);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = @proc_open($cmd, $descriptors, $pipes, '/');
if (!is_resource($process)) {
    log_line($logFile, "[MuchoGDPS] ERROR: Failed to start SSH process.\n");
    $status['status'] = 'failed';
    $status['exit_code'] = 255;
    $status['finished_at'] = gmdate('c');
    status_write($statusFile, $status);
    cleanup_secrets($dir);
    exit(1);
}

fwrite($pipes[0], $remoteEnv);
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

while (true) {
    $read = [$pipes[1], $pipes[2]];
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 1, 0);
    if ($changed) {
        foreach ($read as $stream) {
            $chunk = (string)fread($stream, 8192);
            if ($chunk !== '') {
                log_line($logFile, $chunk);
            }
        }
    }

    if (!(proc_get_status($process)['running'] ?? false)) {
        foreach ([$pipes[1], $pipes[2]] as $stream) {
            $tail = (string)stream_get_contents($stream);
            if ($tail !== '') {
                log_line($logFile, $tail);
            }
        }
        break;
    }
}

fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$status = status_read($statusFile);
$status['exit_code'] = $exitCode;
$status['finished_at'] = gmdate('c');
$status['status'] = $exitCode === 0 ? 'completed' : 'failed';
status_write($statusFile, $status);

if ($exitCode === 0) {
    try {
        $rootDir = dirname(__DIR__);
        log_line($logFile, "[MuchoGDPS] Generating clients for this GDPS...\n");
        upload_tenant_clients(
            $rootDir,
            $dir,
            $domain,
            (string)($status['gdps_name'] ?? 'Mucho GDPS'),
            $host,
            $port,
            $sshPassword,
            $sshKey,
            $knownHosts,
            $adminUser,
            $logFile
        );
    } catch (Throwable $clientError) {
        log_line($logFile, "[MuchoGDPS] ERROR: Client generation/upload failed: " . $clientError->getMessage() . "\n");
        $status = status_read($statusFile);
        $status['exit_code'] = 1;
        $status['status'] = 'failed';
        $status['finished_at'] = gmdate('c');
        status_write($statusFile, $status);
        cleanup_secrets($dir);
        exit(1);
    }

    log_line($logFile, "\n[MuchoGDPS] Deployment completed successfully.\n");
    log_line($logFile, "[MuchoGDPS] GDPS: https://{$domain}\n");
    log_line($logFile, "[MuchoGDPS] Admin: https://{$domain}/admin/ (user: {$adminUser})\n");
} else {
    log_line($logFile, "\n[MuchoGDPS] Deployment failed with exit code {$exitCode}.\n");
}

cleanup_secrets($dir);
exit($exitCode === 0 ? 0 : 1);
