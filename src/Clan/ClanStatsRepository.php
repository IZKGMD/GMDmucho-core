<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use PDO;

final readonly class ClanStatsRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function stats(int $clanId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT
                c.clan_id,
                c.name,
                c.tag,
                c.max_members,
                c.is_open,
                COUNT(m.account_id) AS member_count,
                COALESCE(SUM(COALESCE(p.stars,0)),0) AS total_stars,
                COALESCE(SUM(COALESCE(p.moons,0)),0) AS total_moons,
                COALESCE(SUM(COALESCE(p.demons,0)),0) AS total_demons,
                COALESCE(SUM(COALESCE(p.diamonds,0)),0) AS total_diamonds,
                COALESCE(SUM(COALESCE(p.secret_coins,0)),0) AS total_secret_coins,
                COALESCE(SUM(COALESCE(p.user_coins,0)),0) AS total_user_coins,
                COALESCE(SUM(COALESCE(p.creator_points,0)),0) AS total_creator_points,
                COALESCE(SUM(
                    (SELECT COUNT(*)
                     FROM levels l
                     WHERE l.account_id=m.account_id
                       AND l.is_deleted=0)
                ),0) AS total_levels
             FROM mucho_clans c
             INNER JOIN mucho_clan_members m
                ON m.clan_id=c.clan_id
             LEFT JOIN profiles p
                ON p.account_id=m.account_id
             WHERE c.clan_id=:clan_id
             GROUP BY c.clan_id, c.name, c.tag, c.max_members, c.is_open
             LIMIT 1'
        );
        $stmt->execute(['clan_id'=>$clanId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'clan_id'=>$clanId,
            'member_count'=>0,
            'total_stars'=>0,
            'total_moons'=>0,
            'total_demons'=>0,
            'total_diamonds'=>0,
            'total_secret_coins'=>0,
            'total_user_coins'=>0,
            'total_creator_points'=>0,
            'total_levels'=>0,
        ];
    }

    public function topClans(string $metric='stars', int $limit=25): array
    {
        $allowed=[
            'stars'=>'total_stars',
            'moons'=>'total_moons',
            'demons'=>'total_demons',
            'diamonds'=>'total_diamonds',
            'secret_coins'=>'total_secret_coins',
            'user_coins'=>'total_user_coins',
            'creator_points'=>'total_creator_points',
            'levels'=>'total_levels',
            'members'=>'member_count',
        ];

        $orderAlias=$allowed[$metric] ?? $allowed['stars'];
        $limit=max(1,min(100,$limit));

        $sql='SELECT
                c.clan_id,
                c.name,
                c.tag,
                c.is_open,
                COUNT(m.account_id) AS member_count,
                COALESCE(SUM(COALESCE(p.stars,0)),0) AS total_stars,
                COALESCE(SUM(COALESCE(p.moons,0)),0) AS total_moons,
                COALESCE(SUM(COALESCE(p.demons,0)),0) AS total_demons,
                COALESCE(SUM(COALESCE(p.diamonds,0)),0) AS total_diamonds,
                COALESCE(SUM(COALESCE(p.secret_coins,0)),0) AS total_secret_coins,
                COALESCE(SUM(COALESCE(p.user_coins,0)),0) AS total_user_coins,
                COALESCE(SUM(COALESCE(p.creator_points,0)),0) AS total_creator_points,
                COALESCE(SUM(
                    (SELECT COUNT(*)
                     FROM levels l
                     WHERE l.account_id=m.account_id
                       AND l.is_deleted=0)
                ),0) AS total_levels
             FROM mucho_clans c
             INNER JOIN mucho_clan_members m
                ON m.clan_id=c.clan_id
             LEFT JOIN profiles p
                ON p.account_id=m.account_id
             GROUP BY c.clan_id, c.name, c.tag, c.is_open
             ORDER BY '.$orderAlias.' DESC, c.clan_id ASC
             LIMIT '.$limit;

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }


}
