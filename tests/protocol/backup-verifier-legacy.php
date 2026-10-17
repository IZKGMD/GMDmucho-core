<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Backup/DatabaseBackupVerifier.php';

use MuchoCore\Backup\DatabaseBackupVerifier;

$dir = sys_get_temp_dir() . '/muchocore-archive-verifier-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Cannot create an isolated verifier fixture directory.');
}
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('Backup verifier regression: ' . $message);
    }
};
$makeArchive = static function (string $name, string $header, bool $absoluteSidecar)
    use ($dir): string {
    $file = $dir . '/' . $name . '.sql.gz';
    $gzip = gzopen($file, 'wb9');
    if (!is_resource($gzip)) {
        throw new RuntimeException('Cannot open gzip test fixture.');
    }
    gzwrite($gzip, $header
        . "CREATE TABLE sanity (id INT PRIMARY KEY, payload VARCHAR(50));\n"
        . implode("", array_map(
            static fn(int $id): string =>
                "INSERT INTO sanity VALUES (" . $id . ",'" . bin2hex(random_bytes(15)) . "');\n",
            range(1, 20)
        ))
    );
    gzclose($gzip);
    file_put_contents($file . '.sha256',
        hash_file('sha256', $file) . '  '
            . ($absoluteSidecar ? $file : basename($file)) . "\n"
    );
    return $file;
};

try {
    $native = $makeArchive('native', "-- MuchoCore database backup\n", false);
    $legacy = $makeArchive('vps-legacy', "-- MariaDB dump 10.19 Distrib 10.11\n", true);
    $sandbox = $makeArchive('vps-sandbox',
        "/*M!999999\\- enable the sandbox mode */\n-- MariaDB dump 10.19\n", true);
    $mysql = $makeArchive('mysql-native', "-- MySQL dump 10.13 Distrib 8.0\n", false);
    foreach ([$native, $legacy, $sandbox, $mysql] as $file) {
        $result = DatabaseBackupVerifier::verify($file);
        $assert($result['compressed_bytes'] > 100
                && $result['sql_bytes'] > 200
                && $result['sha256'] === hash_file('sha256', $file),
            'Valid original, MariaDB and MySQL dump formats must verify.');
    }

    $badName = $makeArchive('wrong-sidecar-name', "-- MariaDB dump 10.19\n", true);
    file_put_contents($badName . '.sha256',
        hash_file('sha256', $badName) . '  another-backup.sql.gz' . "\n");
    $rejected = false;
    try {
        DatabaseBackupVerifier::verify($badName);
    } catch (RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, 'Sidecars naming another archive must be rejected.');

    $badChecksum = $makeArchive('changed-archive', "-- MariaDB dump 10.19\n", true);
    file_put_contents($badChecksum, 'unexpected', FILE_APPEND);
    $rejected = false;
    try {
        DatabaseBackupVerifier::verify($badChecksum);
    } catch (RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, 'Valid old-style sidecar must still reject modified data.');

    $badHeader = $makeArchive('forged-header', "NOT A SQL DUMP\n", false);
    $rejected = false;
    try {
        DatabaseBackupVerifier::verify($badHeader);
    } catch (RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, 'Correct hash must not allow arbitrary compressed content.');

    $backupShell = file_get_contents(dirname(__DIR__, 2) . '/bin/mucho-db-backup.sh');
    $assert(is_string($backupShell)
        && str_contains($backupShell, "'-- MuchoCore database backup'")
        && str_contains($backupShell, 'sha256sum "$(basename "$FINAL")"')
        && str_contains($backupShell, 'sha256sum -c "$(basename "$FINAL.sha256")"'),
        'Future VPS backups must use the consistent header and basename sidecar.');
    echo "MUCHOCORE_BACKUP_LEGACY_VERIFIER_OK\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}
