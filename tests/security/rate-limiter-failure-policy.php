<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Security/RateLimitBackend.php';
require dirname(__DIR__, 2) . '/src/Security/RateLimiter.php';

use MuchoCore\Security\RateLimiter;

/**
 * Throw from an opened bucket stream, rather than merely making its directory
 * unwritable. This covers the exception handlers in both rate-limit policies.
 */
final class BrokenRateLimitStream
{
    public $context;

    public function url_stat(string $path, int $flags): array|false
    {
        return stat(sys_get_temp_dir());
    }

    public function stream_stat(): array|false
    {
        return stat(sys_get_temp_dir());
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return true;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_truncate(int $size): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        throw new RuntimeException('Simulated storage failure');
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}

if (!stream_wrapper_register('muchobroken', BrokenRateLimitStream::class)) {
    throw new RuntimeException('Cannot register broken-stream test fixture.');
}

try {
    $broken = new RateLimiter('muchobroken://buckets');

    if ($broken->allowStrict('admin-password-reset', 1, 60) !== false) {
        throw new RuntimeException('Strict limiter must fail closed after a stream exception.');
    }

    if ($broken->allow('game-levels', 1, 60) !== true) {
        throw new RuntimeException('Legacy limiter must fail open without a fatal exception.');
    }
} finally {
    stream_wrapper_unregister('muchobroken');
}

$directory = sys_get_temp_dir() . '/muchocore-limiter-regression-' . bin2hex(random_bytes(8));
$normal = new RateLimiter($directory);
try {
    if ($normal->allowStrict('strict', 1, 60) !== true ||
        $normal->allowStrict('strict', 1, 60) !== false) {
        throw new RuntimeException('Strict limiter bucket count regressed.');
    }

    if ($normal->allow('legacy', 1, 60) !== true ||
        $normal->allow('legacy', 1, 60) !== false) {
        throw new RuntimeException('Legacy limiter bucket count regressed.');
    }
} finally {
    foreach (glob($directory . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

echo "rate-limiter-failure-policy: OK\n";