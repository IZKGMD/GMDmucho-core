<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class ProtocolText
{
    public static function field(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string)$value;

        return str_replace(
            [':', '|', '#', '~'],
            '',
            $value
        );
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
