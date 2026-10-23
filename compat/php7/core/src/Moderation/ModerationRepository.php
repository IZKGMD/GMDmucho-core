<?php

declare (strict_types=1);
/*
 * MuchoCore v7.6 Moderation
 * Copyright (C) 2026 IZK
 */
namespace MuchoCore\Moderation;

use PDO;
final class ModerationRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function getAccountRole(int $accountId): string
    {
        $q = $this->pdo->prepare('SELECT COALESCE(r.code, \'user\') AS role
             FROM accounts a
             LEFT JOIN roles r
               ON r.id=a.role_id
             WHERE a.account_id=:id
               AND is_active=1
               AND is_banned=0
             LIMIT 1');
        $q->execute(['id' => $accountId]);
        return strtolower((string) ($q->fetchColumn() ?: 'user'));
    }
    public function levelExists(int $levelId): bool
    {
        $q = $this->pdo->prepare('SELECT 1
             FROM levels
             WHERE level_id=:id
               AND is_deleted=0
             LIMIT 1');
        $q->execute(['id' => $levelId]);
        return (bool) $q->fetchColumn();
    }
    public function recordSuggestion(int $levelId, int $accountId, int $stars, int $featureTier, int $coinsVerified)
    {
        $q = $this->pdo->prepare('INSERT INTO moderation_suggestions
             (
                level_id,
                account_id,
                stars,
                feature_tier,
                coins_verified
             )
             VALUES
             (
                :level,
                :account,
                :stars,
                :feature,
                :coins
             )');
        $q->execute(['level' => $levelId, 'account' => $accountId, 'stars' => $stars, 'feature' => $featureTier, 'coins' => $coinsVerified]);
    }
    public function applyFullRate(int $levelId, int $stars, int $featureTier, int $coinsVerified)
    {
        $author = $this->author($levelId);
        if ($author === null) {
            return null;
        }
        list($difficulty, $auto, $demon) = $this->starState($stars);
        $featured = $featureTier >= 1 ? 1 : 0;
        $epic = (function () use (&$featureTier) {
            $__mcMatch1 = $featureTier;
            if ($__mcMatch1 === 2) {
                return 1;
            }
            if ($__mcMatch1 === 3) {
                return 2;
            }
            if ($__mcMatch1 === 4) {
                return 3;
            }
            return 0;
        })();
        $q = $this->pdo->prepare('UPDATE levels
             SET
                stars=:stars,
                difficulty=:difficulty,
                auto_level=:auto,
                demon=:demon,
                featured=:featured,
                epic=:epic,
                coins_verified=:coins
             WHERE level_id=:id
               AND is_deleted=0');
        $q->execute(['stars' => $stars, 'difficulty' => $difficulty, 'auto' => $auto, 'demon' => $demon, 'featured' => $featured, 'epic' => $epic, 'coins' => $coinsVerified, 'id' => $levelId]);
        $this->recalculateCreatorPoints($author);
        return $author;
    }
    public function applyStarRate(int $levelId, int $stars)
    {
        $author = $this->author($levelId);
        if ($author === null) {
            return null;
        }
        list($difficulty, $auto, $demon) = $this->starState($stars);
        $q = $this->pdo->prepare('UPDATE levels
             SET
                stars=:stars,
                difficulty=:difficulty,
                auto_level=:auto,
                demon=:demon,
                demon_difficulty=0,
                custom_difficulty=0
             WHERE level_id=:id
               AND is_deleted=0');
        $q->execute(['stars' => $stars, 'difficulty' => $difficulty, 'auto' => $auto, 'demon' => $demon, 'id' => $levelId]);
        $this->recalculateCreatorPoints($author);
        return $author;
    }
    public function applyDemonDifficulty(int $levelId, int $rating): bool
    {
        $difficulty = (function () use (&$rating) {
            $__mcMatch2 = $rating;
            if ($__mcMatch2 === 1) {
                return 3;
            }
            if ($__mcMatch2 === 2) {
                return 4;
            }
            if ($__mcMatch2 === 3) {
                return 0;
            }
            if ($__mcMatch2 === 4) {
                return 5;
            }
            if ($__mcMatch2 === 5) {
                return 6;
            }
            if ($__mcMatch2 === 6) {
                return 8;
            }
            if ($__mcMatch2 === 7) {
                return 9;
            }
            if ($__mcMatch2 === 8) {
                return 10;
            }
            return -1;
        })();
        if ($difficulty < 0) {
            return false;
        }
        $customDifficulty = (function () use (&$rating) {
            $__mcMatch3 = $rating;
            if ($__mcMatch3 === 6) {
                return 1;
            }
            if ($__mcMatch3 === 7) {
                return 2;
            }
            if ($__mcMatch3 === 8) {
                return 3;
            }
            return 0;
        })();
        $stars = (function () use (&$customDifficulty) {
            $__mcMatch4 = $customDifficulty;
            if ($__mcMatch4 === 1) {
                return 15;
            }
            if ($__mcMatch4 === 2) {
                return 30;
            }
            if ($__mcMatch4 === 3) {
                return 50;
            }
            return 10;
        })();
        $q = $this->pdo->prepare('UPDATE levels
             SET
                stars=:stars,
                demon=1,
                difficulty=50,
                auto_level=0,
                demon_difficulty=:difficulty,
                custom_difficulty=:custom_difficulty
             WHERE level_id=:id
               AND is_deleted=0');
        $q->execute(['stars' => $stars, 'difficulty' => $difficulty, 'custom_difficulty' => $customDifficulty, 'id' => $levelId]);
        return $q->rowCount() === 1 || $this->levelExists($levelId);
    }
    public function reportLevel(int $levelId, string $reporterHash): int
    {
        if (!$this->levelExists($levelId)) {
            return 0;
        }
        $q = $this->pdo->prepare('INSERT IGNORE INTO mucho_level_reports
             (level_id,reporter_hash)
             VALUES (:level,:reporter)');
        $q->execute(['level' => $levelId, 'reporter' => $reporterHash]);
        if ($q->rowCount() !== 1) {
            return 0;
        }
        return (int) $this->pdo->lastInsertId();
    }
    public function recalculateCreatorPoints(int $authorAccountId)
    {
        $q = $this->pdo->prepare('SELECT
                COALESCE(
                    SUM(
                        (CASE
                            WHEN stars>0 THEN 1
                            ELSE 0
                        END)
                        +
                        (CASE
                            WHEN featured>0 THEN 1
                            ELSE 0
                        END)
                        +
                        epic
                    ),
                    0
                )
             FROM levels
             WHERE account_id=:account
               AND is_deleted=0');
        $q->execute(['account' => $authorAccountId]);
        $cp = (int) $q->fetchColumn();
        $q = $this->pdo->prepare('UPDATE profiles
             SET creator_points=:cp
             WHERE account_id=:account');
        $q->execute(['cp' => $cp, 'account' => $authorAccountId]);
    }
    public function logAction(int $moderatorAccountId, int $levelId, string $action, array $details)
    {
        $q = $this->pdo->prepare('INSERT INTO moderation_logs
             (
                moderator_account_id,
                level_id,
                action,
                details
             )
             VALUES
             (
                :moderator,
                :level,
                :action,
                :details
             )');
        $q->execute(['moderator' => $moderatorAccountId, 'level' => $levelId, 'action' => $action, 'details' => \mucho7_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    private function author(int $levelId)
    {
        $q = $this->pdo->prepare('SELECT account_id
             FROM levels
             WHERE level_id=:id
               AND is_deleted=0
             LIMIT 1');
        $q->execute(['id' => $levelId]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int) $id;
    }
    private function starState(int $stars): array
    {
        return (function () use (&$stars) {
            $__mcMatch5 = $stars;
            if ($__mcMatch5 === 1) {
                return [50, 1, 0];
            }
            if ($__mcMatch5 === 2) {
                return [10, 0, 0];
            }
            if ($__mcMatch5 === 3) {
                return [20, 0, 0];
            }
            if ($__mcMatch5 === 4 || $__mcMatch5 === 5) {
                return [30, 0, 0];
            }
            if ($__mcMatch5 === 6 || $__mcMatch5 === 7) {
                return [40, 0, 0];
            }
            if ($__mcMatch5 === 8 || $__mcMatch5 === 9) {
                return [50, 0, 0];
            }
            if ($__mcMatch5 === 10) {
                return [50, 0, 1];
            }
            return [0, 0, 0];
        })();
    }
    private $pdo;
}
