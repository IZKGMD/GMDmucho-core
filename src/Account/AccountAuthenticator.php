<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use PDO;
use RuntimeException;
use Throwable;

final readonly class AccountAuthenticator
{
    private const XOR_KEY = '37526';

    public function __construct(
        private PDO $pdo
    ) {}

    public function authenticateGjp2(
        int $accountId,
        string $gjp2
    ): ?array {
        try {
            return $this->authenticate($accountId, $gjp2);
        } catch (Throwable) {
            return null;
        }
    }

    public function authenticate(
        int $accountId,
        string $credential
    ): array {
        $credential = trim($credential);

        if (
            $accountId <= 0 ||
            $credential === '' ||
            strlen($credential) > 1024
        ) {
            throw new RuntimeException('Unauthorized.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                a.account_id,
                a.username,
                a.password_hash,
                a.gjp2_hash,
                a.is_active,
                a.is_banned,
                p.user_id
             FROM accounts a
             LEFT JOIN profiles p
               ON p.account_id = a.account_id
             WHERE a.account_id = :account_id
             LIMIT 1'
        );

        $stmt->execute([
            ':account_id' => $accountId
        ]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$account ||
            (int)$account['is_banned'] === 1 ||
            (int)$account['is_active'] !== 1
        ) {
            throw new RuntimeException('Unauthorized.');
        }

        $storedPass = (string)($account['password_hash'] ?? '');
        $storedGjp2 = (string)($account['gjp2_hash'] ?? '');

        $valid = false;

        /*
         * 1. Plain credential.
         * Нужен для совместимости с текущим ядром/старыми клиентами.
         */
        if ($storedPass !== '') {
            $valid = password_verify($credential, $storedPass);
        }

        /*
         * 2. Уже готовый 40-char hash.
         */
        if (
            !$valid &&
            $storedGjp2 !== '' &&
            preg_match('/^[a-f0-9]{40}$/i', $credential) === 1
        ) {
            $valid = password_verify($credential, $storedGjp2);
        }

        /*
         * 3. URL-safe Base64 + XOR вариант, который уже поддерживало
         * старое ядро.
         */
        if (!$valid) {
            $decodedPassword = $this->decodeXorCredential($credential);

            if ($decodedPassword !== null) {
                if ($storedPass !== '') {
                    $valid = password_verify(
                        $decodedPassword,
                        $storedPass
                    );
                }

                if (!$valid && $storedGjp2 !== '') {
                    $current = sha1(
                        $decodedPassword .
                        AccountService::GJP2_SALT
                    );

                    $legacy = sha1(
                        $decodedPassword .
                        AccountService::LEGACY_GJP2_SALT
                    );

                    $valid =
                        password_verify($current, $storedGjp2) ||
                        password_verify($legacy, $storedGjp2);
                }
            }
        }

        if (!$valid) {
            throw new RuntimeException('Unauthorized.');
        }

        if (empty($account['user_id'])) {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO profiles
                    (account_id, stars, created_at, updated_at)
                 VALUES
                    (:aid, 0, NOW(), NOW())'
            );

            $insert->execute([
                ':aid' => $accountId
            ]);

            $account['user_id'] = $accountId;
        }

        return $account;
    }

    public function authenticateLegacy19Upload(
        int $accountId,
        string $udid,
        string $ip
    ): array {
        $udid = trim($udid);
        $ip = trim($ip);

        if (
            $accountId <= 0 ||
            $udid === '' ||
            strlen($udid) > 255 ||
            $ip === ''
        ) {
            throw new RuntimeException('Unauthorized.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                a.account_id,
                a.username,
                a.password_hash,
                a.gjp2_hash,
                a.is_active,
                a.is_banned,
                p.user_id,
                s.id AS session_id,
                s.udid_hash
             FROM accounts a
             LEFT JOIN profiles p
               ON p.account_id = a.account_id
             INNER JOIN mucho_legacy_19_sessions s
               ON s.account_id = a.account_id
              AND s.ip_address = :ip_address
              AND s.expires_at > UTC_TIMESTAMP()
             WHERE a.account_id = :account_id
             ORDER BY s.created_at DESC
             LIMIT 16'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'ip_address' => $ip,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!password_verify($udid, (string)$row['udid_hash'])) {
                continue;
            }

            $touch = $this->pdo->prepare(
                'UPDATE mucho_legacy_19_sessions
                 SET last_used_at = UTC_TIMESTAMP(),
                     expires_at = DATE_ADD(
                         UTC_TIMESTAMP(),
                         INTERVAL 1 HOUR
                     )
                 WHERE id = :id'
            );

            $touch->execute([
                'id' => (int)$row['session_id'],
            ]);

            unset($row['session_id'], $row['udid_hash']);

            if (
                (int)$row['is_banned'] === 1 ||
                (int)$row['is_active'] !== 1
            ) {
                throw new RuntimeException('Unauthorized.');
            }

            return $row;
        }

        throw new RuntimeException('Unauthorized.');
    }

    private function decodeXorCredential(
        string $credential
    ): ?string {
        $normalized = strtr(
            $credential,
            '-_',
            '+/'
        );

        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false || $decoded === '') {
            return null;
        }

        $result = '';
        $keyLength = strlen(self::XOR_KEY);

        for ($i = 0, $length = strlen($decoded); $i < $length; $i++) {
            $result .=
                $decoded[$i] ^
                self::XOR_KEY[$i % $keyLength];
        }

        if (
            $result === '' ||
            strlen($result) > 256 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $result)
        ) {
            return null;
        }

        return $result;
    }
}
