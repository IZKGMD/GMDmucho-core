<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use PDO;
use RuntimeException;

/**
 * Stores the client's opaque GD user-info snapshot without treating it as
 * authoritative account progress.
 */
final readonly class UserInfoSubmissionService
{
    public const MAX_PAYLOAD_BYTES = 1_048_576;

    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    public function submit(
        int $accountId,
        string $credential,
        string $levelsInfo
    ): void {
        if (
            ($levelsInfo === '' || $levelsInfo === '0') ||
            strlen($levelsInfo) > self::MAX_PAYLOAD_BYTES
        ) {
            throw new RuntimeException('Invalid user-info payload.');
        }

        // A public account ID or device UDID is not an authentication proof.
        // Match the established GDPS behavior: only an authenticated account
        // may replace its private user-info snapshot.
        $this->auth->authenticate($accountId, $credential);

        $stmt = $this->db->prepare(
            'INSERT INTO mucho_account_user_info
                (account_id, levels_info, payload_sha256)
             VALUES
                (:account_id, :levels_info, :payload_sha256)
             ON DUPLICATE KEY UPDATE
                levels_info = VALUES(levels_info),
                payload_sha256 = VALUES(payload_sha256),
                updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'levels_info' => $levelsInfo,
            'payload_sha256' => hash('sha256', $levelsInfo),
        ]);
    }
}
