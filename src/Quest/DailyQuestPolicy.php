<?php

declare(strict_types=1);

namespace MuchoCore\Quest;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MuchoCore\Core\Environment;

final class DailyQuestPolicy
{
    public static function enabled(): bool
    {
        foreach (['MUCHO_PLUGINS_ENABLED', 'MUCHO_DAILY_QUESTS_ENABLED'] as $flag) {
            if (!in_array(strtolower(trim(Environment::get($flag, '1') ?? '1')), ['1','true','yes','on'], true)) {
                return false;
            }
        }
        return true;
    }

    public static function validDay(string $day): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $day;
    }

    public static function calendar(int $epoch): array
    {
        $day = gmdate('Y-m-d', $epoch);
        $next = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->getTimestamp();
        return ['day' => $day, 'server_time' => $epoch, 'reset_at' => $next,
            'remaining_seconds' => max(0, $next - $epoch)];
    }

    public static function definitions(string $day): array
    {
        if (!self::validDay($day)) {
            throw new InvalidArgumentException('Invalid UTC quest day.');
        }
        $rotation = (int)(new DateTimeImmutable($day, new DateTimeZone('UTC')))->format('z') % 3;
        return [
            ['id' => 'stars', 'target' => [5,10,15][$rotation], 'reward_xp' => 25],
            ['id' => 'moons', 'target' => [5,10,15][($rotation+1)%3], 'reward_xp' => 40],
            ['id' => 'demons', 'target' => 1, 'reward_xp' => 75],
        ];
    }
}
