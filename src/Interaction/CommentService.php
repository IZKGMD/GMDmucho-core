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

    public function uploadLevelComment(int $levelId, int $accountId, string $gjp, string $content, int $percent, int $gameVersion = 22): int
    {
        $this->auth->authenticate($accountId, $gjp);
        $decodedContent = GdLegacyText::decodeComment(
            $content,
            $gameVersion
        );
        $decodedContent = trim($decodedContent);

        // Check whether the comment is a moderation command.
        if (str_starts_with($decodedContent, "!")) {
            $handled = $this->handleCommand(
                $levelId,
                $accountId,
                $decodedContent
            );

            if ($handled === true) {
                return 1;
            }

            if ($handled === null) {
                // Unknown or invalid commands are treated as normal comment text.
            }
        }

        $this->repository->addLevelComment(
            $levelId,
            $accountId,
            $decodedContent,
            max(0, min(100, $percent))
        );

        return 1;
    }

    private function handleCommand(int $levelId, int $accountId, string $commandStr): ?bool
    {
        $pdo = $this->getPdo();

        // 1. Check the user's moderation role.
        $stmt = $pdo->prepare(
            "SELECT a.username, COALESCE(r.code, 'user') AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id"
        );
        $stmt->execute([":id" => $accountId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;
        $role = strtolower((string)($user["role"] ?? "user"));
        if (!in_array($role, ["owner", "admin", "moderator", "mod", "elder", "developer"])) {
            return null; // Не модератор — команда постится как обычный текст
        }

        $parts = preg_split("/\s+/", trim($commandStr));
        $cmd = strtolower($parts[0] ?? "");

        // 2. Process the command.
        switch ($cmd) {
            case "!rate":
                $val = strtolower($parts[1] ?? "");
                $diff = 0; $demon = 0; $demon_diff = 0; $auto = 0;

                switch($val) {
                    case 'auto': $diff = 1; $auto = 1; break;
                    case 'easy': $diff = 2; break;
                    case 'normal': $diff = 3; break;
                    case 'hard': $diff = 4; break;
                    case 'harder': $diff = 5; break;
                    case 'insane': $diff = 6; break;
                    case 'demon': $diff = 6; $demon = 1; $demon_diff = 3; break;
                    case 'easydemon': $diff = 6; $demon = 1; $demon_diff = 1; break;
                    case 'mediumdemon': $diff = 6; $demon = 1; $demon_diff = 2; break;
                    case 'harddemon': $diff = 6; $demon = 1; $demon_diff = 3; break;
                    case 'insanedemon': $diff = 6; $demon = 1; $demon_diff = 4; break;
                    case 'extremedemon': $diff = 6; $demon = 1; $demon_diff = 5; break;
                }

                if ($diff > 0) {
                    $pdo->prepare("
                        UPDATE levels SET
                            stars = 0,
                            difficulty = :diff,
                            demon = :demon,
                            demon_difficulty = :demon_diff,
                            auto_level = :auto,
                            featured = 0,
                            epic = 0,
                            updated_at = NOW()
                        WHERE level_id = :id
                    ")->execute([
                        ":diff"  => $diff,
                        ":demon" => $demon,
                        ":demon_diff" => $demon_diff,
                        ":auto"  => $auto,
                        ":id"    => $levelId
                    ]);

                    // Finish successfully; GD expects "1" for a handled comment command.
                    // Do not persist the command as a normal comment.
                    return true;
                }
                
                return "[Mod] Rate error: invalid difficulty (use auto, easy, normal, hard, harder, insane, demon)";

            case "!demon":
                $demonDiff = isset($parts[1]) ? (int)$parts[1] : 3;
                $pdo->prepare("UPDATE levels SET demon = 1, stars = 10, demon_difficulty = :d WHERE level_id = :id")
                    ->execute([":d" => $demonDiff, ":id" => $levelId]);

                $names = [1 => "Easy", 2 => "Medium", 3 => "Hard", 4 => "Insane", 5 => "Extreme"];
                return true;

            case "!delete":
                $pdo->prepare("UPDATE levels SET is_deleted = 1 WHERE level_id = :id")->execute([":id" => $levelId]);
                return true;

            case "!cp":
                $amount = isset($parts[1]) ? (int)$parts[1] : 1;
                $lvlStmt = $pdo->prepare("SELECT account_id FROM levels WHERE level_id = :id");
                $lvlStmt->execute([":id" => $levelId]);
                $authorId = (int)($lvlStmt->fetchColumn() ?: 0);
                if ($authorId > 0) {
                    $pdo->prepare("UPDATE profiles SET creator_points = creator_points + :cp WHERE account_id = :acc")
                        ->execute([":cp" => $amount, ":acc" => $authorId]);
                    return true;
                }
                return null;
        }

        return null;
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

    public function uploadAccountComment(int $accountId, string $gjp, string $content, int $gameVersion = 22): int
    {
        $this->auth->authenticate($accountId, $gjp);
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
            return "-2";
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

    public function deleteComment(int $commentId, int $accountId, string $gjp): bool
    {
        $this->auth->authenticate($accountId, $gjp);
        return $this->repository->deleteLevelComment($commentId, $accountId);
    }

    public function deleteAccountComment(int $commentId, int $accountId, string $gjp): bool
    {
        $this->auth->authenticate($accountId, $gjp);
        return $this->repository->deleteAccountComment($commentId, $accountId);
    }
}
