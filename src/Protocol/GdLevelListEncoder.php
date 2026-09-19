<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdLevelListEncoder
{
    private const HASH_SALT = "xI25fpAapCQg";

    public function encode(
        array $levels,
        int $total,
        int $offset,
        int $limit,
        int $gameVersion = 22
    ): string {
        if (empty($levels) || $total === 0) {
            return "-2";
        }

        $levelStrings = [];
        $userStrings = [];
        $uniqueUsers = [];
        $hashData = "";

        foreach ($levels as $level) {
            $levelId = (string) $level["level_id"];
            $userId = (string) ($level["user_id"] ?? $level["account_id"] ?? 1);
            $accountId = (string) ($level["account_id"] ?? 1);
            $username = ProtocolText::username(
                $level['username'] ?? 'Player'
            );
            $protocolStars = max(
                0,
                min(10, (int) ($level["stars"] ?? 0))
            );

            $levelStrings[] = implode(":", [
                1, $levelId,
                2, $level["name"],
                5, $level["level_version"],
                6, $userId,
                8, 10,
                9, $level["difficulty"],
                10, $level["downloads"],
                12, $level["audio_track"],
                13, $level["game_version"],
                14, $level["likes"],
                17, $level["demon"],
                43, $level["demon_difficulty"],
                25, $level["auto_level"],
                18, $level["stars"],
                19, $level["featured"],
                42, $level["epic"],
                45, $level["object_count"],
                3, $level["description"] ?? "",
                15, $level["length"],
                30, $level["original_level_id"],
                31, $level["two_player"],
                37, $level["coins"],
                38, $level["coins_verified"],
                39, $level["requested_stars"],
                46, 1,
                47, 2,
                40, $level["ldm"],
                35, $level["song_id"]
            ]);

            if (!isset($uniqueUsers[$userId])) {
                $uniqueUsers[$userId] = true;
                $userStrings[] = implode(":", [
                    $userId,
                    $username,
                    $accountId,
                ]);
            }

            $firstChar = $levelId[0];
            $lastChar = $levelId[strlen($levelId) - 1];
            $hashData .= $firstChar . $lastChar . $protocolStars . $level["coins_verified"];
        }

        $levelsPart = implode("|", $levelStrings);
        $usersPart = implode("|", $userStrings);
        $songsPart = "";
        $pageInfo = $total . ":" . $offset . ":" . $limit;
        $hashPart = sha1($hashData . self::HASH_SALT);

        // Строгий формат Geometry Dash: levels#users#songs#pageInfo#hash
        return $levelsPart . "#" . $usersPart . "#" . $songsPart . "#" . $pageInfo . "#" . $hashPart;
    }
}
