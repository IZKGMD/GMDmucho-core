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

    public static function int(
        string $key,
        int $default,
        int $min,
        int $max
    ): int {
        $value = self::raw($key);

        if (
            $value === null ||
            !preg_match('/^-?\d+$/', trim((string)$value))
        ) {
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

        /*
         * Admin overrides live outside the project .env so the web process
         * never needs write access to the main configuration file.
         */
        $controlDir =
            $_ENV['MUCHO_CONTROL_DIR']
            ?? $_SERVER['MUCHO_CONTROL_DIR']
            ?? getenv('MUCHO_CONTROL_DIR');

        $controlDir =
            is_string($controlDir) && $controlDir !== ''
                ? $controlDir
                : '/var/lib/muchocore-control';

        $overrideFile = rtrim($controlDir, '/').'/settings.json';

        if (is_file($overrideFile)) {
            $json = file_get_contents($overrideFile);

            if ($json !== false) {
                try {
                    $decoded = json_decode(
                        $json,
                        true,
                        32,
                        JSON_THROW_ON_ERROR
                    );

                    if (
                        is_array($decoded) &&
                        array_key_exists($key, $decoded)
                    ) {
                        return self::$cache[$key] = $decoded[$key];
                    }
                } catch (\Throwable) {
                    /*
                     * Broken override file must not take the whole server
                     * down. Fall back to normal environment configuration.
                     */
                }
            }
        }

        $value =
            $_ENV[$key]
            ?? $_SERVER[$key]
            ?? getenv($key);

        self::$cache[$key] =
            $value === false
                ? null
                : $value;

        return self::$cache[$key];
    }
}
