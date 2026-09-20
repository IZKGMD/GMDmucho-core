<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdCommentEncoder;
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
        int $gameVersion = 0
    ): int {
        $this->auth->authenticate($accountId, $gjp);

        $decodedContent = $this->decodeLevelComment(
            $content,
            $gameVersion
        );
        $decodedContent = trim($decodedContent);

        // Проверяем, является ли комментарий модераторской командой
        if (str_starts_with($decodedContent, "!")) {
            $commandResult = $this->handleCommand(
                $levelId,
                $accountId,
                $decodedContent
            );

            if ($commandResult !== null) {
                $decodedContent = $commandResult;
            }
        }

        /*
         * Сохраняем протокольное значение:
         * GD < 2.0 присылает Base64, GD 2.0+ — обычный текст.
         * Для неизвестной версии выбираем legacy-поведение.
         */
        $storedContent = $content;

        return $this->repository->addLevelComment(
            $levelId,
            $accountId,
            $storedContent,
            $percent
        );
    }

    private function handleCommand(int $levelId, int $accountId, string $commandStr): ?string
    {
        $pdo = $this->getPdo();

        // 1. Проверяем права пользователя (owner, admin, mod, elder)
        $stmt = $pdo->prepare(
            "SELECT r.code AS role, a.username
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id
             LIMIT 1"
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

        // 2. Обработка команд
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

                    // Завершаем выполнение и возвращаем "1" (код успешной отправки коммента в GD).
                    // Это предотвращает сохранение команды в базу данных и блокирует любые системные сообщения.
                    exit("1");
                }
                
                return "[Mod] Rate error: invalid difficulty (use auto, easy, normal, hard, harder, insane, demon)";

            case "!demon":
                $demonDiff = isset($parts[1]) ? (int)$parts[1] : 3;
                $pdo->prepare("UPDATE levels SET demon = 1, stars = 10, demon_difficulty = :d WHERE level_id = :id")
                    ->execute([":d" => $demonDiff, ":id" => $levelId]);

                $names = [1 => "Easy", 2 => "Medium", 3 => "Hard", 4 => "Insane", 5 => "Extreme"];
                return sprintf("[Mod] Set Demon Difficulty: %s", $names[$demonDiff] ?? "Hard");

            case "!delete":
                $pdo->prepare("UPDATE levels SET is_deleted = 1 WHERE level_id = :id")->execute([":id" => $levelId]);
                return "[Mod] Level has been deleted";

            case "!cp":
                $amount = isset($parts[1]) ? (int)$parts[1] : 1;
                $lvlStmt = $pdo->prepare("SELECT account_id FROM levels WHERE level_id = :id");
                $lvlStmt->execute([":id" => $levelId]);
                $authorId = (int)($lvlStmt->fetchColumn() ?: 0);
                if ($authorId > 0) {
                    $pdo->prepare("UPDATE profiles SET creator_points = creator_points + :cp WHERE account_id = :acc")
                        ->execute([":cp" => $amount, ":acc" => $authorId]);
                    return sprintf("[Mod] Awarded +%d CP to creator", $amount);
                }
                return null;
        }

        return null;
    }

    public function getLevelComments(
        int $levelId,
        int $page,
        int $gameVersion = 0,
        int $limit = 10
    ): string {
        $limit = min(100, max(1, $limit));

        $comments = $this->repository->getLevelComments(
            $levelId,
            $page,
            $limit
        );

        $total = $this->repository->countLevelComments($levelId);
        $offset = max(0, $page) * $limit;

        if (empty($comments)) {
            return '#' . $total . ':' . $offset . ':0';
        }

        $encodedComments = [];
        foreach ($comments as $comment) {
            $role = strtolower((string)($comment["role"] ?? "user"));
            $badge = match ($role) {
                "owner", "developer", "creator", "admin", "elder" => 2,
                "mod", "moderator", "helper"                     => 1,
                default                                          => 0
            };

            $profile = [
                "username" => $comment["username"] ?? "Unknown",
                "cube"     => $comment["cube"] ?? 1,
                "color1"   => $comment["color1"] ?? 0,
                "color2"   => $comment["color2"] ?? 3,
                "special"  => $comment["special"] ?? 0,
                "badge"    => $badge
            ];
            $encodedComments[] = $this->encoder->encode(
                $comment,
                $profile,
                $gameVersion
            );
        }

        return implode("|", $encodedComments)
            . '#' . $total . ':' . $offset . ':' . count($comments);
    }

    public function uploadAccountComment(int $accountId, string $gjp, string $content): int
    {
        $this->auth->authenticate($accountId, $gjp);

        /*
         * Account-wall comments are stored/emitted as the raw protocol text.
         */
        return $this->repository->addAccountComment(
            $accountId,
            $content
        );
    }

    private function decodeLevelComment(
        string $content,
        int $gameVersion
    ): string {
        // Unknown version follows Cvolton's legacy default (gameVersion=0).
        if ($gameVersion >= 20) {
            return $content;
        }

        $normalized = strtr($content, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? $content : $decoded;
    }

    public function getAccountComments(int $accountId, int $page): string
    {
        $limit = 10;
        $comments = $this->repository->getAccountComments(
            $accountId,
            $page,
            $limit
        );

        $total = $this->repository->countAccountComments($accountId);
        $offset = max(0, $page) * $limit;

        if (empty($comments)) {
            return '#' . $total . ':' . $offset . ':0';
        }

        $encoded = [];
        foreach ($comments as $comment) {
            $encoded[] = $this->encoder->encodeAccountComment($comment);
        }

        return implode("|", $encoded)
            . '#' . $total . ':' . $offset . ':' . count($comments);
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
