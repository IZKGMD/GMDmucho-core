<?php

declare (strict_types=1);
namespace MuchoCore\User;

final class GameRole
{
    const USER = 'user';
    const MODERATOR = 'moderator';
    const ELDER_MODERATOR = 'elder_moderator';
    const OWNER = 'owner';
    /** @return list<string> */
    public static function codes(): array
    {
        return [self::USER, self::MODERATOR, self::ELDER_MODERATOR, self::OWNER];
    }
    public static function normalize(string $role): string
    {
        $role = strtolower(trim($role));
        return (function () use (&$role) {
            $__mcMatch1 = $role;
            if ($__mcMatch1 === 'player' || $__mcMatch1 === self::USER) {
                return self::USER;
            }
            if ($__mcMatch1 === self::MODERATOR || $__mcMatch1 === 'mod' || $__mcMatch1 === 'helper') {
                return self::MODERATOR;
            }
            if ($__mcMatch1 === self::ELDER_MODERATOR || $__mcMatch1 === 'admin' || $__mcMatch1 === 'elder' || $__mcMatch1 === 'developer') {
                return self::ELDER_MODERATOR;
            }
            if ($__mcMatch1 === self::OWNER) {
                return self::OWNER;
            }
            return (function () {
                throw new \InvalidArgumentException('Unknown game role.');
            })();
        })();
    }
    public static function accessLevel(string $role): string
    {
        return (function () use (&$role) {
            $__mcMatch2 = strtolower(trim($role));
            if ($__mcMatch2 === self::OWNER || $__mcMatch2 === self::ELDER_MODERATOR) {
                return '2';
            }
            if ($__mcMatch2 === self::MODERATOR) {
                return '1';
            }
            if ($__mcMatch2 === self::USER || $__mcMatch2 === 'player') {
                return '-1';
            }
            return (function () {
                throw new \InvalidArgumentException('Unknown game role.');
            })();
        })();
    }
    public static function badgeLevel(string $role): int
    {
        return (function () use (&$role) {
            $__mcMatch3 = strtolower(trim($role));
            if ($__mcMatch3 === self::OWNER || $__mcMatch3 === self::ELDER_MODERATOR) {
                return 2;
            }
            if ($__mcMatch3 === self::MODERATOR) {
                return 1;
            }
            if ($__mcMatch3 === self::USER || $__mcMatch3 === 'player') {
                return 0;
            }
            return (function () {
                throw new \InvalidArgumentException('Unknown game role.');
            })();
        })();
    }
    public static function displayName(string $role): string
    {
        return (function () use (&$role) {
            $__mcMatch4 = self::normalize($role);
            if ($__mcMatch4 === self::USER) {
                return 'User';
            }
            if ($__mcMatch4 === self::MODERATOR) {
                return 'Moderator';
            }
            if ($__mcMatch4 === self::ELDER_MODERATOR) {
                return 'Elder Moderator';
            }
            if ($__mcMatch4 === self::OWNER) {
                return 'Owner';
            }
            throw new \UnhandledMatchError();
        })();
    }
}
