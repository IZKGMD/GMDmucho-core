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
        private ?AccountAuthenticator $auth = null,
    ) {
    }

    public function getLevels(array $input): string
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

        $demonFilter = max(
            0,
            $this->integer($input['demonFilter'] ?? 0)
        );

        $search = $input['str'] ?? '';

        if (!is_string($search)) {
            $search = '';
        }

        $search = trim($search);

        $viewerAccountId = 0;
        if ($type === 13) {
            $viewerAccountId = $this->authenticatedViewer($input);
            if ($viewerAccountId <= 0) {
                return '-1';
            }
        }

        if (strlen($search) > 100) {
            return '-1';
        }

        $filters = [
            'diff' => is_scalar($input['diff'] ?? '')
                ? (string)$input['diff']
                : '',
            'original' => (int)($input['original'] ?? 0) === 1,
            'coins' => (int)($input['coins'] ?? 0) === 1,
            'uncompleted' => (int)($input['uncompleted'] ?? 0) === 1,
            'onlyCompleted' => (int)($input['onlyCompleted'] ?? 0) === 1,
            'completedLevels' => is_scalar($input['completedLevels'] ?? '')
                ? (string)$input['completedLevels']
                : '',
            'song' => is_scalar($input['song'] ?? '')
                ? (string)$input['song']
                : '',
            'customSong' => (int)($input['customSong'] ?? 0) === 1,
            'twoPlayer' => (int)($input['twoPlayer'] ?? 0) === 1,
            'star' => (int)($input['star'] ?? 0) === 1,
            'noStar' => (int)($input['noStar'] ?? 0) === 1,
            'featured' => (int)($input['featured'] ?? 0) === 1,
            'epic' => (int)($input['epic'] ?? 0) === 1,
            'mythic' => (int)($input['mythic'] ?? 0) === 1,
            'legendary' => (int)($input['legendary'] ?? 0) === 1,
            'len' => is_scalar($input['len'] ?? '')
                ? (string)$input['len']
                : '',
            'gauntlet' => is_scalar($input['gauntlet'] ?? '')
                ? (string)$input['gauntlet']
                : '',
            'followed' => is_scalar($input['followed'] ?? '')
                ? (string)$input['followed']
                : '',
        ];

        $offset = $page * self::PAGE_SIZE;

        $result = $this->levels->search(
            type: $type,
            search: $search,
            gameVersion: $gameVersion,
            offset: $offset,
            limit: self::PAGE_SIZE,
            demonFilter: $demonFilter,
            filters: $filters,
            viewerAccountId: $viewerAccountId,
        );

        if (empty($result['levels'])) {
            return '-2';
        }

        return $this->encoder->encode(
            levels: $result['levels'],
            total: $result['total'],
            offset: $offset,
            limit: self::PAGE_SIZE,
            gameVersion: $gameVersion,
        );
    }

    private function authenticatedViewer(array $input): int
    {
        if ($this->auth === null) {
            return 0;
        }

        $accountId = $this->integer($input['accountID'] ?? 0);
        if ($accountId <= 0) {
            return 0;
        }

        $gameVersion = $this->integer($input['gameVersion'] ?? 0);
        $credential = $gameVersion >= 22
            ? (string)($input['gjp2'] ?? $input['gjp'] ?? '')
            : (string)($input['gjp'] ?? $input['gjp2'] ?? '');

        if ($credential === '') {
            return 0;
        }

        try {
            $this->auth->authenticate($accountId, $credential);
            return $accountId;
        } catch (\Throwable) {
            return 0;
        }
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
