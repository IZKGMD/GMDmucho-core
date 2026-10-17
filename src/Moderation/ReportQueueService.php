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
        if ($result['rows'] === []) {
            // Provisional GD no-results marker. Actual 2.209 client parsing
            // must be confirmed against a native success/empty response.
            return '-2';
        }

        // The 2.209 queue contains *accounts*, with abuse points from
        // player reports (3 each) and recent text-filter hits (1 each).
        // We currently have only the report side. Field numbering and
        // success envelope remain provisional until native parser verified.
        $items = [];
        foreach ($result['rows'] as $row) {
            $stamp = strtotime((string)$row['last_report_at']);
            $items[] = implode(':', [
                1, (int)$row['target_account_id'],
                2, \MuchoCore\Protocol\ProtocolText::username(
                    (string)$row['username']
                ),
                3, (int)$row['report_points'],
                4, (int)$row['report_count'],
                5, max(0, $stamp === false ? 0 : $stamp),
                6, (int)$row['last_report_id'],
            ]);
        }

        // This is an explicit adapter candidate, not a verified RobTop wire
        // response. Never mislabel it as native 2.209 compatibility.
        return implode('|', $items) . '#' .
            $result['total'] . ':' . ($page * 20) . ':20';
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
