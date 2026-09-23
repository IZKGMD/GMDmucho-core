<?php

declare(strict_types=1);

namespace MuchoCore\User;

final class GameRole
{
    public const USER = 'user';
    public const MODERATOR = 'moderator';
    public const ELDER_MODERATOR = 'elder_moderator';
    public const OWNER = 'owner';

    /** @return list<string> */
    public static function codes(): array
    {
        return [
            self::USER,
            self::MODERATOR,
            self::ELDER_MODERATOR,
            self::OWNER,
        ];
    }

    public static function normalize(string $role): string
    {
        $role = strtolower(trim($role));

        return match ($role) {
            'player', self::USER => self::USER,
            self::MODERATOR => self::MODERATOR,
            self::ELDER_MODERATOR => self::ELDER_MODERATOR,
            self::OWNER => self::OWNER,
            default => throw new \InvalidArgumentException('Unknown game role.'),
        };
    }

    public static function accessLevel(string $role): string
    {
        return match (self::normalize($role)) {
            self::OWNER, self::ELDER_MODERATOR => '2',
            self::MODERATOR => '1',
            default => '-1',
        };
    }

    public static function badgeLevel(string $role): int
    {
        return match (self::normalize($role)) {
            self::OWNER, self::ELDER_MODERATOR => 2,
            self::MODERATOR => 1,
            default => 0,
        };
    }

    public static function displayName(string $role): string
    {
        return match (self::normalize($role)) {
            self::USER => 'User',
            self::MODERATOR => 'Moderator',
            self::ELDER_MODERATOR => 'Elder Moderator',
            self::OWNER => 'Owner',
        };
    }
}
