<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Protocol\GdLevelListEncoder;

final readonly class LevelService
{
    private const PAGE_SIZE = 10;

    public function __construct(
        private LevelRepository $levels,
        private GdLevelListEncoder $encoder,
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
