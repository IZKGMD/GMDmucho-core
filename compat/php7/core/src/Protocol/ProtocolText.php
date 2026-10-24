<?php

declare (strict_types=1);
namespace MuchoCore\Protocol;

final class ProtocolText
{
    public static function field($value, int $maxLength = 65535): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = str_replace(["\x00", "\r", "\n", ':', '|', '#', '~'], '', (string) $value);
        $maxLength = max(0, $maxLength);
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }
    public static function comment($value, int $maxLength = 2048): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = str_replace(["\x00", "\r", "\n", '~', '|', '#'], '', (string) $value);
        $maxLength = max(0, $maxLength);
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }
    public static function username($value): string
    {
        if (!is_scalar($value)) {
            return 'Player';
        }
        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F:|#~]/', '', $value) ?? '';
        $value = substr($value, 0, 20);
        return $value !== '' ? $value : 'Player';
    }
}
