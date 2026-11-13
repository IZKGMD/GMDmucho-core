<?php

declare(strict_types=1);

namespace MuchoCore\Security;

final readonly class RateLimiter implements RateLimitBackend
{
    private const AUTO_CLEANUP_INTERVAL = 128;
    private const DEFAULT_STALE_AFTER = 3600;
    // Buckets written before the per-window metadata upgrade must retain the
    // longest existing security budget (24-hour registration device quota).
    private const LEGACY_BUCKET_WINDOW = 86400;
    private const DEFAULT_MAX_ENTRIES = 64;
    private const MAX_CLEANUP_SCAN = 512;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory !== null && trim($directory) !== ''
            ? $directory
            : dirname(__DIR__, 2) . '/storage/control/rate-limit';
    }

    public function allowStrict(
        string $key,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($limit < 1 || $windowSeconds < 1) {
            return false;
        }

        $this->maybeCleanup();

        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0700, true) &&
            !is_dir($this->directory)
        ) {
            return false;
        }

        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        if (is_link($file)) {
            return false;
        }
        $fp = @fopen($file, 'c+');

        if ($fp === false) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);

            $state = is_string($raw) && $raw !== ''
                ? json_decode($raw, true)
                : null;

            // A torn or invalid on-disk counter must never be interpreted
            // as a fresh budget. Only genuinely empty new buckets reset.
            if ($raw === false || ($raw !== '' && (
                !is_array($state) ||
                !isset($state['start'], $state['count']) ||
                !is_int($state['start']) ||
                !is_int($state['count']) ||
                $state['start'] < 0 ||
                $state['count'] < 0 ||
                $state['count'] === PHP_INT_MAX
            ))) {
                return false;
            }

            $now = time();
            $start = is_array($state) ? $state['start'] : 0;
            $count = is_array($state) ? $state['count'] : 0;
            // Allow minor wall-clock corrections without changing quota.
            if ($start > $now + $windowSeconds) {
                return false;
            }
            $start = min($start, $now);

            if ($start <= 0 || ($now - $start) >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }

            $count++;

            rewind($fp);
            if (!ftruncate($fp, 0)) {
                return false;
            }

            $payload = json_encode([
                'start' => $start,
                'count' => $count,
                'window' => $windowSeconds,
            ], JSON_THROW_ON_ERROR);

            $written = fwrite($fp, $payload);
            if ($written !== strlen($payload)) {
                return false;
            }

            if (!fflush($fp)) {
                return false;
            }

            return $count <= $limit;
        } catch (\Throwable) {
            return false;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Remove stale bucket files left behind by expired rate-limit windows.
     *
     * Only files older than the supplied age are eligible, so an active
     * bucket that is still being written remains untouched. Cleanup is
     * bounded to avoid turning a request into an unbounded directory scan.
     */
    public function cleanup(
        int $staleAfterSeconds = self::DEFAULT_STALE_AFTER,
        int $maxEntries = self::DEFAULT_MAX_ENTRIES
    ): int {
        if ($staleAfterSeconds < 1 || $maxEntries < 1 || !is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;
        $cutoff = time() - $staleAfterSeconds;
        $scanned = 0;

        try {
            $iterator = new \FilesystemIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS
            );

            foreach ($iterator as $file) {
                if (++$scanned > self::MAX_CLEANUP_SCAN) {
                    break;
                }

                if (
                    !$file->isFile() ||
                    $file->isLink() ||
                    $file->getExtension() !== 'json'
                ) {
                    continue;
                }

                if ($file->getMTime() >= $cutoff) {
                    continue;
                }

                $path = $file->getPathname();
                $fp = @fopen($path, 'r');
                if ($fp === false) {
                    continue;
                }

                try {
                    // Do not unlink a bucket while its writer holds the lock.
                    // Recheck mtime under lock: the bucket may have been
                    // refreshed since the directory iterator inspected it.
                    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                        continue;
                    }

                    $stat = fstat($fp);
                    if (!is_array($stat) || (int)($stat['mtime'] ?? 0) >= $cutoff) {
                        continue;
                    }

                    $state = json_decode((string)stream_get_contents($fp), true);
                    $start = is_array($state) ? (int)($state['start'] ?? 0) : 0;
                    $window = is_array($state) && isset($state['window'])
                        && is_int($state['window']) && $state['window'] > 0
                        ? $state['window']
                        : self::LEGACY_BUCKET_WINDOW;

                    // Stale mtime does not mean the security window expired.
                    // A 24-hour UDID bucket must survive one-hour file GC.
                    if ($start > 0 && $start + $window > time()) {
                        continue;
                    }

                    // A concurrent worker may have replaced the path
                    // since it was opened. Never remove its fresh bucket.
                    $onDisk = @lstat($path);
                    if (!is_array($onDisk)
                        || (int)($onDisk['ino'] ?? -1) !== (int)($stat['ino'] ?? -2)
                        || (int)($onDisk['dev'] ?? -1) !== (int)($stat['dev'] ?? -2)) {
                        continue;
                    }

                    if (@unlink($path)) {
                        $removed++;

                        if ($removed >= $maxEntries) {
                            break;
                        }
                    }
                } finally {
                    @flock($fp, LOCK_UN);
                    fclose($fp);
                }
            }
        } catch (\Throwable) {
            return $removed;
        }

        return $removed;
    }

    private function maybeCleanup(): void
    {
        static $calls = 0;

        $calls++;

        if ($calls >= self::AUTO_CLEANUP_INTERVAL) {
            $calls = 0;
            $this->cleanup();
        }
    }

    public function allow(
        string $key,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($limit < 1 || $windowSeconds < 1) {
            return true;
        }

        $this->maybeCleanup();

        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0700, true) &&
            !is_dir($this->directory)
        ) {
            return true; // Keep the game protocol available if storage is unavailable.
        }

        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        $fp = @fopen($file, 'c+');

        if ($fp === false) {
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return true;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);

            $state = is_string($raw) && $raw !== ''
                ? json_decode($raw, true)
                : null;

            $now = time();
            $start = is_array($state) ? (int)($state['start'] ?? 0) : 0;
            $count = is_array($state) ? (int)($state['count'] ?? 0) : 0;

            if ($start <= 0 || ($now - $start) >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }

            $count++;

            rewind($fp);
            if (!ftruncate($fp, 0)) {
                return true;
            }
            $payload = json_encode([
                'start' => $start,
                'count' => $count,
                'window' => $windowSeconds,
            ], JSON_THROW_ON_ERROR);
            if (fwrite($fp, $payload) !== strlen($payload) || !fflush($fp)) {
                return true;
            }

            return $count <= $limit;
        } catch (\Throwable) {
            // Legacy game traffic may proceed when local storage fails,
            // but a broken bucket must never cause an uncaught HTTP 500.
            return true;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
