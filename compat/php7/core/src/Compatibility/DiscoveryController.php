<?php

declare (strict_types=1);
namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Protocol\GdUserEncoder;
use MuchoCore\User\UserRepository;
use PDO;
/*
 * MuchoCore GD Discovery Compatibility
 *
 * Copyright (C) 2026 IZK
 */
final class DiscoveryController
{
    const HASH_SALT = 'xI25fpAapCQg';
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    /*
     * getGJCreators / getGJCreators19
     */
    public function creators(Request $request): Response
    {
        $rows = (new UserRepository($this->db))->leaderboardCreators(100);
        if (!$rows) {
            return Response::text('-1');
        }
        return Response::text((new GdUserEncoder())->leaderboard($rows));
    }
    /*
     * getGJDailyLevel
     *
     * Current clients:
     * type=0 daily
     * type=1 weekly
     *
     * Older clients:
     * weekly=0/1
     *
     * Event level type=2 will be implemented
     * separately.
     */
    public function daily(Request $request): Response
    {
        if (isset($_POST['type'])) {
            $type = (int) $_POST['type'];
        } else {
            $type = (int) ($_POST['weekly'] ?? 0) === 1 ? 1 : 0;
        }
        if (!in_array($type, [0, 1, 2], true)) {
            return Response::text('-1');
        }
        $kind = (function () use (&$type) {
            $__mcMatch1 = $type;
            if ($__mcMatch1 === 1) {
                return 'weekly';
            }
            if ($__mcMatch1 === 2) {
                return 'event';
            }
            return 'daily';
        })();
        $q = $this->db->prepare("\n            SELECT\n                id,\n                level_id,\n\n                GREATEST(\n                    0,\n                    TIMESTAMPDIFF(\n                        SECOND,\n                        UTC_TIMESTAMP(),\n                        ends_at\n                    )\n                ) AS remaining\n\n            FROM mucho_daily_rotation\n\n            WHERE kind=:kind\n              AND enabled=1\n              AND starts_at<=UTC_TIMESTAMP()\n              AND ends_at>UTC_TIMESTAMP()\n\n            ORDER BY\n                starts_at DESC,\n                id DESC\n\n            LIMIT 1\n        ");
        $q->execute(['kind' => $kind]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::text('-1');
        }
        /*
         * GD response:
         * daily index | seconds remaining
         */
        $index = (int) $row['id'];
        if ($type === 1) {
            $index += 100001;
        } elseif ($type === 2) {
            $index += 200001;
        }
        return Response::text($index . '|' . max(0, (int) $row['remaining']));
    }
    /*
     * getGJGauntlets / getGJGauntlets21
     */
    public function gauntlets(Request $request): Response
    {
        $rows = $this->db->query("\n                SELECT\n                    id,\n                    level1,\n                    level2,\n                    level3,\n                    level4,\n                    level5\n\n                FROM mucho_gauntlets\n\n                WHERE enabled=1\n                  AND level1>0\n                  AND level2>0\n                  AND level3>0\n                  AND level4>0\n                  AND level5>0\n\n                ORDER BY\n                    sort_order ASC,\n                    id ASC\n            ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return Response::text('-1');
        }
        $parts = [];
        $hashInput = '';
        foreach ($rows as $g) {
            $levels = implode(',', [(int) $g['level1'], (int) $g['level2'], (int) $g['level3'], (int) $g['level4'], (int) $g['level5']]);
            $parts[] = '1:' . (int) $g['id'] . ':3:' . $levels;
            /*
             * GD hash:
             * gauntlet ID + comma-separated level IDs
             */
            $hashInput .= (int) $g['id'] . $levels;
        }
        $hash = sha1($hashInput . self::HASH_SALT);
        return Response::text(implode('|', $parts) . '#' . $hash);
    }
    /*
     * getGJMapPacks / 20 / 21
     */
    public function mapPacks(Request $request): Response
    {
        $page = min(1000, max(0, (int) ($_POST['page'] ?? 0)));
        $limit = 10;
        $offset = $page * $limit;
        $total = (int) $this->db->query("\n                SELECT COUNT(*)\n                FROM mucho_map_packs\n                WHERE enabled=1\n            ")->fetchColumn();
        if ($total === 0) {
            return Response::text('-1');
        }
        $q = $this->db->prepare("\n            SELECT\n                id,\n                name,\n                levels,\n                stars,\n                coins,\n                difficulty,\n                color1,\n                color2\n\n            FROM mucho_map_packs\n\n            WHERE enabled=1\n\n            ORDER BY\n                sort_order ASC,\n                id ASC\n\n            LIMIT :limit\n            OFFSET :offset\n        ");
        $q->bindValue(':limit', $limit, PDO::PARAM_INT);
        $q->bindValue(':offset', $offset, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return Response::text('-1');
        }
        $parts = [];
        $hashInput = '';
        foreach ($rows as $m) {
            $id = (int) $m['id'];
            $stars = (int) $m['stars'];
            $coins = (int) $m['coins'];
            $name = str_replace([':', '|', '#', '~'], '', (string) $m['name']);
            $parts[] = '1:' . $id . ':2:' . $name . ':3:' . (string) $m['levels'] . ':4:' . $stars . ':5:' . $coins . ':6:' . (int) $m['difficulty'] . ':7:' . (string) $m['color1'] . ':8:' . (string) $m['color2'];
            /*
             * Map-pack hash:
             * first digit ID
             * last digit ID
             * stars
             * coins
             */
            $idString = (string) $id;
            $hashInput .= $idString[0] . $idString[strlen($idString) - 1] . $stars . $coins;
        }
        $hash = sha1($hashInput . self::HASH_SALT);
        return Response::text(implode('|', $parts) . '#' . $total . ':' . $offset . ':' . $limit . '#' . $hash);
    }
    private $db;
}
