<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Backup\DatabaseBackupVerifier;

$file = $argv[1] ?? '';
if (
    $file === '' ||
    in_array($file, ['--help', '-h'], true) ||
    count($argv) !== 2
) {
    fwrite(STDERR, "Usage: php bin/mucho-verify-backup.php BACKUP.sql.gz\n");
    exit(2);
}

try {
    $result = DatabaseBackupVerifier::verify($file);
    echo "BACKUP_VERIFIED\n";
    echo 'FILE=' . $result['file'] . PHP_EOL;
    echo 'SHA256=' . $result['sha256'] . PHP_EOL;
    echo 'COMPRESSED_BYTES=' . $result['compressed_bytes'] . PHP_EOL;
    echo 'SQL_BYTES=' . $result['sql_bytes'] . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'BACKUP_INVALID: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
