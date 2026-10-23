<?php

declare (strict_types=1);
namespace MuchoCore\Client;

use RuntimeException;
/**
 * Private temporary APK upload with disjoint, concurrently writable ranges.
 *
 * Only use after the admin route has authenticated the user and validated CSRF.
 * A .done marker is published AFTER a full range has been flushed. One lock
 * per part serializes retries; different parts never lock each other.
 */
final class ParallelApkUpload
{
    const MAX_PART_BYTES = 8 * 1024 * 1024;
    public static function recommendedPartBytes($fileLimit = null, $postLimit = null): int
    {
        $fileLimit = $fileLimit ?? (string) ini_get('upload_max_filesize');
        $postLimit = $postLimit ?? (string) ini_get('post_max_size');
        $available = min(self::parseLimit($fileLimit), self::parseLimit($postLimit));
        // Multipart boundaries, CSRF and action fields must fit too.
        if ($available >= 12 * 1024 * 1024) {
            return self::MAX_PART_BYTES;
        }
        if ($available >= 6 * 1024 * 1024) {
            return 4 * 1024 * 1024;
        }
        if ($available >= 3 * 1024 * 1024) {
            return 2 * 1024 * 1024;
        }
        if ($available >= 1536 * 1024) {
            return 1024 * 1024;
        }
        if ($available >= 768 * 1024) {
            return 512 * 1024;
        }
        throw new RuntimeException('PHP upload_max_filesize/post_max_size must allow at least 768 KiB.');
    }
    /**
     * The browser may request smaller fixed-size parts on mobile links.
     * Server's own PHP limits always win; only discrete powers-of-two
     * sizes are allowed so retries and part offsets stay deterministic.
     */
    public static function negotiatedPartBytes($preference, $fileLimit = null, $postLimit = null): int
    {
        $limit = self::recommendedPartBytes($fileLimit, $postLimit);
        if ($preference === null || $preference === '') {
            return $limit;
        }
        $allowed = ['524288' => 524288, '1048576' => 1048576, '2097152' => 2097152, '4194304' => 4194304, '8388608' => 8388608];
        if (!isset($allowed[$preference])) {
            throw new RuntimeException('Invalid APK upload part size preference.');
        }
        return min($limit, $allowed[$preference]);
    }
    private static function parseLimit(string $limit): int
    {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)B?\s*$/iD', $limit, $matches)) {
            throw new RuntimeException('Invalid PHP upload limit.');
        }
        $multiplier = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
        return (int) ((float) $matches[1] * $multiplier[strtoupper($matches[2])]);
    }
    public static function partCount(int $size, int $partSize): int
    {
        if ($size < 1024 || $size > 512 * 1024 * 1024 || $partSize < 512 * 1024 || $partSize > self::MAX_PART_BYTES) {
            throw new RuntimeException('Invalid parallel APK upload parameters.');
        }
        return intdiv($size + $partSize - 1, $partSize);
    }
    public static function expectedBytes(int $size, int $partSize, int $index): int
    {
        $count = self::partCount($size, $partSize);
        if ($index < 0 || $index >= $count) {
            throw new RuntimeException('APK upload part index outside file.');
        }
        return min($partSize, $size - $index * $partSize);
    }
    public static function prepare(string $path, int $totalSize)
    {
        $out = @fopen($path, 'x+b');
        if ($out === false) {
            throw new RuntimeException('Unable to initialize private APK storage.');
        }
        // Do not pre-allocate or zero-fill an entire 100–512 MiB APK on
        // android-patcher-start. Some shared filesystems make ftruncate()
        // slow; the first uploaded part will grow this sparse staging file.
        // isComplete() still requires every part marker AND the final size.
        fclose($out);
    }
    private static function marker(string $path, int $index): string
    {
        return $path . '.part-' . $index . '.done';
    }
    public static function completed(string $path, int $size, int $partSize): array
    {
        $done = [];
        $count = self::partCount($size, $partSize);
        for ($i = 0; $i < $count; $i++) {
            $marker = self::marker($path, $i);
            clearstatcache(true, $marker);
            if (is_file($marker) && filesize($marker) === 64) {
                $done[] = $i;
            }
        }
        return $done;
    }
    public static function isComplete(string $path, int $size, int $partSize): bool
    {
        clearstatcache(true, $path);
        return is_file($path) && filesize($path) === $size && count(self::completed($path, $size, $partSize)) === self::partCount($size, $partSize);
    }
    /**
     * @param string $inputPath Private upload temp file, verified via is_uploaded_file at HTTP boundary.
     * @return bool Whether the range was newly committed (false means idempotent retry).
     */
    public static function store(string $path, int $size, int $partSize, int $index, string $inputPath, int $inputSize): bool
    {
        $expected = self::expectedBytes($size, $partSize, $index);
        if ($inputSize !== $expected || !is_file($inputPath)) {
            throw new RuntimeException('APK part length mismatch.');
        }
        $marker = self::marker($path, $index);
        $lock = @fopen($path . '.part-' . $index . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock APK upload part.');
        }
        try {
            clearstatcache(true, $marker);
            if (is_file($marker) && filesize($marker) === 64) {
                return false;
                // An acknowledged part is not written a second time.
            }
            $input = @fopen($inputPath, 'rb');
            $out = @fopen($path, 'c+b');
            if ($input === false || $out === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
                throw new RuntimeException('Cannot access APK upload storage.');
            }
            try {
                if (fseek($out, $index * $partSize) !== 0 || stream_copy_to_stream($input, $out, $expected) !== $expected || !fflush($out)) {
                    throw new RuntimeException('Unable to save complete APK part.');
                }
            } finally {
                fclose($input);
                fclose($out);
            }
            $digest = hash_file('sha256', $inputPath);
            if ($digest === false) {
                throw new RuntimeException('Cannot hash APK upload part.');
            }
            $tmpMarker = $marker . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmpMarker, $digest, LOCK_EX) !== 64 || !@rename($tmpMarker, $marker)) {
                @unlink($tmpMarker);
                throw new RuntimeException('Unable to commit APK upload part.');
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    public static function cleanup(string $path, int $size, int $partSize)
    {
        $count = self::partCount($size, $partSize);
        for ($i = 0; $i < $count; $i++) {
            @unlink(self::marker($path, $i));
            @unlink($path . '.part-' . $i . '.lock');
        }
    }
}
