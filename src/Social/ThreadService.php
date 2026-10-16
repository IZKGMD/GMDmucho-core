<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * GD 2.209 server-side thread semantics.
 *
 * Query/wire framing remains provisional until redacted official captures.
 * All membership and invitation decisions are enforced by the database.
 */
final readonly class ThreadService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    private function authorize(int $accountId, string $credential): void
    {
        $this->auth->authenticate($accountId, $credential);
    }

    private function threadForMember(int $accountId, int $threadId): ?array
    {
        if ($threadId < 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT t.id,t.title,t.owner_account_id,t.is_closed,t.updated_at
             FROM mucho_threads t
             INNER JOIN mucho_thread_members m ON m.thread_id=t.id
             WHERE t.id=:thread AND m.account_id=:account
             LIMIT 1'
        );
        $stmt->execute(['thread' => $threadId, 'account' => $accountId]);
        $thread = $stmt->fetch(PDO::FETCH_ASSOC);
        return $thread ?: null;
    }

    private function validTitle(string $title): ?string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120 ||
            preg_match('/[\x00-\x1F\x7F]/', $title)) {
            return null;
        }
        return $title;
    }

    public function create(int $accountId, string $credential, string $title): int
    {
        $this->authorize($accountId, $credential);
        $title = $this->validTitle($title);
        if ($title === null) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mucho_threads (owner_account_id,title)
                 VALUES (:owner,:title)'
            );
            $stmt->execute(['owner' => $accountId, 'title' => $title]);
            $id = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare(
                'INSERT INTO mucho_thread_members (thread_id,account_id)
                 VALUES (:thread,:owner)'
            );
            $stmt->execute(['thread' => $id, 'owner' => $accountId]);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function modify(
        int $accountId,
        string $credential,
        int $threadId,
        string $action,
        string $title = ''
    ): bool {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['owner_account_id'] !== $accountId) {
            return false;
        }

        if ($action === 'rename') {
            $title = $this->validTitle($title);
            if ($title === null || (int)$thread['is_closed'] === 1) {
                return false;
            }
            $stmt = $this->db->prepare(
                'UPDATE mucho_threads SET title=:title
                 WHERE id=:thread AND owner_account_id=:owner AND is_closed=0'
            );
            $stmt->execute([
                'title' => $title, 'thread' => $threadId, 'owner' => $accountId,
            ]);
            return true;
        }
        if ($action === 'close') {
            $stmt = $this->db->prepare(
                'UPDATE mucho_threads SET is_closed=1
                 WHERE id=:thread AND owner_account_id=:owner'
            );
            $stmt->execute(['thread' => $threadId, 'owner' => $accountId]);
            return true;
        }
        return false;
    }

    public function leave(int $accountId, string $credential, int $threadId): bool
    {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['owner_account_id'] === $accountId) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM mucho_thread_members WHERE thread_id=:thread AND account_id=:account'
        );
        $stmt->execute(['thread' => $threadId, 'account' => $accountId]);
        return $stmt->rowCount() === 1;
    }

    public function invite(int $accountId, string $credential, int $threadId, int $target): bool
    {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['is_closed'] === 1 ||
            (int)$thread['owner_account_id'] !== $accountId ||
            $target <= 0 || $target === $accountId) {
            return false;
        }

        $valid = $this->db->prepare(
            'SELECT 1 FROM accounts WHERE account_id=:target AND is_active=1 AND is_banned=0'
        );
        $valid->execute(['target' => $target]);
        if ($valid->fetchColumn() === false) {
            return false;
        }

        $blocks = $this->db->prepare(
            'SELECT 1 FROM blocks WHERE
                (account_id=:a1 AND blocked_account_id=:b1)
                OR (account_id=:b2 AND blocked_account_id=:a2)
             LIMIT 1'
        );
        $blocks->execute(['a1'=>$accountId,'b1'=>$target,'b2'=>$target,'a2'=>$accountId]);
        if ($blocks->fetchColumn() !== false) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $members = $this->db->prepare(
                'SELECT COUNT(*) FROM mucho_thread_members WHERE thread_id=:thread'
            );
            $members->execute(['thread' => $threadId]);
            if ((int)$members->fetchColumn() >= 32) {
                $this->db->rollBack();
                return false;
            }

            $joined = $this->db->prepare(
                'SELECT 1 FROM mucho_thread_members WHERE thread_id=:thread AND account_id=:target'
            );
            $joined->execute(['thread' => $threadId,'target' => $target]);
            if ($joined->fetchColumn() !== false) {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare(
                "INSERT INTO mucho_thread_invites
                    (thread_id,inviter_account_id,invitee_account_id,status)
                 VALUES (:thread,:sender,:target,'pending')
                 ON DUPLICATE KEY UPDATE
                    inviter_account_id=VALUES(inviter_account_id),
                    status='pending'"
            );
            $stmt->execute([
                'thread'=>$threadId,'sender'=>$accountId,'target'=>$target,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function respondInvite(
        int $accountId,
        string $credential,
        int $invitationId,
        bool $accept
    ): bool {
        $this->authorize($accountId, $credential);
        if ($invitationId <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT i.thread_id,t.is_closed
                 FROM mucho_thread_invites i
                 JOIN mucho_threads t ON t.id=i.thread_id
                 WHERE i.id=:invite AND i.invitee_account_id=:account
                   AND i.status='pending'
                 FOR UPDATE"
            );
            $stmt->execute(['invite'=>$invitationId,'account'=>$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return false;
            }

            $threadId = (int)$row['thread_id'];
            if ($accept) {
                if ((int)$row['is_closed'] === 1) {
                    $this->db->rollBack();
                    return false;
                }

                $members = $this->db->prepare(
                    'SELECT COUNT(*) FROM mucho_thread_members WHERE thread_id=:thread'
                );
                $members->execute(['thread'=>$threadId]);
                if ((int)$members->fetchColumn() >= 32) {
                    $this->db->rollBack();
                    return false;
                }

                $join = $this->db->prepare(
                    'INSERT IGNORE INTO mucho_thread_members (thread_id,account_id)
                     VALUES (:thread,:account)'
                );
                $join->execute(['thread'=>$threadId,'account'=>$accountId]);
            }

            $update = $this->db->prepare(
                'UPDATE mucho_thread_invites SET status=:status
                 WHERE id=:invite AND invitee_account_id=:account'
            );
            $update->execute([
                'status'=>$accept?'accepted':'declined',
                'invite'=>$invitationId,
                'account'=>$accountId,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function send(
        int $accountId,
        string $credential,
        int $threadId,
        string $body
    ): bool {
        $this->authorize($accountId, $credential);
        $body = trim($body);
        if ($body === '' || mb_strlen($body, 'UTF-8') > 4000 ||
            strlen($body) > 16000) {
            return false;
        }

        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['is_closed'] === 1) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mucho_thread_messages (thread_id,sender_account_id,body)
                 VALUES (:thread,:sender,:body)'
            );
            $stmt->execute(['thread'=>$threadId,'sender'=>$accountId,'body'=>$body]);
            $touch = $this->db->prepare(
                'UPDATE mucho_threads SET updated_at=UTC_TIMESTAMP()
                 WHERE id=:thread AND is_closed=0'
            );
            $touch->execute(['thread'=>$threadId]);
            if ($touch->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function threads(int $accountId, string $credential, int $page): array
    {
        $this->authorize($accountId, $credential);
        $offset = max(0, min($page, 1000)) * 20;
        $stmt = $this->db->prepare(
            "SELECT t.id,t.title,t.owner_account_id,t.is_closed,t.updated_at
             FROM mucho_threads t
             INNER JOIN mucho_thread_members m ON m.thread_id=t.id
             WHERE m.account_id=:account
             ORDER BY t.updated_at DESC,t.id DESC LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['account'=>$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function messages(int $accountId, string $credential, int $threadId, int $page): array
    {
        $this->authorize($accountId, $credential);
        if (!$this->threadForMember($accountId, $threadId)) {
            return [];
        }
        $offset = max(0, min($page, 1000)) * 20;
        $stmt = $this->db->prepare(
            "SELECT m.id,m.thread_id,m.sender_account_id,m.body,m.created_at,a.username
             FROM mucho_thread_messages m
             JOIN accounts a ON a.account_id=m.sender_account_id
             WHERE m.thread_id=:thread
             ORDER BY m.id DESC LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['thread'=>$threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function members(int $accountId, string $credential, int $threadId): array
    {
        $this->authorize($accountId, $credential);
        if (!$this->threadForMember($accountId, $threadId)) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT m.account_id,a.username,p.user_id,p.cube,p.color1,p.color2,p.special
             FROM mucho_thread_members m
             JOIN accounts a ON a.account_id=m.account_id
             JOIN profiles p ON p.account_id=a.account_id
             WHERE m.thread_id=:thread ORDER BY m.joined_at ASC,m.account_id ASC LIMIT 32'
        );
        $stmt->execute(['thread'=>$threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function invites(int $accountId, string $credential): array
    {
        $this->authorize($accountId, $credential);
        $stmt = $this->db->prepare(
            "SELECT i.id,i.thread_id,i.inviter_account_id,
                    t.title,i.created_at
             FROM mucho_thread_invites i
             JOIN mucho_threads t ON t.id=i.thread_id
             WHERE i.invitee_account_id=:account AND i.status='pending'
               AND t.is_closed=0
             ORDER BY i.id DESC LIMIT 50"
        );
        $stmt->execute(['account'=>$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(
        int $accountId,
        string $credential,
        int $threadId,
        string $term,
        int $page
    ): array {
        $this->authorize($accountId, $credential);
        $term = trim($term);
        if (mb_strlen($term, 'UTF-8') < 2 || mb_strlen($term, 'UTF-8') > 120 ||
            !$this->threadForMember($accountId, $threadId)) {
            return [];
        }
        $escaped = addcslashes($term, '%_\\');
        $offset = max(0, min($page, 1000)) * 20;
        $stmt = $this->db->prepare(
            "SELECT m.id,m.thread_id,m.sender_account_id,m.body,m.created_at,a.username
             FROM mucho_thread_messages m
             JOIN accounts a ON a.account_id=m.sender_account_id
             WHERE m.thread_id=:thread AND m.body LIKE :needle
             ORDER BY m.id DESC LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['thread'=>$threadId,'needle'=>'%'.$escaped.'%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
