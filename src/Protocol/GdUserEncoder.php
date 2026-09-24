<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

use MuchoCore\User\GameRole;

final class GdUserEncoder
{
    public function profile(array $u): string
    {
        $accountId = (int)($u['account_id'] ?? 0);
        $userId = (int)($u['user_id'] ?? $accountId);
        $name = ProtocolText::username(
            $u['username'] ?? 'Player'
        );

        $role = (string)($u['role_code'] ?? 'user');

        try {
            $modBadge = GameRole::badgeLevel($role);
        } catch (\InvalidArgumentException) {
            $modBadge = 0;
        }

        $mapping = [
            1  => $name,
            2  => $userId,
            13 => (int)($u['secret_coins'] ?? 0),
            17 => (int)($u['user_coins'] ?? 0),
            18 => (int)($u['message_state'] ?? 0),
            19 => (int)($u['friend_request_state'] ?? 0),
            20 => (string)($u['youtube'] ?? ''),
            21 => (int)($u['icon_id'] ?? $u['cube'] ?? 1),
            22 => (int)($u['ship'] ?? 1),
            23 => (int)($u['ball'] ?? 1),
            24 => (int)($u['ufo'] ?? 1),
            25 => (int)($u['wave'] ?? 1),
            26 => (int)($u['robot'] ?? 1),
            28 => (int)($u['glow'] ?? 0),
            29 => 1,
            30 => (int)($u['rank'] ?? 0),
            31 => (int)($u['friend_state'] ?? 0),
            38 => (int)($u['messages_count'] ?? 0),
            39 => (int)($u['friend_requests_count'] ?? 0),
            40 => (int)($u['friends_count'] ?? 0),
            43 => (int)($u['spider'] ?? 1),
            44 => (string)($u['twitter'] ?? ''),
            45 => (string)($u['twitch'] ?? ''),
            46 => (int)($u['diamonds'] ?? 0),
            48 => (int)($u['explosion'] ?? 1),
            31 => (int)($u['friend_state'] ?? 0),
            32 => (int)($u['request_id'] ?? 0),
            35 => (string)($u['request_comment'] ?? ''),
            37 => (string)($u['request_date'] ?? ''),
            38 => (int)($u['messages_count'] ?? 0),
            39 => (int)($u['friend_requests_count'] ?? 0),
            40 => (int)($u['friends_count'] ?? 0),
            49 => $modBadge,
            50 => (int)($u['comment_history_state'] ?? 0),
            51 => (int)($u['color3'] ?? 0),
            52 => (int)($u['moons'] ?? 0),
            53 => (int)($u['swing'] ?? 1),
            54 => (int)($u['jetpack'] ?? 1),
            55 => (string)($u['demon_info'] ?? ''),
            56 => (string)($u['star_info'] ?? ''),
            57 => (string)($u['platformer_info'] ?? ''),
            58 => (string)($u['discord'] ?? ''),
            59 => (string)($u['instagram'] ?? ''),
            60 => (string)($u['tiktok'] ?? ''),
            61 => (string)($u['custom_link'] ?? ''),
            3  => (int)($u['stars'] ?? 0),
            4  => (int)($u['demons'] ?? 0),
            6  => (int)($u['rank'] ?? 0),
            7  => $accountId,
            8  => (int)($u['creator_points'] ?? 0),
            9  => (int)($u['icon_id'] ?? $u['cube'] ?? 1),
            10 => (int)($u['color1'] ?? 0),
            11 => (int)($u['color2'] ?? 3),
            14 => (int)($u['icon_type'] ?? 0),
            15 => (int)($u['special'] ?? 0),
            16 => $accountId,
        ];

        $pairs = [];
        foreach ($mapping as $k => $v) {
            $pairs[] = $k . ':' . $v;
        }

        return implode(':', $pairs);
    }

    public function search(array $users, int $total, int $offset, int $limit): string
    {
        if (empty($users)) {
            return '#0:0:0';
        }

        $entries = [];
        foreach ($users as $u) {
            $accountId = (int)($u['account_id'] ?? 0);
            $userId = (int)($u['user_id'] ?? $accountId);

            $mapping = [
                1 => ProtocolText::username($u['username'] ?? 'Player'),
                2 => $userId,
                3 => (int)($u['stars'] ?? 0),
                4 => (int)($u['demons'] ?? 0),
                6 => (int)($u['rank'] ?? 0),
                7 => $accountId,
                8 => (int)($u['creator_points'] ?? 0),
                9 => (int)($u['icon_id'] ?? 1),
                10 => (int)($u['color1'] ?? 0),
                11 => (int)($u['color2'] ?? 3),
                13 => (int)($u['secret_coins'] ?? 0),
                14 => (int)($u['icon_type'] ?? 0),
                15 => (int)($u['special'] ?? 0),
                16 => $accountId,
                17 => (int)($u['user_coins'] ?? 0),
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
            return '-1';
        }

        $entries = [];
        foreach ($users as $u) {
            $accountId = (int)($u['account_id'] ?? 0);
            $userId = (int)($u['user_id'] ?? $accountId);
            $mapping = [
                1 => ProtocolText::username($u['username'] ?? 'Player'),
                2 => $userId,
                13 => (int)($u['secret_coins'] ?? 0),
                17 => (int)($u['user_coins'] ?? 0),
                6 => (int)($u['rank'] ?? 0),
                7 => $accountId,
                9 => (int)($u['icon_id'] ?? 1),
                10 => (int)($u['color1'] ?? 0),
                11 => (int)($u['color2'] ?? 3),
                51 => (int)($u['color3'] ?? 0),
                14 => (int)($u['icon_type'] ?? 0),
                15 => (int)($u['special'] ?? 0),
                16 => $accountId,
                3 => (int)($u['stars'] ?? 0),
                4 => (int)($u['demons'] ?? 0),
                8 => (int)($u['creator_points'] ?? 0),
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
