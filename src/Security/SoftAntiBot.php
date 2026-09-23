<?php

declare(strict_types=1);

namespace MuchoCore\Security;

final class SoftAntiBot
{
    private const MIN_FORM_AGE = 1.0;
    private const MAX_FORM_AGE = 1800.0;

    /**
     * Create a lightweight, invisible anti-bot token for a form.
     * No captcha, puzzle, or external service is required.
     */
    public static function issue(string $scope): array
    {
        if (!isset($_SESSION['mucho_antibot']) || !is_array($_SESSION['mucho_antibot'])) {
            $_SESSION['mucho_antibot'] = [];
        }

        $token = bin2hex(random_bytes(16));

        $_SESSION['mucho_antibot'][$scope] = [
            'token' => $token,
            'started_at' => microtime(true),
        ];

        return ['token' => $token];
    }

    public static function verify(
        string $scope,
        string $token,
        string $honeypot
    ): bool {
        if (trim($honeypot) !== '') {
            return false;
        }

        $state = $_SESSION['mucho_antibot'][$scope] ?? null;
        unset($_SESSION['mucho_antibot'][$scope]);

        if (!is_array($state)) {
            return false;
        }

        $expected = (string)($state['token'] ?? '');
        $startedAt = (float)($state['started_at'] ?? 0);

        if (
            $expected === '' ||
            $token === '' ||
            !hash_equals($expected, $token)
        ) {
            return false;
        }

        $age = microtime(true) - $startedAt;

        return $age >= self::MIN_FORM_AGE && $age <= self::MAX_FORM_AGE;
    }
}
