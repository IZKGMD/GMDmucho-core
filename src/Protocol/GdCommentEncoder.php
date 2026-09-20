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
        int $gameVersion = 22
    ): string {
        $content = (string)$comment['content'];

        if ($gameVersion < 20) {
            $content = str_replace(
                ['+', '/'],
                ['-', '_'],
                base64_encode($content)
            );
        }

        $commentStr = implode('~', [
            '2', $content,
            '3', (string)$comment['account_id'],
            '4', (string)$comment['likes'],
            '5', '0',
            '7', (string)$comment['is_spam'],
            '9', $this->formatTimeAgo((string)$comment['created_at']),
            '10', (string)$comment['percent'],
            '11', (string)($profile['badge'] ?? 0),
            '12', '255,255,255',
            '6', (string)$comment['id']
        ]);

        $userStr = implode('~', [
            '1', ProtocolText::username($profile['username'] ?? 'Player'),
            '2', (string)($profile['cube'] ?? 1),
            '7', (string)($profile['color1'] ?? 0),
            '8', (string)($profile['color2'] ?? 3),
            '9', '0',
            '10', (string)($profile['special'] ?? 0),
            '11', (string)$comment['account_id'],
            '14', '0',
            '15', (string)($profile['badge'] ?? 0),
            '16', (string)$comment['account_id']
        ]);

        return $commentStr . ':' . $userStr;
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
        $diff = max(1, time() - $time);

        if ($diff < 3600) return max(1, (int)floor($diff / 60)) . ' minutes';
        if ($diff < 86400) return (int)floor($diff / 3600) . ' hours';
        if ($diff < 31536000) return (int)floor($diff / 86400) . ' days';

        return (int)floor($diff / 31536000) . ' years';
    }
}
