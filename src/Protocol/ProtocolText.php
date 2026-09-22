<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class ProtocolText
{
    public static function message(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string)$value;
        $value = str_replace(
            ["\\0", "\r", "\n"],
            ['', ' ', ' '],
            $value
        );

        /*
         * Message bodies/subjects are colon-delimited, while Geometry Dash
         * does allow most ordinary punctuation. Strip only protocol-breaking
         * separators and control characters.
         */
        $value = preg_replace(
            '/[\\x00-\\x1F\\x7F:#~|]/',
            '',
            $value
        ) ?? '';

        return substr($value, 0, 65535);
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
