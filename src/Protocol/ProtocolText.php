<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class ProtocolText
{
    public static function relativeTime(int $timestamp, ?int $now = null): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $elapsed = max(0, ($now ?? time()) - $timestamp);
        $units = [
            'year' => 31536000,
            'month' => 2592000,
            'week' => 604800,
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
        ];
        foreach ($units as $unit => $seconds) {
            if ($elapsed >= $seconds) {
                $amount = intdiv($elapsed, $seconds);
                return $amount . ' ' . $unit . ($amount === 1 ? '' : 's');
            }
        }
        return $elapsed . ($elapsed === 1 ? ' second' : ' seconds');
    }

    public static function field(
        mixed $value,
        int $maxLength = 65535
    ): string {
        if (!is_scalar($value)) {
            return '';
        }

        $value = str_replace(
            ["\0", "\r", "\n", ':', '|', '#', '~'],
            '',
            (string)$value
        );

        $maxLength = max(0, $maxLength);

        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }

        return $value;
    }

    public static function comment(
        mixed $value,
        int $maxLength = 2048
    ): string {
        if (!is_scalar($value)) {
            return '';
        }

        $value = str_replace(
            ["\0", "\r", "\n", '~', '|', '#'],
            '',
            (string)$value
        );

        $maxLength = max(0, $maxLength);

        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }

        return $value;
    }

    public static function username(mixed $value): string
    {
        if (!is_scalar($value)) {
            return 'Player';
        }

        $value = trim((string)$value);
        $value = preg_replace(
            '/[\x00-\x1F\x7F:|#~]/',
            '',
            $value
        ) ?? '';
        $value = substr($value, 0, 20);

        return $value !== '' ? $value : 'Player';
    }
}
