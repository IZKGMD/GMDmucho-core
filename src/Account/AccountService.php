<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use PDO;
use Throwable;

final readonly class AccountService
{
    public const GJP2_SALT = 'mI29fmAnxgTs';
    public const LEGACY_GJP2_SALT = 'mPtFakeGJKeyPair';

    public function __construct(
        private PDO $pdo,
        private AccountRepository $accounts,
    ) {}

    public function register(
        string $username,
        string $password,
        string $email,
        string $ip
    ): string {
        $username = trim($username);
        $email = trim($email);

        if ($username === '' || $password === '' || $email === '') {
            return '-1';
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,20}$/D',
                $username
            ) !== 1
        ) {
            return '-4';
        }

        if (
            strlen($password) > 256 ||
            strlen($email) > 254
        ) {
            return '-1';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '-1';
        }

        if ($this->accounts->usernameExists($username)) {
            return '-2';
        }

        if ($this->accounts->emailExists($email)) {
            return '-3';
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $gjp2 = sha1($password . self::GJP2_SALT);
        $gjp2Hash = password_hash($gjp2, PASSWORD_DEFAULT);

        try {
            $this->pdo->beginTransaction();

            $accountId = $this->accounts->createAccount(
                $username,
                $email,
                $passwordHash,
                $gjp2Hash
            );

            $this->accounts->createProfile($accountId);
            $this->accounts->audit($accountId, 'account.register', $ip);

            $this->pdo->commit();

            return '1';
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function login(
        string $username,
        string $password,
        string $gjp2,
        string $ip
    ): string {
        $username = trim($username);

        if (
            $username === '' ||
            strlen($username) > 20 ||
            strlen($password) > 512 ||
            strlen($gjp2) > 512
        ) {
            return '-1';
        }

        $account = $this->accounts->findByUsername($username);

        if ($account === null) {
            return '-1';
        }

        if (
            (int)($account['is_banned'] ?? 0) === 1 ||
            (int)($account['is_active'] ?? 0) !== 1
        ) {
            return '-1';
        }

        $storedPass = (string)($account['password_hash'] ?? '');
        $storedGjp2 = (string)($account['gjp2_hash'] ?? '');

        $valid = false;

        if ($password !== '') {
            // Обычный пароль.
            $valid = password_verify($password, $storedPass);

            // Совместимость с хранимым GJP2.
            if (!$valid && $storedGjp2 !== '') {
                $derived = sha1($password . self::GJP2_SALT);
                $legacy = sha1($password . self::LEGACY_GJP2_SALT);

                $valid =
                    password_verify($derived, $storedGjp2) ||
                    password_verify($legacy, $storedGjp2);
            }

            // Некоторые клиенты/патчеры могут прислать готовый hash.
            if (
                !$valid &&
                preg_match('/^[a-f0-9]{40}$/i', $password) === 1 &&
                $storedGjp2 !== ''
            ) {
                $valid = password_verify($password, $storedGjp2);
            }
        }

        if (
            !$valid &&
            $gjp2 !== '' &&
            $storedGjp2 !== ''
        ) {
            $valid = password_verify($gjp2, $storedGjp2);
        }

        if (!$valid) {
            $this->accounts->audit(
                (int)$account['account_id'],
                'account.login_failed',
                $ip
            );

            return '-1';
        }

        $accountId = (int)$account['account_id'];
        $userId = $this->accounts->ensureProfile($accountId);

        $this->accounts->markLogin($accountId);
        $this->accounts->audit($accountId, 'account.login', $ip);

        return $accountId . ',' . $userId;
    }
}
