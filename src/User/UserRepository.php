<?php

declare(strict_types=1);

namespace MuchoCore\User;

use PDO;

final readonly class UserRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function search(string $query, int $offset = 0, int $limit = 100): array
    {
        [$filter, $parameters] = $this->searchFilter($query);
        $sql = '
            SELECT
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.diamonds, 0) AS diamonds,
                COALESCE(p.secret_coins, 0) AS secret_coins,
                COALESCE(p.user_coins, 0) AS user_coins,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.icon_id, 1) AS icon_id,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.glow, 0) AS glow,
                COALESCE(p.demon_info, "") AS demon_info,
                COALESCE(p.star_info, "") AS star_info,
                COALESCE(p.platformer_info, "") AS platformer_info,
                COALESCE(p.explosion, 1) AS explosion,
                COALESCE(p.cube, 1) AS cube,
                COALESCE(p.ship, 1) AS ship,
                COALESCE(p.ball, 1) AS ball,
                COALESCE(p.ufo, 1) AS ufo,
                COALESCE(p.wave, 1) AS wave,
                COALESCE(p.robot, 1) AS robot,
                COALESCE(p.spider, 1) AS spider,
                COALESCE(p.swing, 1) AS swing,
                COALESCE(p.jetpack, 1) AS jetpack,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.color3, 0) AS color3,
                COALESCE(p.special, 0) AS special,
                p.user_id,
                a.account_id,
                a.username,
                COALESCE(ar.code, \'user\') AS role_code,
                COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), \'\') AS clan_tag
            FROM accounts a
            LEFT JOIN profiles p
                ON p.account_id = a.account_id
            LEFT JOIN roles ar
                ON ar.id = a.role_id
            WHERE ' . $filter . '
            ORDER BY COALESCE(p.stars, 0) DESC, a.account_id ASC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCount(string $query): int
    {
        [$filter, $parameters] = $this->searchFilter($query);
        $join = isset($parameters['user_id']) ? ' LEFT JOIN profiles p ON p.account_id = a.account_id' : '';
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM accounts a' . $join . ' WHERE ' . $filter);
        $stmt->execute($parameters);
        return (int)$stmt->fetchColumn();
    }

    private function searchFilter(string $query): array
    {
        $parameters = ['query' => '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'];
        $name = "a.username LIKE :query ESCAPE '!'";
        $userId = ctype_digit($query) ? (int)$query : 0;
        if ($userId > 0) {
            $parameters['user_id'] = $userId;
            $name .= ' OR p.user_id = :user_id';
        }
        return ['a.is_active = 1 AND a.is_banned = 0 AND (' . $name . ')', $parameters];
    }

    public function findAccountIdByUserId(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT account_id
             FROM profiles
             WHERE user_id = :user_id
             LIMIT 1'
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    /** Accurate 2.209 profile counters using indexed social/notification tables. */
    public function getGd2209ProfileCounters(int $targetId, int $viewerId): array
    {
        $query = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM mucho_follows
                 WHERE followed_account_id=:followers_for) AS followers,
                (SELECT COUNT(*) FROM mucho_follows
                 WHERE follower_account_id=:following_for) AS following,
                (SELECT COUNT(*) FROM mucho_follows
                 WHERE follower_account_id=:viewer AND followed_account_id=:peer)
                    AS is_following,
                (SELECT COUNT(*) FROM mucho_notifications
                 WHERE account_id=:notifications_for AND is_read=0)
                    AS notification_count'
        );
        $query->execute([
            'followers_for' => $targetId,
            'following_for' => $targetId,
            'viewer' => max(0, $viewerId),
            'peer' => $targetId,
            'notifications_for' => $viewerId === $targetId ? $targetId : 0,
        ]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getProfileByTarget(
        int $targetAccountId,
        int $viewerAccountId = 0
    ): ?array
    {
        $sql = '
            SELECT
                p.user_id,
                a.account_id,
                a.username,
                COALESCE(ar.code, \'user\') AS role_code,
                COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), \'\') AS clan_tag,
                COALESCE(a.messages_state, 0) AS message_state,
                COALESCE(a.friend_requests_state, 0) AS friend_request_state,
                COALESCE(a.comments_state, 0) AS comment_history_state,
                COALESCE(a.youtube_url, "") AS youtube,
                COALESCE(a.twitter, "") AS twitter,
                COALESCE(a.twitch, "") AS twitch,
                COALESCE(a.instagram, "") AS instagram,
                COALESCE(a.discord, "") AS discord,
                COALESCE(a.tiktok, "") AS tiktok,
                COALESCE(a.custom_link, "") AS custom_link,
                a.created_at AS registered_at,
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.diamonds, 0) AS diamonds,
                COALESCE(p.secret_coins, 0) AS secret_coins,
                COALESCE(p.user_coins, 0) AS user_coins,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.icon_id, 1) AS icon_id,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.glow, 0) AS glow,
                COALESCE(p.demon_info, "") AS demon_info,
                COALESCE(p.star_info, "") AS star_info,
                COALESCE(p.platformer_info, "") AS platformer_info,
                COALESCE(p.explosion, 1) AS explosion,
                COALESCE(p.cube, 1) AS cube,
                COALESCE(p.ship, 1) AS ship,
                COALESCE(p.ball, 1) AS ball,
                COALESCE(p.ufo, 1) AS ufo,
                COALESCE(p.wave, 1) AS wave,
                COALESCE(p.robot, 1) AS robot,
                COALESCE(p.spider, 1) AS spider,
                COALESCE(p.swing, 1) AS swing,
                COALESCE(p.jetpack, 1) AS jetpack,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.color3, 0) AS color3,
                COALESCE(p.special, 0) AS special
            FROM accounts a
            LEFT JOIN profiles p
                ON p.account_id = a.account_id
            LEFT JOIN roles ar
                ON ar.id = a.role_id
            WHERE a.account_id = :account_id
              AND a.is_active = 1 AND a.is_banned = 0
            LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['account_id' => $targetAccountId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($row['user_id'] === null) {
            // Old imports can contain accounts without profiles. Only repair a
            // missing row, and re-read it after a concurrent score update.
            $insert = $this->pdo->prepare('INSERT IGNORE INTO profiles (account_id)
                SELECT account_id FROM accounts WHERE account_id = :account_id
                  AND is_active = 1 AND is_banned = 0');
            $insert->execute(['account_id' => $targetAccountId]);
            $stmt->execute(['account_id' => $targetAccountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['user_id'] === null) {
                return null;
            }
        }

        $row['friend_state'] = 0;
        if ($viewerAccountId <= 0) {
            return $row;
        }

        if ($viewerAccountId === $targetAccountId) {
            $counters = $this->pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM messages
                     WHERE to_account_id=? AND is_read=0 AND is_receiver_deleted=0) AS messages_count,
                    (SELECT COUNT(*) FROM friend_requests
                     WHERE to_account_id=? AND is_read=0) AS friend_requests_count,
                    (SELECT COUNT(*) FROM friends
                     WHERE account_id=? AND is_new=1) AS friends_count'
            );
            $counters->execute([$viewerAccountId, $viewerAccountId, $viewerAccountId]);
            return array_merge($row, $counters->fetch(PDO::FETCH_ASSOC) ?: []);
        }

        $relationship = $this->pdo->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM blocks WHERE account_id=? AND blocked_account_id=?)
                OR EXISTS(SELECT 1 FROM blocks WHERE account_id=? AND blocked_account_id=?) AS blocked,
                EXISTS(SELECT 1 FROM friends WHERE account_id=? AND friend_account_id=?) AS friends,
                EXISTS(SELECT 1 FROM friend_requests WHERE account_id=? AND to_account_id=?) AS outgoing,
                incoming.id, incoming.comment, incoming.created_at
             FROM (SELECT 1) AS profile_view
             LEFT JOIN friend_requests incoming
                ON incoming.account_id=? AND incoming.to_account_id=? AND incoming.is_read<>2'
        );
        $relationship->execute([
            $viewerAccountId, $targetAccountId, $targetAccountId, $viewerAccountId,
            $viewerAccountId, $targetAccountId, $viewerAccountId, $targetAccountId,
            $targetAccountId, $viewerAccountId,
        ]);
        $state = $relationship->fetch(PDO::FETCH_ASSOC);
        if (!$state || (int)$state['blocked'] === 1) {
            return null;
        }
        if ((int)$state['friends'] === 1) {
            $row['friend_state'] = 1;
        } elseif ($state['id'] !== null) {
            $row['friend_state'] = 3;
            $row['request_id'] = (int)$state['id'];
            $row['request_comment'] = (string)$state['comment'];
            $row['request_date'] = date('d/m/Y G.i', strtotime((string)$state['created_at']));
        } elseif ((int)$state['outgoing'] === 1) {
            $row['friend_state'] = 4;
        }

        return $row;
    }

    public function leaderboardTop(int $limit = 1000, int $gameVersion = 0): array
    {
        return $this->leaderboardByMetric('stars', $limit, $gameVersion);
    }

    public function leaderboardCreators(int $limit = 1000, int $gameVersion = 0): array
    {
        return $this->leaderboardByMetric('creator_points', $limit, $gameVersion);
    }

    private function leaderboardByMetric(string $metric, int $limit, int $gameVersion): array
    {
        // Select leaders before fetching full profiles and resolving their
        // clan tags. Filters run before LIMIT so rank stays gap-free.
        $versionFilter = match (true) {
            $gameVersion >= 20 => 'AND ranked.game_version >= 20',
            $gameVersion > 0 => 'AND ranked.game_version > 0 AND ranked.game_version < 20',
            default => '',
        };
        $sql = "
            SELECT
                p.*,
                a.username,
                COALESCE((
                    SELECT c.tag FROM mucho_clan_members cm
                    INNER JOIN mucho_clans c ON c.clan_id = cm.clan_id
                    WHERE cm.account_id = p.account_id LIMIT 1
                ), '') AS clan_tag,
                COALESCE(ar.code, 'user') AS role_code
            FROM (
                SELECT ranked.account_id, ranked.{$metric}
                FROM profiles ranked
                INNER JOIN accounts eligible ON eligible.account_id = ranked.account_id
                WHERE ranked.{$metric} > 0
                  AND eligible.is_active = 1
                  AND eligible.is_banned = 0
                  {$versionFilter}
                ORDER BY ranked.{$metric} DESC, ranked.account_id ASC
                LIMIT {$limit}
            ) leaders
            INNER JOIN profiles p ON p.account_id = leaders.account_id
            INNER JOIN accounts a ON a.account_id = leaders.account_id
            LEFT JOIN roles ar ON ar.id = a.role_id
            ORDER BY leaders.{$metric} DESC, leaders.account_id ASC";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    public function leaderboardFriends(int $accountId, int $limit = 100, int $gameVersion = 0): array
    {
        if ($accountId <= 0) {
            return [];
        }

        $sql = '
            WITH FriendIds AS (
                SELECT friend_account_id AS account_id
                FROM friends
                WHERE account_id = :account_a
                UNION
                SELECT account_id
                FROM friends
                WHERE friend_account_id = :account_b
                UNION
                SELECT :self_id
            ),
            RankedFriends AS (
                SELECT
                    p.*,
                    a.username,
                    COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=p.account_id LIMIT 1), \'\') AS clan_tag,
                    COALESCE(ar.code, \'user\') AS role_code,
                    ROW_NUMBER() OVER (
                        ORDER BY p.stars DESC, p.account_id ASC
                    ) AS `rank`
                FROM FriendIds f
                INNER JOIN profiles p
                    ON p.account_id = f.account_id
                INNER JOIN accounts a
                    ON a.account_id = f.account_id
                LEFT JOIN roles ar
                    ON ar.id = a.role_id
                WHERE a.is_active=1
                  AND a.is_banned=0
                  AND (:game_version < 20 OR COALESCE(p.game_version, 0) >= 20)
            )
            SELECT *
            FROM RankedFriends
            ORDER BY `rank`
            LIMIT ' . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'account_a' => $accountId,
            'account_b' => $accountId,
            'self_id' => $accountId,
            'game_version' => $gameVersion,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function leaderboardRelative(int $accountId, int $limit = 50, int $gameVersion = 0): array
    {
        $versionFilter = match (true) {
            $gameVersion >= 20 => 'AND ranked.game_version >= 20',
            $gameVersion > 0 => 'AND ranked.game_version > 0 AND ranked.game_version < 20',
            default => '',
        };
        $sql = "WITH RankedProfiles AS (
            SELECT ranked.account_id,
                   ROW_NUMBER() OVER (ORDER BY ranked.stars DESC, ranked.account_id ASC) AS `rank`
            FROM profiles ranked
            INNER JOIN accounts eligible ON eligible.account_id = ranked.account_id
            WHERE eligible.is_active = 1 AND eligible.is_banned = 0
            {$versionFilter}
        ),
        TargetRank AS (
            SELECT `rank` FROM RankedProfiles WHERE account_id = :account_id
        ),
        Nearby AS (
            SELECT r.account_id, r.`rank`
            FROM RankedProfiles r CROSS JOIN TargetRank t
            WHERE r.`rank` BETWEEN GREATEST(CAST(t.`rank` AS SIGNED) - 25, 1) AND t.`rank` + 25
            ORDER BY r.`rank`
            LIMIT " . (int)$limit . "
        )
        SELECT p.*, a.username, n.`rank`,
               COALESCE((SELECT c.tag FROM mucho_clan_members cm
                   INNER JOIN mucho_clans c ON c.clan_id = cm.clan_id
                   WHERE cm.account_id = n.account_id LIMIT 1), '') AS clan_tag,
               COALESCE(ar.code, 'user') AS role_code
        FROM Nearby n
        INNER JOIN profiles p ON p.account_id = n.account_id
        INNER JOIN accounts a ON a.account_id = n.account_id
        LEFT JOIN roles ar ON ar.id = a.role_id
        ORDER BY n.`rank`";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['account_id' => $accountId]);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return empty($res)
            ? $this->leaderboardTop($limit, $gameVersion)
            : $res;
    }
}
