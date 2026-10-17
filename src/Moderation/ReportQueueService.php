<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Account\AccountAuthenticator;
use RuntimeException;

/**
 * Content abuse queue for 2.209. Its native response layout is not verified.
 */
final readonly class ReportQueueService
{
    public function __construct(
        private AccountAuthenticator $auth,
        private ModerationRepository $roles,
        private ReportQueueRepository $reports,
    ) {}

    private function authorize(int $accountId, string $credential): void
    {
        $this->auth->authenticate($accountId, $credential);
    }

    public function getQueue(
        int $accountId,
        string $credential,
        int $page,
        int $sort,
        int $mode,
        int $flags
    ): string {
        $this->authorize($accountId, $credential);

        // 2.209 renamed elder moderators to Moderators and legacy moderators
        // to Rating Advisors. Only Moderators and owners can view abuse reports.
        if (!in_array($this->roles->getAccountRole($accountId),
            ['elder_moderator','owner'], true)) {
            throw new RuntimeException('Moderator privilege required.');
        }

        if ($page < 0 || $page > 1000
            || $sort < 0 || $sort > 100
            || $mode < 0 || $mode > 100
            || $flags < 0 || $flags > 65535) {
            throw new RuntimeException('Invalid queue filters.');
        }

        $result = $this->reports->pending($page, $sort);
        // Disassembled native GD 2.209 parser at 0x140242a0c:
        // response MUST have "<total>:<offset>:<pageSize>#<rows>".
        // A '-2' body or a reversed envelope calls the client's failure
        // delegate and displays "Failed to load the queue".
        // Entry records are pipe-separated, colon-positional and need >=9
        // fields. Record semantics beyond account ID/name are provisional.
        $header = $result['total'] . ':' . ($page * 20) . ':20';
        if ($result['rows'] === []) {
            return $header . '#';
        }

        // Native AdminReportQueueEntry is split on ':' and reads positional
        // fields 0..8; further optional blocks are read only for >=12,
        // >=18 and >=20 fields. Account ID/name are verified from the
        // constructor's downstream user lookup, remaining fields are
        // placeholders until their client UI semantics are established.
        $items = [];
        foreach ($result['rows'] as $row) {
            $stamp = strtotime((string)$row['last_report_at']);
            $items[] = implode(':', [
                (int)$row['target_account_id'],                           // 0 account ID
                \MuchoCore\Protocol\ProtocolText::username((string)$row['username']), // 1 username
                (int)$row['report_points'],                              // 2 (provisional)
                (int)$row['report_count'],                               // 3 (provisional)
                0,                                                        // 4
                0,                                                        // 5
                max(0, $stamp === false ? 0 : $stamp),                    // 6
                '',                                                       // 7
                0,                                                        // 8
            ]);
        }

        return $header . '#' . implode('|', $items);
    }

    public function reportLevel(
        int $accountId,
        string $credential,
        int $levelId,
        int $reason,
        string $details
    ): bool {
        $this->authorize($accountId, $credential);
        if ($reason < 0 || $reason > 255 || strlen($details) > 500) {
            return false;
        }
        return $this->reports->reportLevel(
            $accountId, $levelId, $reason, $details
        );
    }
}
