<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdUserEncoder
{
    public function profile(array $u): string
    {
        $accId = (int)($u['account_id'] ?? 0);
        $userId = (int)($u['user_id'] ?? $accId);

        $role = strtolower(
            (string)($u['role_code'] ?? $u['role'] ?? 'user')
        );

        $modBadge = match ($role) {
            'owner', 'admin', 'developer', 'elder' => 2,
            'moderator', 'mod', 'helper' => 1,
            default => 0
        };

        $mapping = [
            1 => ProtocolText::username($u['username'] ?? 'Player'),
            2 => $userId,
            13 => (int)($u['secret_coins'] ?? 0),
            17 => (int)($u['user_coins'] ?? 0),
            10 => (int)($u['color1'] ?? 0),
            11 => (int)($u['color2'] ?? 3),
            51 => (int)($u['color3'] ?? 0),
            3 => (int)($u['stars'] ?? 0),
            46 => (int)($u['diamonds'] ?? 0),
            52 => (int)($u['moons'] ?? 0),
            4 => (int)($u['demons'] ?? 0),
            8 => (int)($u['creator_points'] ?? 0),
            18 => (int)($u['message_state'] ?? 0),
            19 => (int)($u['friend_request_state'] ?? 0),
            50 => (int)($u['comment_history_state'] ?? 0),
            20 => (string)($u['youtube'] ?? ''),
            21 => (int)($u['cube'] ?? 1),
            22 => (int)($u['ship'] ?? 1),
            23 => (int)($u['ball'] ?? 1),
            24 => (int)($u['ufo'] ?? 1),
            25 => (int)($u['wave'] ?? 1),
            26 => (int)($u['robot'] ?? 1),
            28 => (int)($u['glow'] ?? 0),
            43 => (int)($u['spider'] ?? 1),
            48 => (int)($u['explosion'] ?? 1),
            53 => (int)($u['swing'] ?? 1),
            54 => (int)($u['jetpack'] ?? 1),
            30 => (int)($u['rank'] ?? 0),
            16 => $accId,
            31 => (int)($u['friend_state'] ?? 0),
            44 => (string)($u['twitter'] ?? ''),
            45 => (string)($u['twitch'] ?? ''),
            49 => $modBadge,
            55 => (string)($u['demon_info'] ?? ''),
            56 => (string)($u['star_info'] ?? ''),
            57 => (string)($u['platformer_info'] ?? ''),
            58 => (string)($u['discord'] ?? ''),
            59 => (string)($u['instagram'] ?? ''),
            60 => (string)($u['tiktok'] ?? ''),
            61 => (string)($u['custom_link'] ?? ''),
            29 => 1,
        ];

        $pairs = [];
        foreach ($mapping as $k => $v) {
            $pairs[] = $k . ':' . $v;
        }

        return implode(':', $pairs);
    }

    public function search(
        array $users,
        int $total,
        int $offset,
        int $limit
    ): string {
        if (empty($users)) {
            return '#0:0:0';
        }

        $entries = [];

        foreach ($users as $u) {
            $accId = (int)($u['account_id'] ?? 0);
            $userId = (int)($u['user_id'] ?? $accId);

            $mapping = [
                1 => ProtocolText::username($u['username'] ?? 'Player'),
                2 => $userId,
                13 => (int)($u['secret_coins'] ?? 0),
                17 => (int)($u['user_coins'] ?? 0),
                9 => (int)($u['cube'] ?? 1),
                10 => (int)($u['color1'] ?? 0),
                11 => (int)($u['color2'] ?? 3),
                51 => (int)($u['color3'] ?? 0),
                14 => (int)($u['icon_type'] ?? 0),
                15 => (int)($u['special'] ?? 0),
                16 => $accId,
                3 => (int)($u['stars'] ?? 0),
                8 => (int)($u['creator_points'] ?? 0),
                4 => (int)($u['demons'] ?? 0),
                46 => (int)($u['diamonds'] ?? 0),
                52 => (int)($u['moons'] ?? 0)
            ];

            $pairs = [];
            foreach ($mapping as $k => $v) {
                $pairs[] = $k . ':' . $v;
            }

            $entries[] = implode(':', $pairs);
        }

        return implode('|', $entries) . "#{$total}:{$offset}:{$limit}";
    }

    public function leaderboard(array $users): string
    {
        if (empty($users)) {
            return '';
        }

        $entries = [];

        foreach ($users as $u) {
            $accId = (int)($u['account_id'] ?? 0);
            $userId = (int)($u['user_id'] ?? $accId);

            $mapping = [
                1 => ProtocolText::username($u['username'] ?? 'Player'),
                2 => $userId,
                13 => (int)($u['secret_coins'] ?? 0),
                17 => (int)($u['user_coins'] ?? 0),
                6 => (int)($u['rank'] ?? 0),
                9 => (int)($u['cube'] ?? 1),
                10 => (int)($u['color1'] ?? 0),
                11 => (int)($u['color2'] ?? 3),
                51 => (int)($u['color3'] ?? 0),
                14 => (int)($u['icon_type'] ?? 0),
                15 => (int)($u['special'] ?? 0),
                16 => $accId,
                3 => (int)($u['stars'] ?? 0),
                8 => (int)($u['creator_points'] ?? 0),
                4 => (int)($u['demons'] ?? 0),
                7 => $accId,
                46 => (int)($u['diamonds'] ?? 0),
                52 => (int)($u['moons'] ?? 0)
            ];

            $pairs = [];
            foreach ($mapping as $k => $v) {
                $pairs[] = $k . ':' . $v;
            }

            $entries[] = implode(':', $pairs);
        }

        return implode('|', $entries);
    }

}
