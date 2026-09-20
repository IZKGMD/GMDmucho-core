<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdLevelListEncoder;

final readonly class LevelService
{
    private const PAGE_SIZE = 10;

    public function __construct(
        private LevelRepository $levels,
        private GdLevelListEncoder $encoder,
    ) {
    }

    public function getLevels(
        array $input,
        int $detectedGameVersion = 0
    ): string
    {
        $type = $this->integer($input['type'] ?? 0);
        $page = min(
            1000,
            max(0, $this->integer($input['page'] ?? 0))
        );
        $gameVersion = max(
            0,
            $this->integer($input['gameVersion'] ?? 0)
        );

        if ($gameVersion === 0 && $detectedGameVersion > 0) {
            $gameVersion = $detectedGameVersion;
        }

        $authenticatedAccountId = 0;

        if ($type === 13) {
            $accountId = $this->integer($input['accountID'] ?? 0);
            $credential = $this->credential($input, $gameVersion);

            if ($accountId <= 0 || $credential === '') {
                return '-1';
            }

            try {
                $account = $this->auth->authenticate(
                    $accountId,
                    $credential
                );
                $authenticatedAccountId = (int)$account['account_id'];
            } catch (\Throwable) {
                return '-1';
            }
        }

        $demonFilter = max(
            0,
            $this->integer($input['demonFilter'] ?? 0)
        );

        $search = $input['str'] ?? '';

        if (!is_string($search)) {
            $search = '';
        }

        $search = trim($search);

        if (strlen($search) > 100) {
            return '-1';
        }

        $offset = $page * self::PAGE_SIZE;

        $result = $this->levels->search(
            type: $type,
            search: $search,
            gameVersion: $gameVersion,
            offset: $offset,
            limit: self::PAGE_SIZE,
            demonFilter: $demonFilter,
            friendAccountId: $authenticatedAccountId,
        );

        if (empty($result['levels'])) { return '-2'; } return $this->encoder->encode(
            levels: $result['levels'],
            total: $result['total'],
            offset: $offset,
            limit: self::PAGE_SIZE,
            gameVersion: $gameVersion,
        );
    }


    /** @param array<string,mixed> $input */
    private function credential(array $input, int $gameVersion): string
    {
        $legacy = isset($input['gjp']) && is_scalar($input['gjp'])
            ? trim((string)$input['gjp'])
            : '';
        $modern = isset($input['gjp2']) && is_scalar($input['gjp2'])
            ? trim((string)$input['gjp2'])
            : '';

        return $gameVersion >= 22
            ? ($modern !== '' ? $modern : $legacy)
            : ($legacy !== '' ? $legacy : $modern);
    }

    private function integer(mixed $value): int
    {
        if (
            is_int($value) ||
            (is_string($value) && preg_match('/^-?\d+$/', $value))
        ) {
            return (int) $value;
        }

        return 0;
    }
}
