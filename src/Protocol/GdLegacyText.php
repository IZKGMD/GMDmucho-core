<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdLegacyText
{
    public static function encodeDescriptionForStorage(
        string $description,
        int $gameVersion
    ): string {
        if ($gameVersion < 20) {
            $encoded = base64_encode($description);

            return strtr(
                $encoded,
                '+/',
                '-_'
            );
        }

        return $description;
    }

    public static function decodeDescriptionForResponse(
        string $description,
        int $gameVersion
    ): string {
        if ($gameVersion >= 20 || $description === '') {
            return $description;
        }

        $decoded = base64_decode(
            strtr($description, '-_', '+/'),
            true
        );

        return $decoded === false
            ? $description
            : $decoded;
    }

    public static function decodeComment(
        string $comment,
        int $gameVersion
    ): string {
        if ($gameVersion >= 20 || $comment === '') {
            return $comment;
        }

        $decoded = base64_decode(
            strtr($comment, '-_', '+/'),
            true
        );

        return $decoded === false
            ? $comment
            : $decoded;
    }
}
