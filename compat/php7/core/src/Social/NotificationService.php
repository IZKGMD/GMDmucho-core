<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\ProtocolText;
use PDO;

/**
 * Native PHP7 implementation of the verified GD 2.209 follow-notification
 * subset. The wire layout must match PHP8 exactly; other event classes are
 * deliberately excluded pending actual client evidence.
 */
final class NotificationService
{
    private $db;
    private $auth;

    public function __construct(PDO $db, AccountAuthenticator $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    public function list(int $accountId, string $credential, int $page): string
    {
        $this->auth->authenticate($accountId, $credential);
        $page = max(0, min(1000, $page));
        $offset = $page * 20;
        $count = $this->db->prepare(
            "SELECT COUNT(*) FROM mucho_notifications
             WHERE account_id=:account AND notification_type='follow'"
        );
        $count->execute(['account' => $accountId]);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT n.id,n.notification_type,n.source_id,n.is_read,n.reward_claimed,
                    n.created_at,n.actor_account_id,a.username,p.user_id
             FROM mucho_notifications n
             INNER JOIN accounts a ON a.account_id=n.actor_account_id
             LEFT JOIN profiles p ON p.account_id=n.actor_account_id
             WHERE n.account_id=:account AND n.notification_type='follow'
             ORDER BY n.id DESC
             LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['account' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return '-2';
        }
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::encodeNotification($row);
        }
        return implode('|', $items) . '#' . $total . ':' . $offset . ':20';
    }

    /** 16 official positional keys; only the documented follower event. */
    public static function encodeNotification(array $row, $now = null): string
    {
        if ((string)($row['notification_type'] ?? '') !== 'follow') {
            throw new \InvalidArgumentException('Unsupported notification event type.');
        }

        $username = ProtocolText::username((string)($row['username'] ?? 'Player'));
        $created = strtotime((string)($row['created_at'] ?? ''));
        $elapsed = $created === false ? 0 : max(0, ($now === null ? time() : (int)$now) - $created);
        if ($elapsed < 60) {
            $elapsed = 0;
        }
        $age = '0 seconds';
        foreach ([
            [31536000, 'year'], [2592000, 'month'],
            [604800, 'week'], [86400, 'day'],
            [3600, 'hour'], [60, 'minute'], [1, 'second'],
        ] as $unit) {
            if ($elapsed < $unit[0]) {
                continue;
            }
            $amount = intdiv($elapsed, $unit[0]);
            $age = $amount . ' ' . $unit[1] . ($amount === 1 ? '' : 's');
            break;
        }

        $encode = static function (string $value): string {
            return strtr(base64_encode($value), '+/', '-_');
        };
        return implode(':', [
            1, (int)$row['id'],
            2, 13,
            3, '',
            4, $encode('New follower'),
            5, $encode($username . ' started following you.'),
            6, $age,
            7, (int)($row['is_read'] ?? 0) === 0 ? 1 : 0,
            8, 0,
            9, 0,
            10, '',
            11, 0,
            12, 0,
            13, 0,
            14, 0,
            15, (int)$row['actor_account_id'],
            16, $username,
        ]);
    }

    public function claim(int $accountId, string $credential, int $notificationId): bool
    {
        $this->auth->authenticate($accountId, $credential);
        if ($notificationId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE mucho_notifications
             SET reward_claimed=1,is_read=1
             WHERE id=:id AND account_id=:account
               AND notification_type="follow" AND reward_claimed=0'
        );
        $stmt->execute(['id' => $notificationId, 'account' => $accountId]);
        return $stmt->rowCount() === 1;
    }
}
