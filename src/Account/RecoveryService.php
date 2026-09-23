<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Security\RateLimiter;

final readonly class RecoveryService
{
    private const TOKEN_TTL_SECONDS = 1800;

    public function __construct(
        private RecoveryRepository $repository,
        private RateLimiter $rateLimiter = new RateLimiter()
    ) {
    }

    public function requestReset(
        string $identity,
        string $ip,
        string $baseUrl
    ): void {
        $identity = trim($identity);

        if ($identity === '' || strlen($identity) > 254) {
            return;
        }

        if (!$this->rateLimiter->allow(
            'recovery-ip|' . $ip,
            5,
            900
        )) {
            return;
        }

        $normalized = strtolower($identity);

        if (!$this->rateLimiter->allow(
            'recovery-identity|' . hash('sha256', $normalized),
            3,
            900
        )) {
            return;
        }

        $account = $this->repository->findAccountByIdentity($identity);

        if (
            $account === null ||
            (int)($account['is_active'] ?? 0) !== 1 ||
            (int)($account['is_banned'] ?? 0) === 1
        ) {
            return;
        }

        $email = trim((string)($account['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date(
            'Y-m-d H:i:s',
            time() + self::TOKEN_TTL_SECONDS
        );

        $this->repository->createToken(
            (int)$account['account_id'],
            $tokenHash,
            $expiresAt,
            hash('sha256', $ip)
        );

        $link = rtrim($baseUrl, '/')
            . '/api/api/accounts/lostusername.php?token='
            . rawurlencode($token);

        $this->sendMail(
            $email,
            (string)$account['username'],
            $link
        );

        $this->repository->audit(
            (int)$account['account_id'],
            'account.recovery.requested',
            $ip
        );
    }

    public function tokenIsValid(string $token): bool
    {
        $token = trim($token);

        if (
            !preg_match('/^[a-f0-9]{64}$/i', $token)
        ) {
            return false;
        }

        return $this->repository->tokenIsValid(
            hash('sha256', strtolower($token))
        );
    }

    public function resetPassword(
        string $token,
        string $password,
        string $confirmation,
        string $ip
    ): bool {
        $token = trim($token);

        if (
            !preg_match('/^[a-f0-9]{64}$/i', $token) ||
            strlen($password) < 8 ||
            strlen($password) > 256 ||
            !hash_equals($password, $confirmation)
        ) {
            return false;
        }

        if (!$this->rateLimiter->allow(
            'recovery-reset-ip|' . $ip,
            10,
            900
        )) {
            return false;
        }

        $accountId = $this->repository->consumeToken(
            hash('sha256', strtolower($token))
        );

        if ($accountId === null) {
            return false;
        }

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $gjp2 = sha1(
            $password . AccountService::GJP2_SALT
        );

        $gjp2Hash = password_hash(
            $gjp2,
            PASSWORD_DEFAULT
        );

        $this->repository->updatePassword(
            $accountId,
            $passwordHash,
            $gjp2Hash
        );

        $this->repository->audit(
            $accountId,
            'account.recovery.completed',
            $ip
        );

        return true;
    }

    private function sendMail(
        string $email,
        string $username,
        string $link
    ): void {
        if (!function_exists('mail')) {
            error_log(
                '[MuchoCore Recovery] PHP mail() is unavailable.'
            );
            return;
        }

        $from = trim(
            (string)(
                $_ENV['MUCHO_RECOVERY_FROM']
                ?? getenv('MUCHO_RECOVERY_FROM')
                ?? ''
            )
        );

        if ($from === '') {
            $host = trim(
                (string)(
                    $_SERVER['HTTP_HOST'] ?? 'localhost'
                )
            );

            $host = preg_replace(
                '/[^A-Za-z0-9.-]/',
                '',
                $host
            ) ?: 'localhost';

            $from = 'no-reply@' . $host;
        }

        $subject = 'MuchoCore — восстановление аккаунта';

        $safeUsername = htmlspecialchars(
            $username,
            ENT_QUOTES |
            ENT_SUBSTITUTE,
            'UTF-8'
        );

        $safeLink = htmlspecialchars(
            $link,
            ENT_QUOTES |
            ENT_SUBSTITUTE,
            'UTF-8'
        );

        $boundary = '=_MuchoCore_' . bin2hex(random_bytes(8));

        $text = "Здравствуйте, {$username}!\n\n"
            . "Для восстановления аккаунта MuchoCore откройте ссылку:\n"
            . $link . "\n\n"
            . "Ссылка действует 30 минут и одноразова.\n"
            . "Если вы не запрашивали восстановление, просто проигнорируйте это письмо.\n";

        $html = '<!doctype html><html lang="ru"><body>'
            . '<h2>Восстановление аккаунта MuchoCore</h2>'
            . '<p>Здравствуйте, ' . $safeUsername . '.</p>'
            . '<p>Для восстановления аккаунта нажмите кнопку:</p>'
            . '<p><a href="' . $safeLink . '">Восстановить аккаунт</a></p>'
            . '<p>Ссылка действует 30 минут и может быть использована только один раз.</p>'
            . '<p>Если вы не запрашивали восстановление, проигнорируйте это письмо.</p>'
            . '</body></html>';

        $headers = [
            'From: MuchoCore <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Auto-Response-Suppress: All',
        ];

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text
            . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html
            . "\r\n\r\n"
            . '--' . $boundary . "--\r\n";

        $ok = @mail(
            $email,
            '=?UTF-8?B?' .
                base64_encode($subject) .
                '?=',
            $body,
            implode("\r\n", $headers)
        );

        if (!$ok) {
            error_log(
                '[MuchoCore Recovery] mail() failed for '
                . $email
            );
        }
    }
}
