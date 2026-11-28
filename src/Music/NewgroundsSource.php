<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use InvalidArgumentException;

final class NewgroundsSource
{
    private const PREFIX = 'https://www.newgrounds.com/audio/listen/';

    public static function parse(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)
            || preg_match('~^(?:https://(?:www\.)?newgrounds\.com/audio/listen/)?([1-9][0-9]{0,9})/?$~D', trim($value), $matches) !== 1
            || (float) $matches[1] > 2147483647) {
            throw new InvalidArgumentException('Enter a valid Newgrounds Audio ID or track URL.');
        }
        return (int) $matches[1];
    }

    public static function url(int $id): string
    {
        return self::PREFIX . $id;
    }
}
