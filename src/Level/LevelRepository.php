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
        int $demonFilter = 0,
        array $filters = []
    ): array {
        $where = [
            "l.is_deleted = 0",
            "l.is_unlisted = 0",
        ];

        $params = [];
        $historyJoin = '';

        $completedLevels = $this->numberList(
            $filters['completedLevels'] ?? ''
        );

        if (($filters['uncompleted'] ?? false) && $completedLevels !== []) {
            $where[] = 'l.level_id NOT IN (' .
                implode(',', $completedLevels) . ')';
        }

        if (($filters['onlyCompleted'] ?? false) && $completedLevels !== []) {
            $where[] = 'l.level_id IN (' .
                implode(',', $completedLevels) . ')';
        }

        if (($filters['coins'] ?? false)) {
            $where[] = 'l.coins_verified = 1 AND l.coins > 0';
        }

        if (($filters['twoPlayer'] ?? false)) {
            $where[] = 'l.two_player = 1';
        }

        if (($filters['star'] ?? false)) {
            $where[] = 'l.stars > 0';
        }

        if (($filters['noStar'] ?? false)) {
            $where[] = 'l.stars = 0';
        }

        if (($filters['original'] ?? false)) {
            $where[] = 'l.original_level_id = 0';
        }

        $lengths = $this->numberList(
            $filters['len'] ?? ''
        );

        if ($lengths !== []) {
            $where[] = 'l.length IN (' . implode(',', $lengths) . ')';
        }

        $song = trim((string)($filters['song'] ?? ''));
        if ($song !== '' && ctype_digit($song)) {
            if (($filters['customSong'] ?? false)) {
                $where[] = 'l.song_id = :song_id';
                $params['song_id'] = (int)$song;
            } else {
                $where[] = 'l.audio_track = :audio_track';
                $params['audio_track'] = max(
                    0,
                    ((int)$song) - 1
                );
            }
        }

        $gauntlet = trim((string)($filters['gauntlet'] ?? ''));
        if ($gauntlet !== '' && ctype_digit($gauntlet)) {
            $gq = $this->pdo->prepare(
                'SELECT level1, level2, level3, level4, level5
                 FROM mucho_gauntlets
                 WHERE id=:id
                   AND enabled=1
                 LIMIT 1'
            );
            $gq->execute(['id'=>(int)$gauntlet]);
            $g = $gq->fetch(PDO::FETCH_ASSOC);

            if (!$g) {
                $where[] = '1 = 0';
            } else {
                $ids = array_values(array_filter(
                    array_map(
                        'intval',
                        [
                            $g['level1'] ?? 0,
                            $g['level2'] ?? 0,
                            $g['level3'] ?? 0,
                            $g['level4'] ?? 0,
                            $g['level5'] ?? 0,
                        ]
                    ),
                    static fn(int $id): bool => $id > 0
                ));

                $where[] = $ids === []
                    ? '1 = 0'
                    : 'l.level_id IN (' . implode(',', array_unique($ids)) . ')';
            }
        }

        $difficulty = $this->numberList(
            (string)($filters['diff'] ?? '')
        );

        if ($difficulty !== []) {
            $difficulty = array_map(
                static fn(int $value): int =>
                    $value > 0 && $value < 10
                        ? $value * 10
                        : $value,
                $difficulty
            );

            $where[] =
                'l.difficulty IN (' .
                implode(',', $difficulty) .
                ') AND l.auto_level=0 AND l.demon=0';
        }

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

        if (($filters['featured'] ?? false)) {
            $where[] = 'l.featured = 1';
        }

        $epicFlags = [];
        if (($filters['epic'] ?? false)) {
            $epicFlags[] = 1;
        }
        if (($filters['mythic'] ?? false)) {
            $epicFlags[] = 3;
        }
        if (($filters['legendary'] ?? false)) {
            $epicFlags[] = 2;
        }
        if ($epicFlags !== []) {
            $where[] = 'l.epic IN (' . implode(',', $epicFlags) . ')';
        }

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
            case 17:
                $where[] = "(l.featured = 1 OR l.epic > 0)";
                $order = "l.updated_at DESC";
                break;

            case 16:
                $where[] = "l.epic > 0";
                $order = "l.updated_at DESC";
                break;

            case 7:
                $where[] = "l.object_count > 9999";
                break;

            case 10:
            case 19:
            case 25:
            case 26:
                $ids = $this->numberList($search);
                if ($ids === []) {
                    $where[] = "1 = 0";
                } else {
                    $where[] = "l.level_id IN (" . implode(',', $ids) . ")";
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
            LEFT JOIN songs s ON s.id = l.song_id
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
                COALESCE(p.user_id, l.account_id) as user_id,
                s.id as song_row_id,
                s.name as song_name,
                s.author_id as song_author_id,
                s.author_name as song_author_name,
                s.size as song_size,
                s.download_url as song_download_url,
                s.is_verified as song_is_verified
            " . $from . " WHERE " . $whereSql . " ORDER BY " . $order . " LIMIT " . $limit . " OFFSET " . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            "levels" => $stmt->fetchAll(PDO::FETCH_ASSOC),
            "total" => $total,
        ];
    }

    private function numberList(
        mixed $value
    ): array {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        if (preg_match('/^\d+(?:,\d+)*$/', trim($value)) !== 1) {
            return [];
        }

        return array_values(
            array_unique(
                array_map('intval', explode(',', trim($value)))
            )
        );
    }
}
