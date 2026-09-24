<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdLegacyText;
use PDO;

final class CommentService
{
    public function __construct(
        private readonly CommentRepository $repository,
        private readonly AccountAuthenticator $auth,
        private readonly GdCommentEncoder $encoder,
        private readonly ?PDO $pdo = null
    ) {}

    private function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $db = new \MuchoCore\Database\Database();
        return $db->connection();
    }

    public function uploadLevelComment(
        int $levelId,
        int $accountId,
        string $gjp,
        string $content,
        int $percent,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): string
    {
        $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );
        $decodedContent = GdLegacyText::decodeComment(
            $content,
            $gameVersion
        );
        $decodedContent = trim($decodedContent);

        /*
         * Any text beginning with "!" is a command attempt.
         * Commands are never persisted as ordinary comments.
         */
        if (str_starts_with($decodedContent, "!")) {
            $commandName = strtolower(
                (string)(preg_split("/\\s+/", $decodedContent)[0] ?? "")
            );

            if ($commandName === "!help") {
                return $this->commandResponse(
                    $gameVersion,
                    true,
                    $this->commandHelp()
                );
            }

            $handled = $this->handleCommand(
                $levelId,
                $accountId,
                $decodedContent
            );

            return $this->commandResponse(
                $gameVersion,
                $handled === true
            );
        }

        $percent = max(0, min(100, $percent));

        $this->repository->addLevelComment(
            $levelId,
            $accountId,
            $decodedContent,
            $percent
        );

        /*
         * Legacy GD also records the progress attached to a comment as a
         * level score when percent is non-zero.
         */
        if ($percent > 0 && $this->pdo !== null) {
            $this->recordCommentProgress(
                $accountId,
                $levelId,
                $percent
            );
        }

        return 1;
    }

    private function recordCommentProgress(
        int $accountId,
        int $levelId,
        int $percent
    ): void {
        $q = $this->pdo->prepare(
            'SELECT score_id, percent
             FROM mucho_level_scores
             WHERE account_id = :account_id
               AND level_id = :level_id
               AND is_daily = 0
             LIMIT 1'
        );
        $q->execute([
            'account_id' => $accountId,
            'level_id' => $levelId,
        ]);

        $existing = $q->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $insert = $this->pdo->prepare(
                'INSERT INTO mucho_level_scores
                 (account_id, level_id, is_daily, daily_id, percent,
                  coins, attempts, clicks, play_time, progresses,
                  created_at, updated_at)
                 VALUES
                 (:account_id, :level_id, 0, 0, :percent,
                  0, 0, 0, 0, \'\',
                  :created_at, :updated_at)'
            );
            $now = time();
            $insert->execute([
                'account_id' => $accountId,
                'level_id' => $levelId,
                'percent' => $percent,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        if ($percent <= (int)$existing['percent']) {
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE mucho_level_scores
             SET percent = :percent,
                 updated_at = :updated_at
             WHERE score_id = :score_id'
        );
        $update->execute([
            'percent' => $percent,
            'updated_at' => time(),
            'score_id' => (int)$existing['score_id'],
        ]);
    }

    private function commandResponse(
        int $gameVersion,
        bool $success,
        string $message = ""
    ): string {
        /*
         * Geometry Dash 2.1+ understands temporary server messages
         * in the "temp_<seconds>_<message>" response form.
         *
         * GD 1.9/2.0 use strict numeric comment-upload responses, so
         * preserve their wire compatibility: 1 = accepted, -1 = failed.
         */
        if ($gameVersion >= 21) {
            if ($message === "") {
                $message = $success
                    ? "Command executed successfully!"
                    : "Command failed!";
            }

            return "temp_0_" . $message;
        }

        return $success ? "1" : "-1";
    }

    private function commandHelp(): string {
        return implode(" | ", [
            "MuchoCore Commands",
            "!help",
            "!rate <difficulty> <stars> [coins] [featured]",
            "!r <difficulty> <stars> [coins] [featured]",
            "!demon <1-5>",
            "!delete",
            "!cp <amount>",
        ]);
    }

    private function handleCommand(int $levelId, int $accountId, string $commandStr): ?bool
    {
        $pdo = $this->getPdo();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(r.code, 'user') AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id
               AND a.is_active = 1
               AND a.is_banned = 0
             LIMIT 1"
        );
        $stmt->execute([":id" => $accountId]);
        $role = strtolower((string)($stmt->fetchColumn() ?: "user"));

        /*
         * !help is intentionally handled before this permission gate so
         * every registered player can request command documentation.
         */
        if (!in_array(
            $role,
            ["owner", "elder_moderator", "moderator"],
            true
        )) {
            return null;
        }

        $parts = preg_split("/\\s+/", trim($commandStr)) ?: [];
        $cmd = strtolower($parts[0] ?? "");

        if ($cmd === "!r") {
            $cmd = "!rate";
        }

        switch ($cmd) {
            case "!rate":
                /*
                 * Legacy Cvolton/GD 1.9 syntax:
                 *   !rate <difficulty> <stars> [coins] [featured]
                 *
                 * Examples:
                 *   !rate easy 2
                 *   !rate hard 5 1
                 *   !rate demon 10
                 */
                $difficultyName = strtolower($parts[1] ?? "");
                $stars = isset($parts[2]) ? (int)$parts[2] : 0;
                $coins = isset($parts[3]) ? (int)$parts[3] : null;
                $featured = isset($parts[4]) ? (int)$parts[4] : null;

                if ($stars < 0 || $stars > 10) {
                    return null;
                }

                $difficultyMap = [
                    "na" => [0, 0, 0],
                    "none" => [0, 0, 0],
                    "auto" => [50, 1, 0],
                    "easy" => [10, 0, 0],
                    "normal" => [20, 0, 0],
                    "hard" => [30, 0, 0],
                    "harder" => [40, 0, 0],
                    "insane" => [50, 0, 0],
                    "demon" => [50, 0, 1],
                ];

                if (!isset($difficultyMap[$difficultyName])) {
                    return null;
                }

                [
                    $difficulty,
                    $auto,
                    $demon
                ] = $difficultyMap[$difficultyName];

                if ($difficultyName === "demon" && $stars === 0) {
                    $stars = 10;
                }

                if ($difficultyName === "auto" && $stars === 0) {
                    $stars = 1;
                }

                if ($coins !== null && ($coins < 0 || $coins > 3)) {
                    return null;
                }

                if ($featured !== null && ($featured < 0 || $featured > 4)) {
                    return null;
                }

                $updateSql = "
                    UPDATE levels
                    SET stars = :stars,
                        difficulty = :difficulty,
                        demon = :demon,
                        demon_difficulty = CASE
                            WHEN :demon = 1 AND demon_difficulty BETWEEN 1 AND 5
                            THEN demon_difficulty
                            ELSE 0
                        END,
                        auto_level = :auto,
                        updated_at = NOW()";

                $params = [
                    ":stars" => $stars,
                    ":difficulty" => $difficulty,
                    ":demon" => $demon,
                    ":auto" => $auto,
                    ":id" => $levelId,
                ];

                if ($coins !== null) {
                    $updateSql .= ", coins_verified = :coins_verified";
                    $params[":coins_verified"] = $coins > 0 ? 1 : 0;
                }

                if ($featured !== null) {
                    $updateSql .= ",
                        featured = CASE WHEN :featured > 0 THEN 1 ELSE 0 END,
                        epic = CASE
                            WHEN :featured = 2 THEN 1
                            WHEN :featured = 3 THEN 2
                            WHEN :featured = 4 THEN 3
                            ELSE 0
                        END";
                    $params[":featured"] = $featured;
                }

                $updateSql .= "
                    WHERE level_id = :id
                      AND is_deleted = 0";

                $update = $pdo->prepare($updateSql);
                $update->execute($params);

                $check = $pdo->prepare(
                    "SELECT stars, difficulty, demon, auto_level,
                            coins_verified, featured, epic
                     FROM levels
                     WHERE level_id = :id
                       AND is_deleted = 0
                     LIMIT 1"
                );
                $check->execute([":id" => $levelId]);
                $state = $check->fetch(PDO::FETCH_ASSOC);

                if (!$state) {
                    return null;
                }

                if (
                    (int)$state["stars"] !== $stars ||
                    (int)$state["difficulty"] !== $difficulty ||
                    (int)$state["demon"] !== $demon ||
                    (int)$state["auto_level"] !== $auto
                ) {
                    return null;
                }

                if (
                    $coins !== null &&
                    (int)$state["coins_verified"] !== ($coins > 0 ? 1 : 0)
                ) {
                    return null;
                }

                if (
                    $featured !== null &&
                    (int)$state["featured"] !== ($featured > 0 ? 1 : 0)
                ) {
                    return null;
                }

                $this->recalculateCreatorPoints($pdo, $levelId);

                return true;

            case "!demon":
                $demonDifficulty = isset($parts[1])
                    ? (int)$parts[1]
                    : 3;

                if ($demonDifficulty < 1 || $demonDifficulty > 5) {
                    return null;
                }

                $update = $pdo->prepare(
                    "UPDATE levels
                     SET stars = 10,
                         difficulty = 6,
                         demon = 1,
                         demon_difficulty = :demon_difficulty,
                         auto_level = 0,
                         updated_at = NOW()
                     WHERE level_id = :id
                       AND is_deleted = 0"
                );
                $update->execute([
                    ":demon_difficulty" => $demonDifficulty,
                    ":id" => $levelId,
                ]);

                if ($update->rowCount() === 0) {
                    return null;
                }

                $this->recalculateCreatorPoints($pdo, $levelId);

                return true;

            case "!delete":
                $update = $pdo->prepare(
                    "UPDATE levels
                     SET is_deleted = 1,
                         updated_at = NOW()
                     WHERE level_id = :id
                       AND is_deleted = 0"
                );
                $update->execute([":id" => $levelId]);

                return $update->rowCount() === 1;

            case "!cp":
                $amount = isset($parts[1])
                    ? (int)$parts[1]
                    : 1;

                if ($amount < 1 || $amount > 100) {
                    return null;
                }

                $lvlStmt = $pdo->prepare(
                    "SELECT account_id
                     FROM levels
                     WHERE level_id = :id
                       AND is_deleted = 0
                     LIMIT 1"
                );
                $lvlStmt->execute([":id" => $levelId]);

                $authorId = (int)($lvlStmt->fetchColumn() ?: 0);

                if ($authorId <= 0) {
                    return null;
                }

                $pdo->prepare(
                    "UPDATE profiles
                     SET creator_points = creator_points + :cp
                     WHERE account_id = :acc"
                )->execute([
                    ":cp" => $amount,
                    ":acc" => $authorId,
                ]);

                return true;
        }

        return null;
    }

    private function recalculateCreatorPoints(
        PDO $pdo,
        int $levelId
    ): void {
        $author = $pdo->prepare(
            "SELECT account_id
             FROM levels
             WHERE level_id = :id
             LIMIT 1"
        );
        $author->execute([":id" => $levelId]);

        $accountId = (int)($author->fetchColumn() ?: 0);

        if ($accountId <= 0) {
            return;
        }

        $cp = $pdo->prepare(
            "SELECT COALESCE(
                SUM(
                    CASE WHEN stars > 0 THEN 1 ELSE 0 END
                    + CASE WHEN featured > 0 THEN 1 ELSE 0 END
                    + epic
                ),
                0
             )
             FROM levels
             WHERE account_id = :account
               AND is_deleted = 0"
        );
        $cp->execute([":account" => $accountId]);

        $update = $pdo->prepare(
            "UPDATE profiles
             SET creator_points = :cp
             WHERE account_id = :account"
        );
        $update->execute([
            ":cp" => (int)$cp->fetchColumn(),
            ":account" => $accountId,
        ]);
    }

    public function getLevelComments(int $levelId, int $page, int $gameVersion = 22, int $binaryVersion = 0): string
    {
        $limit = 10;
        $offset = max(0, $page) * $limit;
        $comments = $this->repository->getLevelComments($levelId, $page, $limit);
        $total = $this->repository->countLevelComments($levelId);

        if ($total === 0 || empty($comments)) {
            return "-2";
        }

        $encodedComments = [];
        foreach ($comments as $comment) {
            $role = strtolower((string)($comment["role"] ?? "user"));
            $badge = match ($role) {
                "owner", "admin" => 2,
                "elder_moderator" => 2,
                "moderator" => 1,
                default => 0
            };

            $profile = [
                "username" => $comment["username"] ?? "Unknown",
                "user_id"  => $comment["user_id"] ?? $comment["account_id"] ?? 0,
                "cube"     => $comment["cube"] ?? 1,
                "color1"   => $comment["color1"] ?? 0,
                "color2"   => $comment["color2"] ?? 3,
                "special"  => $comment["special"] ?? 0,
                "icon_type"=> $comment["icon_type"] ?? 0,
                "badge"    => $badge
            ];
            $encodedComments[] = $this->encoder->encode(
                $comment,
                $profile,
                $gameVersion,
                $binaryVersion
            );
        }

        $body = implode("|", $encodedComments);

        if ($binaryVersion < 32) {
            $users = [];
            $seen = [];

            foreach ($comments as $comment) {
                $uid = (int)($comment['user_id'] ?? $comment['account_id'] ?? 0);
                $account = (int)($comment['account_id'] ?? 0);
                $key = $uid . ':' . $account;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $users[] = $uid . ':' .
                    \MuchoCore\Protocol\ProtocolText::username(
                        $comment['username'] ?? 'Player'
                    ) . ':' . $account;
            }

            $body .= '#' . implode('|', $users);
        }

        return $body
            . '#' . $total . ':' . $offset . ':' . count($comments);
    }

    public function getUserComments(
        int $userId,
        int $page,
        int $count,
        int $mode,
        int $gameVersion,
        int $binaryVersion
    ): string {
        if ($userId <= 0) {
            return '-1';
        }

        $limit = min(100, max(1, $count));
        $page = min(1000, max(0, $page));
        $offset = $page * $limit;
        $orderByLikes = $mode !== 0;

        $comments = $this->repository->getUserComments(
            $userId,
            $page,
            $limit,
            $orderByLikes
        );
        $total = $this->repository->countUserComments($userId);

        if ($total === 0 || $comments === []) {
            return '-2';
        }

        $encoded = [];
        $users = [];
        $seenUsers = [];

        foreach ($comments as $comment) {
            $role = strtolower((string)($comment['role'] ?? 'user'));
            $badge = match ($role) {
                'owner', 'admin', 'elder_moderator' => 2,
                'moderator' => 1,
                default => 0,
            };

            $profile = [
                'username' => $comment['username'] ?? 'Player',
                'user_id' => $comment['user_id'] ?? 0,
                'cube' => $comment['cube'] ?? 1,
                'color1' => $comment['color1'] ?? 0,
                'color2' => $comment['color2'] ?? 3,
                'special' => $comment['special'] ?? 0,
                'icon_type' => $comment['icon_type'] ?? 0,
                'badge' => $badge,
                'account_id' => $comment['account_id'] ?? 0,
            ];

            $encodedComment = $this->encoder->encode(
                $comment,
                $profile,
                $gameVersion,
                $binaryVersion
            );

            $encoded[] =
                '1~' . (int)$comment['commented_level_id'] .
                '~' . $encodedComment;

            if ($binaryVersion < 32) {
                $uid = (int)($comment['user_id'] ?? 0);
                $accountId = (int)($comment['account_id'] ?? 0);
                $key = $uid . ':' . $accountId;

                if ($uid > 0 && !isset($seenUsers[$key])) {
                    $seenUsers[$key] = true;
                    $users[] =
                        $uid . ':' .
                        \MuchoCore\Protocol\ProtocolText::username(
                            $comment['username'] ?? 'Player'
                        ) .
                        ':' . $accountId;
                }
            }
        }

        $body = implode('|', $encoded);

        if ($binaryVersion < 32) {
            $body .= '#' . implode('|', $users);
        }

        return $body .
            '#' . $total . ':' . $offset . ':' . count($comments);
    }

    public function uploadAccountComment(
        int $accountId,
        string $gjp,
        string $content,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): int
    {
        $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );
        $decodedContent = GdLegacyText::decodeComment(
            $content,
            $gameVersion
        );

        $this->repository->addAccountComment(
            $accountId,
            $decodedContent
        );

        return 1;
    }

    public function getAccountComments(int $accountId, int $page, int $gameVersion = 22): string
    {
        $limit = 10;
        $offset = max(0, $page) * $limit;
        $comments = $this->repository->getAccountComments($accountId, $page, $limit);
        $total = $this->repository->countAccountComments($accountId);

        if ($total === 0 || empty($comments)) {
            return '#0:0:0';
        }

        $encoded = [];
        foreach ($comments as $comment) {
            $encoded[] = $this->encoder->encodeAccountComment(
                $comment,
                $gameVersion
            );
        }

        return implode("|", $encoded) . "#" . $total . ":" . $offset . ":" . count($comments);
    }

    public function deleteComment(
        int $commentId,
        int $accountId,
        string $gjp,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): bool {
        $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );

        return $this->repository->deleteLevelComment(
            $commentId,
            $accountId
        );
    }

    public function deleteAccountComment(
        int $commentId,
        int $accountId,
        string $gjp,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): bool {
        $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );

        return $this->repository->deleteAccountComment(
            $commentId,
            $accountId
        );
    }

    private function authenticateCommenter(
        int $accountId,
        string $gjp,
        int $gameVersion,
        string $udid,
        string $ip
    ): void {
        if ($gjp !== '') {
            $this->auth->authenticate($accountId, $gjp);
            return;
        }

        if (
            $gameVersion === 19 &&
            trim($udid) !== '' &&
            trim($ip) !== ''
        ) {
            $this->auth->authenticateLegacy19Upload(
                $accountId,
                $udid,
                $ip
            );
            return;
        }

        throw new RuntimeException('Unauthorized.');
    }
}
