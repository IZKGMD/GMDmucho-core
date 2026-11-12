<?php

declare (strict_types=1);
namespace MuchoCore\CloudSave;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
final class CloudSaveService
{
    const MAX_SAVE_BYTES = 32 * 1024 * 1024;
    public function __construct(PDO $db, AccountAuthenticator $auth, CloudSaveRepository $repository)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->repository = $repository;
    }
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
        // Keep the original PHP 8 credential and identity rules in PHP 7.
        $accountId = 0;
        $rawAccountId = $data['accountID'] ?? null;
        if ($rawAccountId !== null && $rawAccountId !== '') {
            if (!is_int($rawAccountId) && !is_string($rawAccountId)) {
                throw new RuntimeException('Invalid cloud save account ID.');
            }
            $digits = (string) $rawAccountId;
            if (preg_match('/\\A[0-9]{1,32}\\z/', $digits) !== 1) {
                throw new RuntimeException('Invalid cloud save account ID.');
            }
            $normalized = ltrim($digits, '0');
            $maximum = (string) PHP_INT_MAX;
            if (
                strlen($normalized) > strlen($maximum) ||
                (strlen($normalized) === strlen($maximum) &&
                    strcmp($normalized, $maximum) > 0)
            ) {
                throw new RuntimeException('Invalid cloud save account ID.');
            }
            $accountId = (int) $digits;
        }
        $rawUsername = $data['userName'] ?? $data['username'] ?? '';
        if (!is_string($rawUsername)) {
            throw new RuntimeException('Invalid cloud save username.');
        }
        $username = trim($rawUsername);
        if ($accountId <= 0 && $username !== '') {
            $q = $this->db->prepare("\n                SELECT account_id\n                FROM accounts\n                WHERE username = :username\n                LIMIT 1\n            ");
            $q->execute(['username' => $username]);
            $accountId = (int) $q->fetchColumn();
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
            $candidate = (string) $candidate;
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
    private $db;
    private $auth;
    private $repository;
}
