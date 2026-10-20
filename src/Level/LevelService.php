<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Cache\CacheInterface;
use MuchoCore\Diagnostics\RequestProfiler;
use MuchoCore\Protocol\GdLevelListEncoder;

final readonly class LevelService
{
    private const PAGE_SIZE = 10;

    public function __construct(
        private LevelRepository $levels,
        private GdLevelListEncoder $encoder,
        private CacheInterface $cache,
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

        $binaryVersion = max(
            0,
            $this->integer($input['binaryVersion'] ?? 0)
        );

        $version = \MuchoCore\Compatibility\ClientVersion::fromValues(
            $gameVersion,
            $binaryVersion
        );
        $gameVersion = $version->effectiveGameVersion();

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

        $cacheable = $this->cacheable($type, $input);
        $cacheKey = '';

        if ($cacheable) {
            // Only inputs inspected by LevelRepository affect a public list.
            // Client-specific transport fields (UDID, UUID, GJP, etc.) must
            // not splinter the cache or cause identical queries to hit SQL.
            // Keep the wire-version separate: 2.209 includes timestamp fields.
            $cacheInput = [];
            foreach ([
                'diff', 'len', 'original', 'coins', 'twoPlayer', 'star',
                'noStar', 'featured', 'epic', 'mythic', 'legendary',
                'song', 'customSong', 'uncompleted', 'onlyCompleted',
                'gauntlet',
            ] as $key) {
                if (isset($input[$key]) && is_scalar($input[$key])) {
                    $cacheInput[$key] = (string)$input[$key];
                }
            }
            $cacheKey = 'mucho:levels:' . hash(
                'sha256',
                json_encode([
                    'type' => $type,
                    'page' => $page,
                    'game_version' => $gameVersion,
                    'binary_version' => $binaryVersion,
                    'demon_filter' => $demonFilter,
                    'search' => $search,
                    'input' => $cacheInput,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            );
            $cacheStartedAt = RequestProfiler::clock();
            $cached = $this->cache->get($cacheKey);
            RequestProfiler::observe('cache_read', $cacheStartedAt);
            RequestProfiler::cacheHit($cached !== null);
            if ($cached !== null) {
                return $cached;
            }
        }

        $searchStartedAt = RequestProfiler::clock();
        $result = $this->levels->search(
            type: $type,
            search: $search,
            gameVersion: $gameVersion,
            offset: $offset,
            limit: self::PAGE_SIZE,
            demonFilter: $demonFilter,
            input: $input,
        );
        RequestProfiler::observe('search', $searchStartedAt);

        $encodeStartedAt = RequestProfiler::clock();
        if (empty($result['levels'])) {
            $encoded = '-2';
        } else {
            $encoded = $this->encoder->encode(
                levels: $result['levels'],
                total: $result['total'],
                offset: $offset,
                limit: self::PAGE_SIZE,
                gameVersion: $gameVersion,
                binaryVersion: $binaryVersion,
            );
        }

        RequestProfiler::observe('encode', $encodeStartedAt);

        if ($cacheable && $cacheKey !== '') {
            $ttl = max(1, min(300, (int)(getenv('MUCHO_LEVEL_CACHE_TTL') ?: 15)));
            $writeStartedAt = RequestProfiler::clock();
            $this->cache->set($cacheKey, $encoded, $ttl);
            RequestProfiler::observe('cache_write', $writeStartedAt);
        }

        return $encoded;
    }

    private function cacheable(int $type, array $input): bool
    {
        if (in_array($type, [5, 12, 13, 25, 26, 27], true)) {
            return false;
        }

        foreach (['accountID','followed','completedLevels','gauntlet'] as $key) {
            if (isset($input[$key]) && (string)$input[$key] !== '' && (string)$input[$key] !== '0') {
                return false;
            }
        }

        return in_array(
            $type,
            [0, 1, 2, 3, 6, 11, 15, 16, 17, 21, 22, 23],
            true
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
