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
        $sql = '
            SELECT
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.diamonds, 0) AS diamonds,
                COALESCE(p.secret_coins, 0) AS secret_coins,
                COALESCE(p.user_coins, 0) AS user_coins,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.cube, 1) AS cube,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.ship, 1) AS ship,
                COALESCE(p.ball, 1) AS ball,
                COALESCE(p.ufo, 1) AS ufo,
                COALESCE(p.wave, 1) AS wave,
                COALESCE(p.robot, 1) AS robot,
                COALESCE(p.spider, 1) AS spider,
                COALESCE(p.swing, 1) AS swing,
                COALESCE(p.jetpack, 1) AS jetpack,
                COALESCE(p.explosion, 1) AS explosion,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.color3, 0) AS color3,
                COALESCE(p.glow, 0) AS glow,
                COALESCE(p.special, 0) AS special,
                a.account_id,
                COALESCE(p.user_id, a.account_id) AS user_id,
                a.username,
                r.code AS role_code
            FROM accounts a
            LEFT JOIN roles r
                ON r.id = a.role_id
            LEFT JOIN profiles p
                ON p.account_id = a.account_id
            WHERE
                a.username LIKE :query
            ORDER BY COALESCE(p.stars, 0) DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['query' => '%' . $query . '%']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    private function ensureProfileForAccount(
        int $accountId
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM profiles
             WHERE account_id = :account_id
             LIMIT 1'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return;
        }

        /*
         * updateGJUserScore и getGJUserInfo20 могут прийти одновременно.
         * Создаём профиль атомарно, чтобы первый запрос не получил -1.
         */
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO profiles (account_id)
             SELECT account_id
             FROM accounts
             WHERE account_id = :account_id'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);
    }

    public function getProfileByTarget(int $targetAccountId): ?array
    {
        $this->ensureProfileForAccount($targetAccountId);

        $sql = '
            SELECT
                a.account_id,
                COALESCE(p.user_id, a.account_id) AS user_id,
                a.username,
                r.code AS role_code,
                COALESCE(a.messages_state, 0) AS message_state,
                COALESCE(a.friend_requests_state, 0) AS friend_request_state,
                COALESCE(a.comments_state, 0) AS comment_history_state,
                COALESCE(a.youtube_url, "") AS youtube,
                COALESCE(a.twitter, "") AS twitter,
                COALESCE(a.twitch, "") AS twitch,
                COALESCE(a.discord, "") AS discord,
                COALESCE(a.instagram, "") AS instagram,
                COALESCE(a.tiktok, "") AS tiktok,
                COALESCE(a.custom_link, "") AS custom_link,
                a.created_at AS registered_at,
                COALESCE(p.demon_info, "") AS demon_info,
                COALESCE(p.star_info, "") AS star_info,
                COALESCE(p.platformer_info, "") AS platformer_info,
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.diamonds, 0) AS diamonds,
                COALESCE(p.secret_coins, 0) AS secret_coins,
                COALESCE(p.user_coins, 0) AS user_coins,
                COALESCE(p.creator_points, 0) AS creator_points,
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
                COALESCE(p.glow, 0) AS glow,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.explosion, 1) AS explosion,
                COALESCE(p.special, 0) AS special,
                COALESCE((SELECT COUNT(*) FROM levels WHERE account_id = a.account_id AND is_deleted = 0), 0) AS levels_count
            FROM accounts a
            LEFT JOIN profiles p
                ON p.account_id = a.account_id
            WHERE a.account_id = :account_id
            LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['account_id' => $targetAccountId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function leaderboardTop(int $limit = 1000): array
    {
        $sql = '
            SELECT
                p.*,
                a.username,
                r.code AS role_code,
                ROW_NUMBER() OVER (ORDER BY p.stars DESC, p.account_id ASC) AS `rank`
            FROM profiles p
            INNER JOIN accounts a
                ON a.account_id = p.account_id
            INNER JOIN roles r
                ON r.id = a.role_id
            WHERE p.stars > 0
              AND a.is_banned = 0
            ORDER BY `rank`
            LIMIT ' . (int)$limit;

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaderboardCreators(int $limit = 1000): array
    {
        $sql = '
            SELECT
                p.*,
                a.username,
                r.code AS role_code,
                ROW_NUMBER() OVER (ORDER BY p.creator_points DESC, p.account_id ASC) AS `rank`
            FROM profiles p
            INNER JOIN accounts a
                ON a.account_id = p.account_id
            INNER JOIN roles r
                ON r.id = a.role_id
            WHERE p.creator_points > 0
              AND a.is_banned = 0
            ORDER BY `rank`
            LIMIT ' . (int)$limit;

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaderboardRelative(int $accountId, int $limit = 50): array
    {
        $sql = "WITH RankedProfiles AS (
            SELECT p.*, a.username, r.code AS role_code,
                   ROW_NUMBER() OVER (ORDER BY p.stars DESC, p.account_id ASC) AS `rank`
            FROM profiles p
            INNER JOIN accounts a ON a.account_id = p.account_id
            INNER JOIN roles r ON r.id = a.role_id
            WHERE a.is_banned = 0
        ),
        TargetRank AS (
            SELECT `rank` FROM RankedProfiles WHERE account_id = :account_id
        )
        SELECT r.* FROM RankedProfiles r
        JOIN TargetRank t ON 1=1
        WHERE r.`rank` BETWEEN t.`rank` - 25 AND t.`rank` + 25
        LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['account_id' => $accountId]);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return empty($res) ? $this->leaderboardTop($limit) : $res;
    }
}
