<?php

declare(strict_types=1);

namespace MuchoCore\CloudSave;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;

final readonly class CloudSaveService
{
    private const MAX_SAVE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private CloudSaveRepository $repository
    ) {}

    public function backup(array $data): string
    {
        $accountId = $this->authenticate($data);

        $saveData = $data['saveData'] ?? null;

        if (!is_string($saveData) || $saveData === '') {
            throw new RuntimeException('Missing cloud save payload.');
        }

        $size = strlen($saveData);
        if ($size <= 0 || $size > self::MAX_SAVE_BYTES) {
            throw new RuntimeException('Cloud save exceeds size limit.');
        }

        $this->repository->save($accountId, $saveData);

        return '1';
    }

    public function sync(array $data): string
    {
        $accountId = $this->authenticate($data);

        $saveData = $this->repository->load($accountId);

        if ($saveData === null || $saveData === '') {
            return '-1';
        }

        // Preserve the stored save payload byte-for-byte. Trimming can
        // corrupt a legitimate legacy payload before it is sent back.
        if (!str_contains($saveData, ';21;30;a;a')) {
            $saveData .= ';21;30;a;a';
        }

        return $saveData;
    }

    private function authenticate(array $data): int
    {
        // Do not coerce malformed IDs such as "1junk", "1.5" or arrays
        // into another player's real account number. A missing/zero ID is
        // still supported for the legacy username-based Cloud Save route.
        $accountId = 0;
        $rawAccountId = $data['accountID'] ?? null;
        if ($rawAccountId !== null && $rawAccountId !== '') {
            if (!is_int($rawAccountId) && !is_string($rawAccountId)) {
                throw new RuntimeException('Invalid cloud save account ID.');
            }

            $digits = (string)$rawAccountId;
            if (preg_match('/\\A[0-9]{1,32}\\z/', $digits) !== 1) {
                throw new RuntimeException('Invalid cloud save account ID.');
            }

            $normalized = ltrim($digits, '0');
            $maximum = (string)PHP_INT_MAX;
            if (
                strlen($normalized) > strlen($maximum) ||
                (strlen($normalized) === strlen($maximum) &&
                    strcmp($normalized, $maximum) > 0)
            ) {
                throw new RuntimeException('Invalid cloud save account ID.');
            }

            $accountId = (int)$digits;
        }

        $rawUsername = $data['userName'] ?? $data['username'] ?? '';
        if (!is_string($rawUsername)) {
            throw new RuntimeException('Invalid cloud save username.');
        }
        $username = trim($rawUsername);

        if ($accountId <= 0 && $username !== '') {
            $q = $this->db->prepare("
                SELECT account_id
                FROM accounts
                WHERE username = :username
                LIMIT 1
            ");
            $q->execute(['username' => $username]);
            $accountId = (int)$q->fetchColumn();
        }

        // Match both the classic Cvolton contract and MuchoCore's GJP2 flow.
        // Some clients submit an empty legacy password field alongside GJP/GJP2,
        // so choose the first non-empty credential instead of the first present key.
        $credential = '';

        foreach (['password', 'gjp2', 'gjp'] as $key) {
            $candidate = $data[$key] ?? '';

            if (!is_scalar($candidate)) {
                continue;
            }

            // Keep credential bytes exactly as supplied. A password
            // containing leading/trailing spaces is a valid secret.
            $candidate = (string)$candidate;

            if ($candidate !== '') {
                $credential = $candidate;
                break;
            }
        }

        if ($accountId <= 0 || $credential === '') {
            throw new RuntimeException('Unauthorized cloud save account.');
        }

        $this->auth->authenticate($accountId, $credential);

        return $accountId;
    }
}
