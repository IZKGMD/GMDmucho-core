<?php

declare(strict_types=1);

namespace MuchoCore\News;

use RuntimeException;

/**
 * Geometry Dash 2.209 native News serializer.
 *
 * Reverse-engineered from a real 2.209 news.dat cache:
 *  url-safe-base64(gzip("4,<count>|1:<id>;2:<date64>;3:<title64>;4:<element>~..."))
 * Element types: 0=body text, 1=image, 2=hyperlink.
 *
 * We intentionally generate text and optional link elements only: an image
 * needs an independently served news image resource and dimensions.
 */
final class NativeNewsFormat
{
    private const FORMAT_VERSION = 4;
    private const MAX_POSTS = 30;

    /**
     * @param array<int,array<string,mixed>> $posts Already filtered to published.
     */
    public static function textPayload(array $posts): string
    {
        $rows = [];
        foreach (array_slice($posts, 0, self::MAX_POSTS) as $post) {
            $id = (int)($post['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Native news post IDs must be positive.');
            }

            $title = (string)($post['title'] ?? '');
            $body = (string)($post['body'] ?? '');
            if ($title === '' || $body === '') {
                throw new RuntimeException('Native news title and body cannot be empty.');
            }
            $date = strtotime((string)($post['published_at'] ?? ''));
            if ($date === false || $date <= 0) {
                throw new RuntimeException('Native news publication date is invalid.');
            }

            $row = '1:' . $id
                . ';2:' . self::base64url(gmdate('F j, Y', $date))
                . ';3:' . self::base64url($title)
                . ';4:0,' . self::base64url($body);

            $link = (string)($post['url'] ?? '');
            if ($link !== '') {
                if (!NewsService::isSafeLink($link)) {
                    throw new RuntimeException('Invalid native news link.');
                }
                $row .= '~2,' . self::base64url('Read more') . ',' . self::base64url($link);
            }
            $rows[] = $row;
        }

        return self::FORMAT_VERSION . ',' . count($rows)
            . ($rows !== [] ? '|' . implode('|', $rows) : '');
    }

    /**
     * @param array<int,array<string,mixed>> $posts
     */
    public static function encode(array $posts): string
    {
        $compressed = gzencode(self::textPayload($posts), 9, ZLIB_ENCODING_GZIP);
        if ($compressed === false) {
            throw new RuntimeException('Unable to gzip native GD news.');
        }
        return self::base64url($compressed);
    }

    /**
     * News version must change on edits, publish/unpublish, reorder and delete.
     * A content-derived stable integer avoids stale caches and unnecessary reloads.
     *
     * @param array<int,array<string,mixed>> $posts
     */
    public static function version(array $posts): string
    {
        $hash = hash('sha256', self::textPayload($posts));
        $number = hexdec(substr($hash, 0, 7));
        return (string)max(1, $number);
    }

    private static function base64url(string $value): string
    {
        // Native format uses URL-safe characters AND keeps '=' padding.
        return strtr(base64_encode($value), '+/', '-_');
    }
}
