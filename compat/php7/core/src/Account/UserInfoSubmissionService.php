<?php

declare (strict_types=1);
namespace MuchoCore\Account;

use PDO;
use RuntimeException;

/**
 * Stores the client's opaque GD user-info snapshot without treating it as
 * authoritative account progress.
 */
final class UserInfoSubmissionService
{
    const MAX_PAYLOAD_BYTES = 8388608;

    public function __construct(PDO $db, AccountAuthenticator $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    public function submit(int $accountId, string $credential, string $levelsInfo): bool
    {
        $this->auth->authenticate($accountId, $credential);
        if ($levelsInfo === '' || $levelsInfo === '0') {
            return false;
        }
        if (strlen($levelsInfo) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Invalid user-info payload.');
        }
        $stmt = $this->db->prepare('INSERT INTO mucho_account_user_info
                (account_id, levels_info, payload_sha256)
             VALUES
                (:account_id, :levels_info, :payload_sha256)
             ON DUPLICATE KEY UPDATE
                levels_info = VALUES(levels_info),
                payload_sha256 = VALUES(payload_sha256),
                updated_at = CURRENT_TIMESTAMP');
        $stmt->execute(['account_id' => $accountId, 'levels_info' => $levelsInfo, 'payload_sha256' => hash('sha256', $levelsInfo)]);
        return true;
    }
}
