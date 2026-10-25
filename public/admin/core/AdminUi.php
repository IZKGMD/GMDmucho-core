<?php
declare(strict_types=1);

/** Shared, bounded list state. Only allowlisted query fields survive a POST. */
function adminListState(array $input): array
{
    return [
        'q' => mb_substr(trim((string)($input['q'] ?? '')), 0, 128, 'UTF-8'),
        'filter' => (string)($input['filter'] ?? ''),
        'p' => max(1, min(100000, (int)($input['p'] ?? 1))),
    ];
}

function adminListReturnFields(array $state): void
{
    foreach (['q','filter','p'] as $key) {
        echo '<input type="hidden" name="return_'.$key.'" value="'.h($state[$key] ?? '').'">';
    }
}

function adminListUrl(string $page, array $state, int $number): string
{
    return '/admin/?'.http_build_query(['page'=>$page, 'q'=>$state['q'], 'filter'=>$state['filter'], 'p'=>$number]);
}

function adminListPager(string $page, array $state, bool $hasMore): void
{
    echo '<nav class="admin-pagination" aria-label="Pagination">';
    if ($state['p'] > 1) echo '<a class="btn gray" href="'.h(adminListUrl($page,$state,$state['p']-1)).'">Previous</a>';
    echo '<span class="muted">'.h((string)$state['p']).'</span>';
    if ($hasMore) echo '<a class="btn gray" href="'.h(adminListUrl($page,$state,$state['p']+1)).'">Next</a>';
    echo '</nav>';
}

function adminGdIcon(string $name, string $class = 'gd-icon'): string
{
    // Callers use fixed, source-controlled names, never filesystem paths.
    if (!preg_match('/^[a-z0-9_-]+$/D', $name)) $name='unrated';
    return '<img class="'.h($class).'" src="/admin/assets/gd/'.h($name).'.png" width="32" height="32" alt="" aria-hidden="true" decoding="async">';
}

function adminDifficultyProfile(array $row): string
{
    if ((int)($row['auto_level'] ?? 0) === 1) return 'auto';
    if ((int)($row['demon'] ?? 0) === 1) {
        return match ((int)($row['demon_difficulty'] ?? 0)) {
            1=>'easy-demon', 2=>'medium-demon', 4=>'insane-demon',
            5=>'extreme-demon', default=>'hard-demon',
        };
    }
    return match ((int)($row['difficulty'] ?? 0)) {
        1=>'auto', 2=>'easy', 3=>'normal', 4=>'hard', 5=>'harder', 6=>'insane', default=>'unrated',
    };
}
