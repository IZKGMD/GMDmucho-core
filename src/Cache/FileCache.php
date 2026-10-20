<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

use FilesystemIterator;
use Throwable;

/**
 * Optional disk-backed cache for GD public level lists.
 *
 * Entries are private files outside public/, sharded by a key hash.
 * Readers never observe half-written values: writers publish by rename().
 * A directory-wide flock synchronizes prefix invalidations across PHP-FPM
 * workers and the background worker. Any I/O error is a cache miss.
 */
final readonly class FileCache implements CacheInterface
{
    private const MAX_KEY_BYTES = 512;
    private const MAX_VALUE_BYTES = 5_242_880;

    public function __construct(private string $directory)
    {
    }

    public function get(string $key): ?string
    {
        if (!$this->validKey($key)) {
            return null;
        }
        $lock = $this->lock(LOCK_SH);
        if ($lock === null) {
            return null;
        }

        try {
            $file = $this->filePath($key);
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $entry = json_decode($raw, true);
            if (!is_array($entry) || ($entry['key'] ?? null) !== $key
                || !is_int($entry['expires'] ?? null)
                || !is_string($entry['value'] ?? null)
            ) {
                return null;
            }
            $expires = $entry['expires'];
            if ($expires !== 0 && $expires <= time()) {
                return null;
            }
            $data = base64_decode($entry['value'], true);
            return is_string($data) ? $data : null;
        } catch (Throwable) {
            return null;
        } finally {
            $this->unlock($lock);
        }
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        if (!$this->validKey($key) || strlen($value) > self::MAX_VALUE_BYTES) {
            return;
        }
        $lock = $this->lock(LOCK_SH);
        if ($lock === null) {
            return;
        }

        $temporary = null;
        try {
            $file = $this->filePath($key);
            $bucket = dirname($file);
            if (!is_dir($bucket)) {
                if (!@mkdir($bucket, 0700, true) && !is_dir($bucket)) {
                    return;
                }
                // The CLI worker may run as root while PHP-FPM runs as
                // www-data. Keep shared cache shards owned by the private
                // cache directory's owner, not the process that wrote first.
                $owner = @fileowner($this->directory);
                $group = @filegroup($this->directory);
                if (is_int($owner)) {
                    @chown($bucket, $owner);
                }
                if (is_int($group)) {
                    @chgrp($bucket, $group);
                }
                @chmod($bucket, 0700);
            }

            $encoded = json_encode([
                'key' => $key,
                'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
                'value' => base64_encode($value),
            ], JSON_THROW_ON_ERROR);

            // tempnam() creates a unique 0600 file on the same filesystem.
            $temporary = @tempnam($bucket, '.mc-');
            if (!is_string($temporary) || dirname($temporary) !== $bucket) {
                return;
            }
            $owner = @fileowner($bucket);
            $group = @filegroup($bucket);
            if (is_int($owner)) {
                @chown($temporary, $owner);
            }
            if (is_int($group)) {
                @chgrp($temporary, $group);
            }
            @chmod($temporary, 0600);
            if (@file_put_contents($temporary, $encoded) !== strlen($encoded)) {
                return;
            }
            @rename($temporary, $file);
        } catch (Throwable) {
            // Cache failures must never change an API response.
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            $this->unlock($lock);
            // GC is amortized across writes and restricted to one small
            // shard; this prevents expired 15-second entries accumulating
            // indefinitely without a full directory scan on normal reads.
            if (isset($bucket) && mt_rand(1, 128) === 1) {
                $this->pruneBucket($bucket);
            }
        }
    }

    public function delete(string $key): void
    {
        if (!$this->validKey($key)) {
            return;
        }
        $lock = $this->lock(LOCK_SH);
        if ($lock === null) {
            return;
        }
        try {
            @unlink($this->filePath($key));
        } finally {
            $this->unlock($lock);
        }
    }

    public function deletePrefix(string $prefix): void
    {
        // An exclusive lock prevents concurrent set() from re-publishing an
        // invalidated value while the scan is underway.
        $lock = $this->lock(LOCK_EX);
        if ($lock === null) {
            return;
        }

        try {
            $buckets = new FilesystemIterator(
                $this->directory,
                FilesystemIterator::SKIP_DOTS
            );
            foreach ($buckets as $bucket) {
                if (!$bucket->isDir() || $bucket->isLink()) {
                    continue;
                }
                $files = new FilesystemIterator(
                    $bucket->getPathname(),
                    FilesystemIterator::SKIP_DOTS
                );
                foreach ($files as $file) {
                    if (!$file->isFile() || $file->isLink()
                        || $file->getExtension() !== 'json') {
                        continue;
                    }
                    $raw = @file_get_contents($file->getPathname());
                    $entry = is_string($raw) ? json_decode($raw, true) : null;
                    if (!is_array($entry) || !is_string($entry['key'] ?? null)) {
                        continue;
                    }
                    if (str_starts_with($entry['key'], $prefix)
                        || (is_int($entry['expires'] ?? null)
                            && $entry['expires'] > 0 && $entry['expires'] <= time())
                    ) {
                        @unlink($file->getPathname());
                    }
                }
            }
        } catch (Throwable) {
            // Avoid making level changes fail if cache storage is unhealthy.
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Opportunistically reclaim expired entries. At most 256 files are
     * examined per pass; expired rows eventually drain from busy shards.
     * Locking against writers prevents removal of concurrent fresh values.
     */
    private function pruneBucket(string $bucket): void
    {
        $lock = $this->lock(LOCK_EX);
        if ($lock === null) {
            return;
        }
        try {
            if (!is_dir($bucket)) {
                return;
            }
            $seen = 0;
            $files = new FilesystemIterator($bucket, FilesystemIterator::SKIP_DOTS);
            foreach ($files as $file) {
                if (++$seen > 256) {
                    break;
                }
                if (!$file->isFile() || $file->isLink()
                    || $file->getExtension() !== 'json') {
                    continue;
                }
                $raw = @file_get_contents($file->getPathname());
                $entry = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($entry) && is_int($entry['expires'] ?? null)
                    && $entry['expires'] > 0 && $entry['expires'] <= time()) {
                    @unlink($file->getPathname());
                }
            }
        } catch (Throwable) {
            // Cache hygiene is best effort; never block an API write.
        } finally {
            $this->unlock($lock);
        }
    }

    private function validKey(string $key): bool
    {
        return $key !== '' && strlen($key) <= self::MAX_KEY_BYTES;
    }

    private function filePath(string $key): string
    {
        $hash = hash('sha256', $key);
        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }

    /** @return resource|null */
    private function lock(int $mode): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)) {
            return null;
        }
        $lock = @fopen($this->directory . '/.lock', 'c');
        if ($lock === false) {
            return null;
        }
        if (!@flock($lock, $mode)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    /** @param resource $lock */
    private function unlock(mixed $lock): void
    {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
