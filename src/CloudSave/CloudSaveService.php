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
        $accountId = $this->authenticateAccount($data);

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
        $accountId = $this->authenticateAccount($data);
        $saveData = $this->repository->load($accountId);

        if ($saveData === null || $saveData === '') {
            return '-1';
        }

        $saveData = trim($saveData);

        // GD protocol expects the save-version suffix.
        if (!str_contains($saveData, ';21;30;a;a')) {
            $saveData .= ';21;30;a;a';
        }

        return $saveData;
    }

    private function authenticateAccount(array $data): int
    {
        $accountId = (int)($data['accountID'] ?? 0);
        $username = trim(
            (string)($data['userName'] ?? $data['username'] ?? '')
        );

        if ($accountId <= 0 && $username !== '') {
            $q = $this->db->prepare(
                'SELECT account_id
                 FROM accounts
                 WHERE username = :username
                 LIMIT 1'
            );

            $q->execute([
                'username' => $username
            ]);

            $accountId = (int)$q->fetchColumn();
        }

        $credential = trim(
            (string)($data['gjp2'] ?? $data['gjp'] ?? '')
        );

        if ($accountId <= 0 || $credential === '') {
            throw new RuntimeException('Unauthorized.');
        }

        $account = $this->auth->authenticate(
            $accountId,
            $credential
        );

        return (int)$account['account_id'];
    }
}
