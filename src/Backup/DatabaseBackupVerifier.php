<?php

declare(strict_types=1);

namespace MuchoCore\Backup;

use RuntimeException;

/**
 * Read-only verification of a MuchoCore gzip SQL backup and its SHA-256
 * sidecar. Verification intentionally does not execute SQL or connect to DB.
 */
final class DatabaseBackupVerifier
{
    /** @return array{file:string,sha256:string,compressed_bytes:int,sql_bytes:int} */
    public static function verify(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Backup archive is missing or unreadable.');
        }

        $compressedBytes = filesize($path);
        if ($compressedBytes === false || $compressedBytes < 100) {
            throw new RuntimeException('Backup archive is unexpectedly small.');
        }

        $sidecar = $path . '.sha256';
        if (!is_file($sidecar) || !is_readable($sidecar)) {
            throw new RuntimeException('Backup SHA-256 sidecar is missing.');
        }
        $sidecarText = file_get_contents($sidecar);
        if (!is_string($sidecarText) || strlen($sidecarText) > 1024) {
            throw new RuntimeException('Backup checksum sidecar is invalid.');
        }

        if (
            preg_match(
                '/^([a-f0-9]{64})[ \t]+\*?([^\r\n]+)\r?\n?$/iD',
                $sidecarText,
                $matches
            ) !== 1 ||
            $matches[2] !== basename($path)
        ) {
            throw new RuntimeException('Backup checksum or filename is invalid.');
        }

        $expected = strtolower($matches[1]);
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('Backup SHA-256 checksum mismatch.');
        }

        $gzip = gzopen($path, 'rb');
        if ($gzip === false) {
            throw new RuntimeException('Backup is not a readable gzip archive.');
        }

        $sqlBytes = 0;
        $prefix = '';

        try {
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, 65536);
                if (!is_string($chunk) || ($chunk === '' && !gzeof($gzip))) {
                    throw new RuntimeException('Backup gzip stream is corrupt.');
                }

                if (strlen($prefix) < 1024) {
                    $prefix .= substr($chunk, 0, 1024 - strlen($prefix));
                }
                $sqlBytes += strlen($chunk);
            }
        } finally {
            gzclose($gzip);
        }

        if (
            $sqlBytes === 0 ||
            !str_starts_with($prefix, '-- MuchoCore database backup')
        ) {
            throw new RuntimeException('Backup SQL header is missing.');
        }

        return [
            'file' => $path,
            'sha256' => $expected,
            'compressed_bytes' => (int)$compressedBytes,
            'sql_bytes' => $sqlBytes,
        ];
    }
}
