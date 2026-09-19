<?php

declare(strict_types=1);

namespace MuchoCore\Security;

final readonly class RateLimiter
{
    public function __construct(
        private string $directory = '/tmp/muchocore-rate-limit'
    ) {}

    public function allow(
        string $key,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($limit < 1 || $windowSeconds < 1) {
            return true;
        }

        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0700, true) &&
            !is_dir($this->directory)
        ) {
            return true; // fail-open: не ломаем GD
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
            ftruncate($fp, 0);
            fwrite($fp, json_encode([
                'start' => $start,
                'count' => $count,
            ], JSON_THROW_ON_ERROR));
            fflush($fp);

            return $count <= $limit;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
