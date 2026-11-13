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

        // Older VPS backups contain an absolute path in the sidecar;
        // newer backups and PHP exports use the archive basename.
        // Only permit identifiers that exactly name this archive.
        if (
            preg_match(
                '/^([a-f0-9]{64})[ \t]+\*?([^\r\n]+)\r?\n?$/iD',
                $sidecarText,
                $matches
            ) !== 1 ||
            !in_array($matches[2], [basename($path), $path], true)
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
        $tail = '';

        try {
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, 65536);
                if (!is_string($chunk) || ($chunk === '' && !gzeof($gzip))) {
                    throw new RuntimeException('Backup gzip stream is corrupt.');
                }

                if (strlen($prefix) < 1024) {
                    $prefix .= substr($chunk, 0, 1024 - strlen($prefix));
                }
                $tail = substr($tail . $chunk, -256);
                $sqlBytes += strlen($chunk);
            }
        } finally {
            gzclose($gzip);
        }

        // The native PHP dumper writes a MuchoCore header. The VPS shell
        // script historically used mariadb-dump with its normal SQL banner,
        // optionally preceded by the MariaDB sandbox-mode comment.
        // Validate the entire gzip stream and checksum before trusting either.
        $header = $prefix;
        if (str_starts_with($header, '/*M!999999')) {
            $closing = strpos($header, '*/');
            if ($closing !== false && $closing < 150) {
                $header = ltrim(substr($header, $closing + 2), "\r\n");
            }
        }
        $validSqlHeader =
            str_starts_with($header, '-- MuchoCore database backup') ||
            preg_match('/^-- (?:MariaDB|MySQL) dump\\b/', $header) === 1;
        if ($sqlBytes === 0 || !$validSqlHeader) {
            throw new RuntimeException('Backup SQL header is missing.');
        }

        // The portable PHP exporter emits its own "Generated" line and a
        // final FOREIGN_KEY_CHECKS reset. A complete gzip containing only
        // the first portion of that SQL is NOT a restorable database backup,
        // even when an attacker/operator recomputes the SHA-256 sidecar.
        // Native MariaDB dumps have a different footer; do not reject them.
        $portableHeader = "-- MuchoCore database backup\n-- Generated: ";
        if (strncmp($prefix, $portableHeader, strlen($portableHeader)) === 0) {
            $ending = 'SET FOREIGN_KEY_CHECKS=1;';
            if (substr(rtrim($tail, " \t\r\n"), -strlen($ending)) !== $ending) {
                throw new RuntimeException('Portable SQL backup is missing its completion marker.');
            }
        }

        return [
            'file' => $path,
            'sha256' => $expected,
            'compressed_bytes' => (int)$compressedBytes,
            'sql_bytes' => $sqlBytes,
        ];
    }
}
