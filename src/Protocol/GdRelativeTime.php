<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

/**
 * Native GD "time ago" wire values (without the "ago" suffix).
 *
 * The client itself adds "ago" for comment key 9 and level-score key 42.
 * Sending a full calendar date makes the UI display "19/11/2026 ... ago".
 */
final class GdRelativeTime
{
    public static function fromEpoch(int $timestamp, ?int $now = null): string
    {
        if ($timestamp <= 0) {
            return '0 seconds';
        }

        $elapsed = max(0, ($now ?? time()) - $timestamp);

        foreach ([
            [31536000, 'year'],
            [2592000, 'month'],
            [604800, 'week'],
            [86400, 'day'],
            [3600, 'hour'],
            [60, 'minute'],
        ] as [$duration, $unit]) {
            if ($elapsed >= $duration) {
                $count = intdiv($elapsed, $duration);
                return $count . ' ' . $unit . ($count === 1 ? '' : 's');
            }
        }

        return $elapsed . ' second' . ($elapsed === 1 ? '' : 's');
    }

    public static function fromDate(string $date, ?int $now = null): string
    {
        if (trim($date) === '') {
            return '0 seconds';
        }

        $timestamp = strtotime($date);
        return $timestamp === false
            ? '0 seconds'
            : self::fromEpoch($timestamp, $now);
    }
}
