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
            case 15:
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

            case 7:
                // Legacy "Magic" search: large object-count levels.
                $where[] = "l.object_count > 9999";
                break;

            case 10:
            case 19:
                $ids = $this->idList($search, 1000);
                if (!$ids) {
                    $where[] = "1 = 0";
                } else {
                    $marks = [];
                    foreach (array_values($ids) as $i => $id) {
                        $key = "map_pack_" . $i;
                        $marks[] = ":" . $key;
                        $params[$key] = $id;
                    }
                    $where[] = "l.level_id IN (" . implode(",", $marks) . ")";
                    $order = "l.level_id ASC";
                }
                break;

            case 12:
                $ids = $this->idList($search, 500);
                if (!$ids) {
                    $where[] = "1 = 0";
                } else {
                    $marks = [];
                    foreach (array_values($ids) as $i => $id) {
                        $key = "followed_" . $i;
                        $marks[] = ":" . $key;
                        $params[$key] = $id;
                    }
                    $where[] = "l.account_id IN (" . implode(",", $marks) . ")";
                    $order = "l.created_at DESC";
                }
                break;

            case 13:
                $accountId = $this->integerString($search);
                if ($accountId <= 0) {
                    $where[] = "1 = 0";
                } else {
                    $where[] = "l.account_id IN (
                        SELECT f.friend_account_id
                        FROM friends f
                        WHERE f.account_id = :friends_account
                    )";
                    $params["friends_account"] = $accountId;
                    $order = "l.created_at DESC";
                }
                break;

            case 16:
                $where[] = "l.epic > 0";
                $order = "l.updated_at DESC";
                break;

            case 17:
                $where[] = $gameVersion > 21
                    ? "(l.featured > 0 OR l.epic > 0)"
                    : "l.featured > 0";
                $order = "l.updated_at DESC";
                break;

            case 25:
                $listId = $this->integerString($search);
                if ($listId <= 0) {
                    $where[] = "1 = 0";
                    break;
                }

                try {
                    $listStmt = $this->pdo->prepare(
                        "SELECT level_ids
                         FROM mucho_level_lists
                         WHERE list_id = :list_id
                         LIMIT 1"
                    );
                    $listStmt->execute(["list_id" => $listId]);
                    $levelIds = $this->idList(
                        (string)$listStmt->fetchColumn(),
                        1000
                    );
                } catch (\Throwable) {
                    $levelIds = [];
                }

                if (!$levelIds) {
                    $where[] = "1 = 0";
                } else {
                    $marks = [];
                    foreach (array_values($levelIds) as $i => $id) {
                        $key = "list_level_" . $i;
                        $marks[] = ":" . $key;
                        $params[$key] = $id;
                    }
                    $where[] = "l.level_id IN (" . implode(",", $marks) . ")";
                    $order = "l.level_id ASC";
                }
                break;

            case 26:
                $ids = $this->idList($search, 1000);
                if (!$ids) {
                    $where[] = "1 = 0";
                } else {
                    $marks = [];
                    foreach (array_values($ids) as $i => $id) {
                        $key = "local_list_" . $i;
                        $marks[] = ":" . $key;
                        $params[$key] = $id;
                    }
                    $where[] = "l.level_id IN (" . implode(",", $marks) . ")";
                    $order = "l.level_id ASC";
                }
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

    /** @return list<int> */
    private function idList(string $value, int $maxItems): array
    {
        $ids = [];

        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '' || !ctype_digit($item)) {
                continue;
            }

            $id = (int)$item;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
            if (count($ids) >= $maxItems) {
                break;
            }
        }

        return $ids;
    }

    private function integerString(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\\d+$/', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
