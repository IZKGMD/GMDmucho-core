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

        $offset = $page * self::PAGE_SIZE;

        $result = $this->levels->search(
            type: $type,
            search: $search,
            gameVersion: $gameVersion,
            offset: $offset,
            limit: self::PAGE_SIZE,
            demonFilter: $demonFilter,
        );

        if (empty($result['levels'])) { return '-2'; } return $this->encoder->encode(
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
