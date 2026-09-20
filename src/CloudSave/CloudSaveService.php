<?php

declare(strict_types=1);

namespace MuchoCore\CloudSave;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use MuchoCore\Core\Settings;
use RuntimeException;

/*
 * MuchoCore Secure Cloud Save Service
 * Copyright (C) 2026 IZK
 */
final readonly class CloudSaveService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private CloudSaveRepository $repository
    ) {}

    public function backup(array $data): string
    {
        $accountId = (int)($data['accountID'] ?? 0);
        if ($accountId <= 0) {
            $accountId = $this->authenticate($data);
        }

        $saveData = $data['saveData'] ?? null;

        if (!is_string($saveData) || $saveData === '') {
            throw new RuntimeException('Missing cloud save payload.');
        }

        $size = strlen($saveData);
        $maxBytes = Settings::int(
            'MUCHO_CLOUD_SAVE_MAX_MB',
            32,
            1,
            256
        ) * 1024 * 1024;

        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Cloud save exceeds size limit.');
        }

        $this->repository->save($accountId, $saveData);

        return '1';
    }

    public function sync(array $data): string
    {
        $accountId = (int)($data['accountID'] ?? 0);

        if ($accountId <= 0) {
            $username = trim((string)($data['userName'] ?? $data['username'] ?? ''));
            if ($username !== '') {
                $q = $this->db->prepare("SELECT account_id FROM accounts WHERE username = :username LIMIT 1");
                $q->execute(['username' => $username]);
                $accountId = (int)$q->fetchColumn();
            }
        }

        if ($accountId <= 0) {
            return '-1';
        }

        $saveData = $this->repository->load($accountId);

        if ($saveData === null || $saveData === '') {
            return '-1';
        }

        $saveData = trim($saveData);

        // GD протокол требует суффикс ;21;30;a;a для корректного разбора сейва клиентом
        if (!str_contains($saveData, ';21;30;a;a')) {
            $saveData .= ';21;30;a;a';
        }

        return $saveData;
    }

    private function authenticate(array $data): int
    {
        $accountId = (int)($data['accountID'] ?? 0);
        $username = trim((string)($data['userName'] ?? $data['username'] ?? ''));

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

        if ($accountId <= 0) {
            throw new RuntimeException('Unknown cloud save account.');
        }

        return $accountId;
    }
}
