<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdCommentEncoder
{
    /**
     * Формирует строку комментария к уровню (comment:user)
     */
    public function encode(
        array $comment,
        array $profile,
        int $gameVersion = 22,
        int $binaryVersion = 0
    ): string {
        $content = (string)($comment['content'] ?? '');

        if ($gameVersion < 20) {
            $content = base64_encode($content);
        }

        $userId = (int)($profile['user_id'] ?? $comment['account_id'] ?? 0);
        $extId = (int)($profile['ext_id'] ?? $comment['account_id'] ?? 0);

        $commentStr = implode('~', [
            '2', $content,
            '3', (string)$userId,
            '4', (string)($comment['likes'] ?? 0),
            '5', '0',
            '7', (string)($comment['is_spam'] ?? 0),
            '9', $this->formatTimeAgo(
                (string)($comment['created_at'] ?? '')
            ),
            '10', (string)($comment['percent'] ?? 0),
        ]);

        /*
         * Binary versions <= 31 use the separate #user list.
         * Newer clients receive the extended user block after the comment.
         */
        if ($binaryVersion > 31) {
            $badge = (int)($profile['badge'] ?? 0);

            $commentStr .= '~11~' . $badge
                . ':1~' . ProtocolText::username(
                    $profile['username'] ?? 'Player'
                )
                . '~7~1'
                . '~9~' . (int)($profile['cube'] ?? 1)
                . '~10~' . (int)($profile['color1'] ?? 0)
                . '~11~' . (int)($profile['color2'] ?? 3)
                . '~14~' . (int)($profile['icon_type'] ?? 0)
                . '~15~' . (int)($profile['special'] ?? 0)
                . '~16~' . $extId;
        }

        return $commentStr;
    }

    /**
     * Формирует строку комментария со стены профиля
     */
    public function encodeAccountComment(array $comment): string
    {
        return implode('~', [
            '2', (string)$comment['content'],
            '3', (string)$comment['account_id'],
            '4', (string)$comment['likes'],
            '5', '0',
            '7', (string)$comment['is_spam'],
            '9', $this->formatTimeAgo((string)$comment['created_at']),
            '6', (string)$comment['id']
        ]);
    }

    private function formatTimeAgo(string $timestamp): string
    {
        $time = strtotime($timestamp);

        if ($time === false) {
            $time = 0;
        }

        // Geometry Dash expects the classic comment timestamp format.
        return date('d/m/Y G.i', $time);
    }
}
