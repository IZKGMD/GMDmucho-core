<?php

declare (strict_types=1);
namespace MuchoCore\Score;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDO;
use RuntimeException;
use Throwable;
/*
 * MuchoCore Geometry Dash Level Scores
 *
 * Copyright (C) 2026 IZK
 */
final class LevelScoreController
{
    public function __construct(PDO $db, AccountAuthenticator $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }
    /**
     * GD 2.209 level scoreboard:
     * - without percent: authenticate and fetch, never submit a 0% score;
     * - when type is absent: use the client's default friends table (0);
     * - with percent (including explicit 0): validate then submit.
     * Reference: boomlings.dev/endpoints/levels/getGJLevelScores211
     */
    public function regular(Request $request): Response
    {
        $data = !empty($request->post) ? $request->post : $_POST;
        $accountId = $this->strictInt($data['accountID'] ?? null);
        $gjp = $request->gdCredential();
        $levelId = $this->strictInt($data['levelID'] ?? null);
        // Explicit malformed type must not silently become "friends".
        if (array_key_exists('type', $data)
            && !(is_int($data['type']) ||
                 (is_string($data['type']) &&
                  preg_match('/^[0-9]{1,2}$/D', $data['type']) === 1))) {
            return Response::text('-1');
        }
        $type = array_key_exists('type', $data)
            ? $this->strictInt($data['type']) : 0;

        // A missing percent means "read the leaderboard", not "submit 0".
        // Invalid values must not silently turn into an actual 0% upload.
        $hasPercent = array_key_exists('percent', $data);
        $percent = 0;
        if ($hasPercent) {
            $raw = $data['percent'];
            if (!is_int($raw) &&
                (!is_string($raw) || preg_match('/^[0-9]{1,3}$/D', $raw) !== 1)) {
                return Response::text('-1');
            }
            $percent = (int)$raw;
        }

        if ($accountId <= 0 || $levelId <= 0 || $gjp === ''
            || $percent < 0 || $percent > 100
            || !in_array($type, [0, 1, 2], true)) {
            return Response::text('-1');
        }

        try {
            $this->auth->authenticate($accountId, $gjp);
            if (!$this->levelExists($levelId)) {
                return Response::text('-1');
            }

            // A malformed timely ID is not the ordinary leaderboard.
            if (array_key_exists('s10', $data)
                && !(is_int($data['s10']) ||
                     (is_string($data['s10']) &&
                      preg_match('/^[0-9]{1,10}$/D', $data['s10']) === 1))) {
                return Response::text('-1');
            }
            $dailyId = $this->strictInt($data['s10'] ?? 0);
            if ($dailyId < 0) {
                return Response::text('-1');
            }
            $isDaily = $dailyId > 0 ? 1 : 0;

            if ($hasPercent) {
                $attempts = $this->decodedNumber($data, 's1', 8354, 0, 1000000);
                $clicks = $this->decodedNumber($data, 's2', 3991, 0, 10000000);
                $playTime = $this->decodedNumber($data, 's3', 4085, 0, 86400);
                $coins = $this->decodedNumber($data, 's9', 5819, 0, 3);
                $progresses = $this->decodeProgresses($data['s6'] ?? '');

                if ($attempts === null || $clicks === null ||
                    $playTime === null || $coins === null ||
                    $progresses === null || strlen($progresses) > 100000) {
                    return Response::text('-1');
                }

                $scoreId = $this->saveScore(
                    $accountId, $levelId, $percent, $coins, $attempts,
                    $clicks, $playTime, $progresses, $dailyId, $isDaily
                );
                if ($scoreId !== null) {
                    ScoreIntegrity::record(
                        $this->db,
                        $isDaily ? 'regular_daily' : 'regular',
                        $scoreId,
                        $accountId,
                        $levelId,
                        ScoreIntegrity::evaluateRegular(
                            $percent, $attempts, $clicks, $playTime, $progresses
                        )
                    );
                }
            }

            return Response::text(
                $this->leaderboard($accountId, $levelId, $isDaily, $type)
            );
        } catch (Throwable $e) {
            return Response::text('-1');
        }
    }

