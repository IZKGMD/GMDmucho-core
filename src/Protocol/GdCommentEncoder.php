<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdCommentEncoder
{
    public function encode(
        array $comment,
        array $profile,
        int $gameVersion = 22,
        int $binaryVersion = 0
    ): string {
        $content = (string)($comment['content'] ?? '');

        if ($gameVersion < 20) {
            $content = strtr(
                base64_encode($content),
                '+/',
                '-_'
            );
        }

        $badge = (int)($profile['badge'] ?? 0);

        $commentParts = [
            '2', $content,
            '3', (string)$comment['account_id'],
            '4', (string)($comment['likes'] ?? 0),
            '5', '0',
            '7', (string)($comment['is_spam'] ?? 0),
            '9', $this->date((string)($comment['created_at'] ?? '')),
            '6', (string)$comment['id'],
            '10', (string)($comment['percent'] ?? 0),
        ];

        if ($binaryVersion > 31 || $gameVersion >= 22) {
            $commentParts[] = '11';
            $commentParts[] = (string)$badge;

            if ($badge > 0) {
                $commentParts[] = '12';
                $commentParts[] = (string)($profile['comment_color'] ?? '255,255,255');
            }

            $userParts = [
                '1', ProtocolText::username($profile['username'] ?? 'Player'),
                '7', '1',
                '9', (string)($profile['cube'] ?? 1),
                '10', (string)($profile['color1'] ?? 0),
                '11', (string)($profile['color2'] ?? 3),
                '14', (string)($profile['icon_type'] ?? 0),
                '15', (string)($profile['special'] ?? 0),
                '16', (string)(
                    $profile['account_id']
                    ?? $comment['account_id']
                    ?? 0
                ),
            ];

            return implode('~', $commentParts)
                . ':'
                . implode('~', $userParts);
        }

        return implode('~', $commentParts);
    }

    public function encodeAccountComment(
        array $comment,
        int $gameVersion = 22
    ): string {
        $content = (string)($comment['content'] ?? '');

        if ($gameVersion < 20) {
            $content = strtr(
                base64_encode($content),
                '+/',
                '-_'
            );
        }

        return implode('~', [
            '2', $content,
            '3', (string)$comment['account_id'],
            '4', (string)($comment['likes'] ?? 0),
            '5', '0',
            '7', (string)($comment['is_spam'] ?? 0),
            '9', $this->date((string)($comment['created_at'] ?? '')),
            '6', (string)$comment['id'],
        ]);
    }

    private function date(string $value): string
    {
        $time = strtotime($value);

        return $time === false
            ? ''
            : date('d/m/Y G.i', $time);
    }
}
