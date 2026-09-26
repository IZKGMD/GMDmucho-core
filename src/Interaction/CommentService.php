<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdLegacyText;
use PDO;
use MuchoCore\Interaction\CommentCommandService;

final class CommentService
{
    public function __construct(
        private readonly CommentRepository $repository,
        private readonly AccountAuthenticator $auth,
        private readonly GdCommentEncoder $encoder,
        private readonly ?PDO $pdo = null,
        private readonly ?Legacy10IdentityService $legacy10 = null,
        private readonly ?CommentCommandService $commands = null
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
        $accountId = $this->authenticateCommenter(
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
        $decodedContent = $this->normalizeCommentText($decodedContent);

        if ($decodedContent === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }

        if (!$this->levelExists($levelId)) {
            throw new \RuntimeException('Level not found.');
        }

        /*
         * Any text beginning with "!" is a command attempt.
         * Commands are never persisted as ordinary comments.
         */
        if (str_starts_with($decodedContent, "!")) {
            $commandName = strtolower(
                (string)(preg_split("/\\s+/", $decodedContent)[0] ?? "")
            );

            $commands = $this->commands ?? new CommentCommandService(
                $this->getPdo()
            );

            if ($commandName === "!help") {
                return $commands->response(
                    $gameVersion,
                    true,
                    $commands->help()
                );
            }

            $handled = $commands->handle(
                $levelId,
                $accountId,
                $decodedContent
            );

            return $commands->response(
                $gameVersion,
                $handled === true
            );
        }

        $percent = max(0, min(100, $percent));

        if ($this->pdo === null) {
            $commentId = $this->repository->addLevelComment(
                $levelId,
                $accountId,
                $decodedContent,
                $percent
            );

            return $gameVersion >= 21
                ? (string)$commentId
                : "1";
        }

        $this->pdo->beginTransaction();

        try {
            $commentId = $this->repository->addLevelComment(
                $levelId,
                $accountId,
                $decodedContent,
                $percent
            );

            /*
             * Legacy GD also records the progress attached to a comment as a
             * level score when percent is non-zero.
             */
            if ($percent > 0) {
                $this->recordCommentProgress(
                    $accountId,
                    $levelId,
                    $percent
                );
            }

            $this->pdo->commit();

            return $gameVersion >= 21
                ? (string)$commentId
                : "1";
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function recordCommentProgress(
        int $accountId,
        int $levelId,
        int $percent
    ): void {
        if ($this->pdo === null) {
            return;
        }

        $now = time();

        $q = $this->pdo->prepare(
            'INSERT INTO mucho_level_scores
             (account_id, level_id, is_daily, daily_id, percent,
              coins, attempts, clicks, play_time, progresses,
              created_at, updated_at)
             VALUES
             (:account_id, :level_id, 0, 0, :percent,
              0, 0, 0, 0, \'\',
              :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                percent = GREATEST(percent, VALUES(percent)),
                updated_at = CASE
                    WHEN VALUES(percent) > percent
                    THEN VALUES(updated_at)
                    ELSE updated_at
                END'
        );

        $q->execute([
            'account_id' => $accountId,
            'level_id' => $levelId,
            'percent' => $percent,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function getLevelComments(
        int $levelId,
        int $page,
        int $gameVersion = 22,
        int $binaryVersion = 0,
        int $count = 10,
        int $mode = 0
    ): string
    {
        if (!$this->levelExists($levelId)) {
            return '-2';
        }

        $limit = min(100, max(1, $count));
        $page = min(1000, max(0, $page));
        $offset = $page * $limit;
        $comments = $this->repository->getLevelComments(
            $levelId,
            $page,
            $limit,
            $mode !== 0
        );
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
                "badge"    => $badge,
                "clan_tag" => $comment["clan_tag"] ?? ''
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

                $tag = strtoupper(trim((string)($comment['clan_tag'] ?? '')));
                $username = (string)($comment['username'] ?? 'Player');

                if ($tag !== '') {
                    $prefix = '[' . $tag . ']';
                    $username = $prefix . substr(
                        $username,
                        0,
                        max(1, 20 - strlen($prefix))
                    );
                }

                $users[] = $uid . ':' .
                    \MuchoCore\Protocol\ProtocolText::username($username)
                    . ':' . $account;
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
                'clan_tag' => $comment['clan_tag'] ?? '',
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
                    $tag = strtoupper(trim((string)($comment['clan_tag'] ?? '')));
                    $username = (string)($comment['username'] ?? 'Player');
                    if ($tag !== '') {
                        $prefix = '[' . $tag . ']';
                        $username = $prefix . substr($username, 0, max(1, 20 - strlen($prefix)));
                    }

                    $users[] =
                        $uid . ':' .
                        \MuchoCore\Protocol\ProtocolText::username($username) .
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
        $accountId = $this->authenticateCommenter(
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
        $decodedContent = $this->normalizeCommentText($decodedContent);

        if ($decodedContent === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }

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
        $accountId = $this->authenticateCommenter(
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
        $accountId = $this->authenticateCommenter(
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

    private function normalizeCommentText(string $content): string
    {
        $content = str_replace(
            ["\\0", "~", "|", "#", ":"],
            "",
            $content
        );

        $content = preg_replace(
            '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',
            '',
            $content
        ) ?? '';

        $content = trim($content);

        if (
            $content !== '' &&
            mb_strlen($content, 'UTF-8') > 4096
        ) {
            throw new \RuntimeException(
                'Comment is too long.'
            );
        }

        return $content;
    }

    private function levelExists(int $levelId): bool
    {
        $q = $this->getPdo()->prepare(
            'SELECT 1
             FROM levels
             WHERE level_id = :id
               AND is_deleted = 0
             LIMIT 1'
        );

        $q->execute([
            'id' => $levelId
        ]);

        return $q->fetchColumn() !== false;
    }

    private function authenticateCommenter(
        int $accountId,
        string $gjp,
        int $gameVersion,
        string $udid,
        string $ip
    ): int {
        if ($gjp !== '') {
            $account = $this->auth->authenticate($accountId, $gjp);
            return (int)$account['account_id'];
        }

        if (
            $gameVersion > 0 &&
            $gameVersion < 19 &&
            trim($udid) !== '' &&
            $this->legacy10 !== null
        ) {
            /*
             * Cvolton-compatible early clients can submit comments without
             * accountID/gjp and identify themselves by device UDID.
             * Resolve that UDID to MuchoCore's non-privileged legacy identity.
             */
            $resolvedAccountId = $this->legacy10->resolveAccount(
                $udid,
                '',
                $ip
            );

            if ($resolvedAccountId > 0) {
                return $resolvedAccountId;
            }
        }

        if (
            $gameVersion === 19 &&
            $accountId > 0 &&
            trim($udid) !== '' &&
            trim($ip) !== ''
        ) {
            $account = $this->auth->authenticateLegacy19Upload(
                $accountId,
                $udid,
                $ip
            );
            return (int)$account['account_id'];
        }

        throw new RuntimeException('Unauthorized.');
    }
}