    private function levelExists(int $levelId): bool
    {
        $q = $this->db->prepare("\n            SELECT 1\n\n            FROM levels\n\n            WHERE level_id=:level\n              AND is_deleted=0\n\n            LIMIT 1\n        ");
        $q->execute(['level' => $levelId]);
        return (bool) $q->fetchColumn();
    }
    private function saveScore(int $accountId, int $levelId, int $percent, int $coins, int $attempts, int $clicks, int $playTime, string $progresses, int $dailyId, int $isDaily)
    {
        $now = time();
        /*
         * The logical score key is unique in the database. Use one atomic
         * upsert so two first-time submissions cannot both observe "no row"
         * and race into a duplicate-key failure. Worse progress is a no-op.
         */
        $q = $this->db->prepare("\n            INSERT INTO mucho_level_scores\n            (\n                account_id,\n                level_id,\n                is_daily,\n                daily_id,\n                percent,\n                coins,\n                attempts,\n                clicks,\n                play_time,\n                progresses,\n                created_at,\n                updated_at\n            )\n            VALUES\n            (\n                :account,\n                :level,\n                :is_daily,\n                :daily_id,\n                :percent,\n                :coins,\n                :attempts,\n                :clicks,\n                :play_time,\n                :progresses,\n                :created_at,\n                :updated_at\n            )\n            ON DUPLICATE KEY UPDATE\n                score_id=LAST_INSERT_ID(score_id),\n                daily_id=IF(VALUES(percent)>=percent,VALUES(daily_id),daily_id),\n                coins=IF(VALUES(percent)>=percent,VALUES(coins),coins),\n                attempts=IF(VALUES(percent)>=percent,VALUES(attempts),attempts),\n                clicks=IF(VALUES(percent)>=percent,VALUES(clicks),clicks),\n                play_time=IF(VALUES(percent)>=percent,VALUES(play_time),play_time),\n                progresses=IF(VALUES(percent)>=percent,VALUES(progresses),progresses),\n                updated_at=IF(VALUES(percent)>=percent,VALUES(updated_at),updated_at),\n                percent=GREATEST(percent,VALUES(percent))\n        ");
        $q->execute(['account' => $accountId, 'level' => $levelId, 'is_daily' => $isDaily, 'daily_id' => $dailyId, 'percent' => $percent, 'coins' => $coins, 'attempts' => $attempts, 'clicks' => $clicks, 'play_time' => $playTime, 'progresses' => $progresses, 'created_at' => $now, 'updated_at' => $now]);
        if ($q->rowCount() === 0) {
            return null;
        }
        $scoreId = (int) $this->db->lastInsertId();
        if ($scoreId <= 0) {
            throw new RuntimeException('Score upsert failed.');
        }
        return $scoreId;
    }
    /**
     * Render the native GD level-score list with one bounded SQL request.
     *
     * Previously each of up to 100 rows called getProfileByTarget(), which
     * performed several additional SELECTs and a possible profile INSERT.
     * This path only needs the public icon/identity fields, so joining the
     * two indexed account tables is both sufficient and safer for read-only
     * leaderboards. Keep bans, friend scopes and quarantine ahead of LIMIT.
     */
    private function leaderboard(
        int $accountId,
        int $levelId,
        int $isDaily,
        int $type
    ): string {
        $params = [
            'level' => $levelId,
            'daily' => $isDaily,
        ];

        $where = '
            s.level_id=:level
            AND s.is_daily=:daily
            AND a.is_active=1
            AND a.is_banned=0
        ';

        if (ScoreIntegrity::quarantineEnabled()) {
            $where .= "
                AND NOT EXISTS (
                    SELECT 1
                    FROM mucho_score_integrity_events si
                    WHERE si.score_type=:integrity_type
                      AND si.score_id=s.score_id
                      AND si.status IN ('suspicious','quarantined')
                      AND si.risk_score>=70
                )
            ";
            $params['integrity_type'] =
                $isDaily ? 'regular_daily' : 'regular';
        }

        // type=0 friends, type=1 global, type=2 recent.
        if ($type === 0) {
            $friends = $this->friendAccountIds($accountId);
            if ($friends === []) {
                return '';
            }

            $placeholders = [];
            foreach (array_values($friends) as $i => $friendId) {
                $key = 'friend_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $friendId;
            }

            $where .= '
                AND s.account_id IN (' .
                    implode(',', $placeholders) . ')
            ';
        } elseif ($type === 2) {
            $where .= ' AND s.updated_at>:recent';
            $params['recent'] = time() - 604800;
        }

        $sql = "
            SELECT
                s.account_id,
                s.percent,
                s.coins,
                s.updated_at,
                a.username,
                COALESCE(p.user_id, s.account_id) AS user_id,
                COALESCE(p.cube, 1) AS cube,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.color3, 0) AS color3,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.special, 0) AS special
            FROM mucho_level_scores s
            INNER JOIN accounts a ON a.account_id=s.account_id
            LEFT JOIN profiles p ON p.account_id=s.account_id
            WHERE " . $where . "
            ORDER BY
                s.percent DESC,
                s.updated_at ASC
            LIMIT 100
        ";

