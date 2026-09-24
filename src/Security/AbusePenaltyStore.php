<?php

declare(strict_types=1);

namespace MuchoCore\Security;

final readonly class AbusePenaltyStore
{
    public function __construct(
        private string $directory = '/tmp/muchocore-protect-penalties'
    ) {}

    /** @return array{active:bool, remaining:int, strikes:int} */
    public function status(string $key): array
    {
        $state = $this->readState($key);
        if ($state === null) {
            return ['active' => false, 'remaining' => 0, 'strikes' => 0];
        }

        $now = time();
        $expires = (int)($state['expires'] ?? 0);
        $strikes = max(0, (int)($state['strikes'] ?? 0));

        if ($expires <= $now) {
            return ['active' => false, 'remaining' => 0, 'strikes' => 0];
        }

        return ['active' => true, 'remaining' => $expires - $now, 'strikes' => $strikes];
    }

    /** @return array{seconds:int, strikes:int} */
    public function penalize(
        string $key,
        int $baseSeconds = 15,
        int $maxSeconds = 900,
        int $strikeWindowSeconds = 600
    ): array {
        if ($baseSeconds < 1 || $maxSeconds < $baseSeconds) {
            return ['seconds' => 0, 'strikes' => 0];
        }

        $fp = $this->open($key);
        if ($fp === null) {
            return ['seconds' => 0, 'strikes' => 0];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return ['seconds' => 0, 'strikes' => 0];
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $now = time();
            $last = is_array($state) ? (int)($state['last'] ?? 0) : 0;
            $oldStrikes = is_array($state) ? (int)($state['strikes'] ?? 0) : 0;

            $strikes = ($last > 0 && ($now - $last) <= $strikeWindowSeconds)
                ? min(8, $oldStrikes + 1)
                : 1;

            $seconds = min($maxSeconds, $baseSeconds * (2 ** min(7, $strikes - 1)));
            $expires = $now + $seconds;

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode([
                'expires' => $expires,
                'last' => $now,
                'strikes' => $strikes,
            ], JSON_THROW_ON_ERROR));
            fflush($fp);

            return ['seconds' => $seconds, 'strikes' => $strikes];
        } catch (\Throwable) {
            return ['seconds' => 0, 'strikes' => 0];
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function open(string $key): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return null;
        }

        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        $fp = @fopen($file, 'c+');
        return $fp === false ? null : $fp;
    }

    private function readState(string $key): ?array
    {
        $fp = $this->open($key);
        if ($fp === null) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return null;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            return is_array($state) ? $state : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
