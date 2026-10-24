<?php

declare (strict_types=1);
namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use Throwable;
/** Shared authorization for direct downloads and level comments. */
final class LevelAccess
{
    public static function canRead(PDO $db, array $level, AccountAuthenticator $auth, int $viewer = 0, string $credential = ''): bool
    {
        if ((int) ($level['is_deleted'] ?? 0) !== 0) {
            return false;
        }
        if ((int) ($level['unlisted2'] ?? 0) === 0) {
            return true;
        }
        if ($viewer <= 0 || trim($credential) === '') {
            return false;
        }
        try {
            $auth->authenticate($viewer, $credential);
        } catch (Throwable $__mcCaught) {
            return false;
        }
        $owner = (int) $level['account_id'];
        if ($viewer === $owner) {
            return true;
        }
        // Native PDO prepares require a distinct placeholder per occurrence.
        $query = $db->prepare('SELECT 1 FROM friends
            WHERE (account_id=:viewer AND friend_account_id=:owner)
               OR (account_id=:reverse_owner AND friend_account_id=:reverse_viewer)
            LIMIT 1');
        $query->execute(['viewer' => $viewer, 'owner' => $owner, 'reverse_owner' => $owner, 'reverse_viewer' => $viewer]);
        return $query->fetchColumn() !== false;
    }
}