        $query = $this->db->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return '';
        }

        $out = '';
        foreach ($rows as $score) {
            $username = (string)$score['username'];
            if ($username === '') {
                continue;
            }
            $username = \MuchoCore\Protocol\ProtocolText::username($username);

            $id = (int)$score['account_id'];
            $userId = (int)$score['user_id'];
            if ($userId <= 0) {
                $userId = $id;
            }

            $percent = (int)$score['percent'];
            $place = $percent === 100 ? 1 : ($percent > 75 ? 2 : 3);

            $out .= '1:' . $username
                . ':2:' . $userId
                . ':9:' . (int)$score['cube']
                . ':10:' . (int)$score['color1']
                . ':11:' . (int)$score['color2']
                . ':51:' . (int)$score['color3']
                . ':14:' . (int)$score['icon_type']
                . ':15:' . (int)$score['special']
                . ':16:' . $id
                . ':3:' . $percent
                . ':6:' . $place
                . ':13:' . (int)$score['coins']
                . ':42:' . gmdate('d/m/Y G.i', (int)$score['updated_at'])
                . '|';
        }

        return rtrim($out, '|');
    }

    private function friendAccountIds(int $accountId): array
    {
        $ids = [$accountId => $accountId];
        $pairs = [['account_id', 'friend_account_id'], ['account_id', 'friend_id'], ['account_id_1', 'account_id_2'], ['account1_id', 'account2_id'], ['user_id', 'friend_id'], ['user_id_1', 'user_id_2'], ['user1', 'user2'], ['requester_id', 'addressee_id']];
        foreach (['friends', 'friendships'] as $table) {
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM `" . $table . "`")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $__mcCaught) {
                continue;
            }
            if (!$cols) {
                continue;
            }
            $names = [];
            foreach ($cols as $col) {
                $names[] = (string) $col['Field'];
            }
            foreach ($pairs as list($left, $right)) {
                if (!in_array($left, $names, true) || !in_array($right, $names, true)) {
                    continue;
                }
                $q = $this->db->prepare("SELECT\n                        `" . $left . "` AS a,\n                        `" . $right . "` AS b\n\n                     FROM `" . $table . "`\n\n                     WHERE `" . $left . "`=:id_left\n                        OR `" . $right . "`=:id_right");
                $q->execute(['id_left' => $accountId, 'id_right' => $accountId]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $a = (int) $row['a'];
                    $b = (int) $row['b'];
                    if ($a === $accountId && $b > 0) {
                        $ids[$b] = $b;
                    }
                    if ($b === $accountId && $a > 0) {
                        $ids[$a] = $a;
                    }
                }
                return array_values($ids);
            }
        }
        /*
         * Если схема друзей неизвестна —
         * friends leaderboard безопасно
         * покажет хотя бы самого игрока.
         */
        return array_values($ids);
    }
    private function decodedNumber(array $data, string $key, int $offset, int $min, int $max)
    {
        if (!array_key_exists($key, $data) || $data[$key] === '') {
            return 0;
        }
        $raw = $data[$key];
        if (!(is_int($raw) || is_string($raw)) || preg_match('/^-?\d+$/D', (string) $raw) !== 1) {
            return null;
        }
        $value = (int) $raw - $offset;
        if ($value < $min || $value > $max) {
            return null;
        }
        return $value;
    }
    private function strictInt($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        return 0;
    }
    private function decodeProgresses($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $encoded = strtr($value, '-_', '+/');
        $mod = strlen($encoded) % 4;
        if ($mod !== 0) {
            $encoded .= str_repeat('=', 4 - $mod);
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return null;
        }
        $key = '41274';
        $out = '';
        $keyLength = strlen($key);
        for ($i = 0, $length = strlen($decoded); $i < $length; $i++) {
            $out .= chr(ord($decoded[$i]) ^ ord($key[$i % $keyLength]));
        }
        return $out;
    }
    private function value(array $row, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }
        return $default;
    }
    private $db;
    private $auth;
}
