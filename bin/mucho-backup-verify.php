#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use PDO;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$dir = (string)(
    getenv('MUCHO_BACKUP_DIR')
    ?: ($_ENV['MUCHO_BACKUP_DIR'] ?? ($root . '/backups/database'))
);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = rtrim(substr($arg, 6), '/');
    }
}

if (!is_dir($dir)) {
    fwrite(STDERR, "Backup directory does not exist: {$dir}\n");
    exit(2);
}

$files = glob($dir . '/*.sql.gz') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    echo "NO_BACKUPS\n";
    exit(0);
}

$db = null;

try {
    $db = (new Database())->connection();
} catch (Throwable) {
}

$failures = 0;

foreach ($files as $file) {
    $name = basename($file);
    $size = (int)(filesize($file) ?: 0);
    $sha = (string)(hash_file('sha256', $file) ?: '');

    $gzipValid = false;
    $sqlValid = false;

    $output = [];
    exec(
        'gzip -t -- ' . escapeshellarg($file),
        $output,
        $gzipCode
    );
    $gzipValid = $gzipCode === 0;

    if ($gzipValid) {
        $handle = @gzopen($file, 'rb');

        if ($handle !== false) {
            $sample = gzread($handle, 65536);
            gzclose($handle);

            $sqlValid = is_string($sample) && (
                str_contains($sample, '--') ||
                stripos($sample, 'CREATE TABLE') !== false ||
                stripos($sample, 'INSERT INTO') !== false
            );
        }
    }

    printf(
        "%s size=%d sha256=%s gzip=%s sql=%s\n",
        $name,
        $size,
        $sha,
        $gzipValid ? 'OK' : 'FAIL',
        $sqlValid ? 'OK' : 'FAIL'
    );

    if (!$gzipValid || !$sqlValid) {
        $failures++;
    }

    if ($db instanceof PDO) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO mucho_backup_verifications
                    (file_name,size_bytes,sha256,gzip_valid,sql_valid)
                 VALUES
                    (:file,:size,:sha,:gzip,:sql)'
            );
            $stmt->execute([
                'file' => $name,
                'size' => $size,
                'sha' => $sha,
                'gzip' => $gzipValid ? 1 : 0,
                'sql' => $sqlValid ? 1 : 0,
            ]);
        } catch (Throwable) {
        }
    }
}

echo $failures === 0
    ? "BACKUP_VERIFY_OK\n"
    : "BACKUP_VERIFY_FAILED={$failures}\n";

exit($failures === 0 ? 0 : 1);
