<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use PDO;

final readonly class LevelRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function search(
        int $type,
        string $search,
        int $gameVersion,
        int $offset,
        int $limit,
        int $demonFilter = 0
    ): array {
        $where = [
            "l.is_deleted = 0",
            "l.is_unlisted = 0",
        ];

        $params = [];
        $historyJoin = '';

        /* Mucho Demon Filter v7: 5 vanilla + Insaned + Brutal + Nightmare.
         * Standalone plain Demon was removed. Legacy filter id 11 falls back to Hard Demon. */
        if ($demonFilter > 0) {
            $where[] = "l.demon = 1";
            switch ($demonFilter) {
                case 1:
                case 7:  $where[] = "l.demon_difficulty = 3"; break;
                case 2:
                case 8:  $where[] = "l.demon_difficulty = 4"; break;
                case 3:
                case 6:
                case 11: $where[] = "COALESCE(l.demon_difficulty, 0) = 0"; break;
                case 4:
                case 9:  $where[] = "l.demon_difficulty = 5"; break;
                case 5:
                case 10: $where[] = "l.demon_difficulty = 6"; break;
                case 12: $where[] = "l.demon_difficulty = 8"; break;
                case 13: $where[] = "l.demon_difficulty = 9"; break;
                case 14: $where[] = "l.demon_difficulty = 10"; break;
                default: $where[] = "1 = 0"; break;
            }
        }

        // Фильтруем по версии только если клиент явно передал конкретную версию > 0
        if ($gameVersion > 0) {
            $where[] = "l.game_version <= :game_version";
            $params["game_version"] = $gameVersion;
        }

        $order = "l.created_at DESC";

        switch ($type) {
            case 0:
                $order = "l.likes DESC, l.level_id DESC";
                if ($search !== "") {
                    if (ctype_digit($search)) {
                        $where[] = "l.level_id = :level_id";
                        $params["level_id"] = (int) $search;
                    } else {
                        $where[] = "l.name LIKE :search";
                        $params["search"] = "%" . $search . "%";
                    }
                }
                break;

            case 1:
                $order = "l.downloads DESC, l.level_id DESC";
                break;

            case 2:
                $order = "l.likes DESC, l.level_id DESC";
                break;

            case 3:
                $where[] = "l.created_at >= (CURRENT_TIMESTAMP - INTERVAL 7 DAY)";
                $order = "l.likes DESC, l.level_id DESC";
                break;

            case 5:
                if (ctype_digit($search)) {
                    $where[] = "(p.user_id = :uid OR l.account_id = :aid)";
                    $params["uid"] = (int) $search;
                    $params["aid"] = (int) $search;
                }
                break;

            case 6:
                $where[] = "(l.featured = 1 OR l.epic > 0)";
                $order = "l.updated_at DESC";
                break;

            case 21:
                $historyJoin = "
                    INNER JOIN mucho_daily_rotation mdr
                      ON mdr.level_id = l.level_id
                     AND mdr.kind = 'daily'
                ";
                $order = "mdr.id DESC";
                break;

            case 22:
                $historyJoin = "
                    INNER JOIN mucho_daily_rotation mdr
                      ON mdr.level_id = l.level_id
                     AND mdr.kind = 'weekly'
                ";
                $order = "mdr.id DESC";
                break;

            case 23:
                $historyJoin = "
                    INNER JOIN mucho_daily_rotation mdr
                      ON mdr.level_id = l.level_id
                     AND mdr.kind = 'event'
                ";
                $order = "mdr.id DESC";
                break;

            case 11:
                $where[] = "l.stars > 0";
                $order = "l.updated_at DESC";
                break;
        }

        $whereSql = implode(" AND ", $where);

        $from = "
            FROM levels l
            {$historyJoin}
            LEFT JOIN accounts a ON a.account_id = l.account_id
            LEFT JOIN profiles p ON p.account_id = l.account_id
        ";

        $count = $this->pdo->prepare(
            "SELECT COUNT(*) " . $from . " WHERE " . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = "
            SELECT
                l.*,
                COALESCE(a.username, 'Player') as username,
                COALESCE(p.user_id, l.account_id) as user_id
            " . $from . " WHERE " . $whereSql . " ORDER BY " . $order . " LIMIT " . $limit . " OFFSET " . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            "levels" => $stmt->fetchAll(PDO::FETCH_ASSOC),
            "total" => $total,
        ];
    }
}
