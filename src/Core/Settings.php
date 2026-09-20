<?php

declare(strict_types=1);

namespace MuchoCore\Core;

final class Settings
{
    private static ?array $cache = null;

    public static function string(string $key, string $default): string
    {
        $value = self::raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return trim((string)$value);
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return match (strtolower(trim((string)$value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    public static function int(string $key, int $default, int $min, int $max): int
    {
        $value = self::raw($key);
        if ($value === null || !preg_match('/^-?\d+$/', trim((string)$value))) {
            return $default;
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            return $default;
        }
        return $number;
    }

    private static function raw(string $key): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
        }
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        self::$cache[$key] = $value === false ? null : $value;
        return self::$cache[$key];
    }
}
