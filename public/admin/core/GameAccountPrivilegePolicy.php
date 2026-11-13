<?php

declare(strict_types=1);

namespace MuchoCore\Admin;

/**
 * Keep privileged Geometry Dash game accounts protected from lower-rank
 * administrator operations. Admin-panel roles and game roles use different
 * numeric scales, so normalize the existing game rank before comparing them.
 */
final class GameAccountPrivilegePolicy
{
    public static function ownerRequired(
        int $actorAdminRank,
        string $currentGameRole,
        int $currentGamePriority,
        ?int $requestedGamePriority = null
    ): bool {
        $currentRank = match (strtolower(trim($currentGameRole))) {
            'owner' => 40,
            'elder_moderator' => 30,
            'moderator' => 20,
            // Unknown non-default game roles must not become a bypass.
            default => $currentGamePriority > 0 ? 40 : 0,
        };

        return
            ($currentRank > 0 && $currentRank >= $actorAdminRank) ||
            ($requestedGamePriority !== null &&
                $requestedGamePriority > 0 &&
                $requestedGamePriority >= $actorAdminRank);
    }
}
