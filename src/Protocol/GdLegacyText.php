<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdLegacyText
{
    /**
     * Decode client-supplied wire text before persisting it.
     *
     * Geometry Dash sends level descriptions/comments as Base64 on the
     * database protocol. Requests may use URL-safe Base64 without padding.
     */
    public static function encodeDescriptionForStorage(
        string $description,
        int $gameVersion
    ): string {
        /*
         * GD 1.9 sends level descriptions as plain text.
         * GD 2.0+ sends URL-safe Base64 on upload.
         */
        if ($gameVersion < 20) {
            return $description;
        }

        return self::decodeWireText($description);
    }

    /**
     * Encode a stored/plain description for a server response.
     */
    public static function encodeDescriptionForResponse(
        string $description,
        int $gameVersion
    ): string {
        return self::encodeWireText($description);
    }

    /**
     * Backward-compatible method name retained for existing callers.
     */
    public static function decodeDescriptionForResponse(
        string $description,
        int $gameVersion
    ): string {
        return self::encodeDescriptionForResponse(
            $description,
            $gameVersion
        );
    }

    /**
     * Decode a client-supplied comment.
     */
    public static function decodeComment(
        string $comment,
        int $gameVersion
    ): string {
        /*
         * GD 2.0+ sends level comments as plain protocol text.
         * Only the pre-2.0 client family uses Base64 for comments.
         */
        if ($gameVersion >= 20) {
            return $comment;
        }

        return self::decodeWireText($comment);
    }

    /**
     * Encode a stored/plain comment for a server response.
     */
    public static function encodeCommentForResponse(
        string $comment,
        int $gameVersion
    ): string {
        /*
         * GD 2.0+ expects level/account comments as plain text.
         * GD 1.9 keeps the legacy Base64 representation.
         */
        if ($gameVersion >= 20) {
            return $comment;
        }

        return self::encodeWireText($comment);
    }

    private static function encodeWireText(
        string $value
    ): string {
        if ($value === '') {
            return '';
        }

        /*
         * Existing installations may contain already encoded values.
         * Keep those intact when they can be decoded to valid UTF-8 text;
         * newly written plain text is encoded exactly once.
         */
        if (self::isWireEncoded($value)) {
            return $value;
        }

        return base64_encode($value);
    }

    private static function decodeWireText(
        string $value
    ): string {
        if ($value === '') {
            return '';
        }

        $normalized = strtr(
            $value,
            '-_',
            '+/'
        );

        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false || $decoded === '') {
            return $value;
        }

        /*
         * Do not turn arbitrary binary/base64-looking text into stored data.
         * Geometry Dash comments/descriptions are UTF-8 text.
         */
        if (preg_match('//u', $decoded) !== 1) {
            return $value;
        }

        if (
            preg_match(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
                $decoded
            ) === 1
        ) {
            return $value;
        }

        return $decoded;
    }

    private static function isWireEncoded(
        string $value
    ): bool {
        if (
            $value === '' ||
            !preg_match(
                '/^[A-Za-z0-9+\/_-]+={0,2}$/D',
                $value
            )
        ) {
            return false;
        }

        /*
         * Plain UTF-8 text is normally not Base64-aligned. For aligned
         * values, only preserve them as encoded when the decoded result is
         * valid UTF-8 printable text and the input is canonical enough to
         * be recognized as a protocol value.
         */
        $normalized = strtr(
            $value,
            '-_',
            '+/'
        );

        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false || $decoded === '') {
            return false;
        }

        if (preg_match('//u', $decoded) !== 1) {
            return false;
        }

        return preg_match(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            $decoded
        ) !== 1;
    }
}
