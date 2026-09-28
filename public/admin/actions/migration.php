<?php
declare(strict_types=1);

if (!in_array($action, ['migration-preview','migration-apply'], true)) {
    throw new RuntimeException('Invalid migration action.');
}

requirePermission('system.manage');
set_time_limit(0);

$host=trim((string)($_POST['source_host'] ?? ''));
$port=(int)($_POST['source_port'] ?? 3306);
$database=trim((string)($_POST['source_db'] ?? ''));
$user=trim((string)($_POST['source_user'] ?? ''));
$password=(string)($_POST['source_pass'] ?? '');

try {
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
        throw new RuntimeException('Invalid database host. Enter the MySQL/MariaDB host, not the GDPS website URL.');
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Database port must be between 1 and 65535.');
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database)) {
        throw new RuntimeException('Invalid database name.');
    }

    if ($user === '' || strlen($user) > 128) {
        throw new RuntimeException('Invalid database user.');
    }

    if ($password === '') {
        throw new RuntimeException('Database password cannot be empty.');
    }

    $rootDir=defined('ROOT_DIR')
        ? ROOT_DIR
        : dirname(__DIR__,3);

    $wizard=$rootDir.'/bin/mucho-migrate.php';
    if (!is_file($wizard)) {
        throw new RuntimeException('Migration Center is not available in this installation.');
    }

    $isApply=$action==='migration-apply';

    $command=
        escapeshellarg(PHP_BINARY).' '.
        escapeshellarg($wizard).' '.
        '--source-host='.escapeshellarg($host).' '.
        '--source-port='.escapeshellarg((string)$port).' '.
        '--source-db='.escapeshellarg($database).' '.
        '--source-user='.escapeshellarg($user);

    if ($isApply) {
        $command.=' --apply --confirm=MIGRATE';
    } else {
        $command.=' --json';
    }

    $environment=getenv();
    if (!is_array($environment)) {
        $environment=[];
    }

    $environment['MUCHO_MIGRATION_SOURCE_PASS']=$password;

    $pipes=[];
    $process=proc_open(
        $command,
        [
            0=>['pipe','r'],
            1=>['pipe','w'],
            2=>['pipe','w'],
        ],
        $pipes,
        $rootDir,
        $environment
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the migration process.');
    }

    fclose($pipes[0]);

    $stdout=stream_get_contents($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode=proc_close($process);

    $combined=trim(
        (string)$stdout.
        ($stderr!=='' ? PHP_EOL.PHP_EOL.'ERROR OUTPUT'.PHP_EOL.$stderr : '')
    );

    if (strlen($combined)>60000) {
        $combined=substr($combined,0,60000).PHP_EOL.'[output truncated]';
    }

    $_SESSION['migration_output']=$combined;

    if ($exitCode===0) {
        $_SESSION['migration_status']=$isApply
            ? 'Migration completed successfully.'
            : 'Source check completed. MuchoCore did not change the destination database.';
    } else {
        $_SESSION['migration_status']='Migration stopped with exit code '.$exitCode.'. Review the migration result and the verified target backup before retrying.';
        $_SESSION['migration_status_type']='error';
    }
} catch (Throwable $e) {
    $_SESSION['migration_status']=$e->getMessage();
    $_SESSION['migration_status_type']='error';
}

$return='/admin/?page=migration';
header('Location:'.$return);
exit;
