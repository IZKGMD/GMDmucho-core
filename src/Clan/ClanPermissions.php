<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

final class ClanPermissions
{
    public const VIEW = 'view';
    public const VIEW_STATS = 'view_stats';
    public const LEAVE = 'leave';
    public const INVITE = 'invite';
    public const MANAGE_INVITES = 'manage_invites';
    public const MANAGE_APPLICATIONS = 'manage_applications';
    public const KICK = 'kick';
    public const MANAGE_BANS = 'manage_bans';
    public const MANAGE_ROLES = 'manage_roles';
    public const SETTINGS = 'settings';
    public const TRANSFER = 'transfer';
    public const DELETE = 'delete';

    public static function forRole(string $role): array
    {
        return match ($role) {
            'owner' => [
                self::VIEW,
                self::VIEW_STATS,
                self::INVITE,
                self::MANAGE_INVITES,
                self::MANAGE_APPLICATIONS,
                self::KICK,
                self::MANAGE_BANS,
                self::MANAGE_ROLES,
                self::SETTINGS,
                self::TRANSFER,
                self::DELETE,
            ],
            'officer' => [
                self::VIEW,
                self::VIEW_STATS,
                self::LEAVE,
                self::INVITE,
                self::MANAGE_INVITES,
                self::MANAGE_APPLICATIONS,
                self::KICK,
                self::MANAGE_BANS,
            ],
            default => [
                self::VIEW,
                self::VIEW_STATS,
                self::LEAVE,
            ],
        };
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }
}
