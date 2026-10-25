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

/** Source-controlled outline icons; difficulty faces remain native GD assets. */
function adminNavIcon(string $name): string
{
    $paths = [
        'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'players'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'levels'=>'<path d="m12 3 10 5-10 5L2 8l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/>',
        'rating'=>'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
        'clans'=>'<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'songs'=>'<path d="M9 18V5l12-2v13M9 8l12-2"/><ellipse cx="6" cy="18" rx="3" ry="3"/><ellipse cx="18" cy="16" rx="3" ry="3"/>',
        'news'=>'<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M7 8h10M7 12h4m4 0h2M7 16h10"/>',
        'clients'=>'<path d="M8 3H5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-3M8 22h8m-4-4v4m0-20v10m-4-4 4 4 4-4"/>',
        'settings'=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
        'plugins'=>'<path d="M9 3h6v5h5v6h-5v7H9v-7H4V8h5V3Z"/>',
        'comments'=>'<path d="M21 14a3 3 0 0 1-3 3H8l-5 4V6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8Z"/><path d="M7 8h10M7 12h6"/>',
        'messages'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'analytics'=>'<path d="M4 3v18h17M8 16v-4m5 4V8m5 8V5"/>',
        'database'=>'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        'endpoints'=>'<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18"/>',
        'system'=>'<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M11 6.5h6M11 17.5h6"/>',
        'admins'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'audit'=>'<path d="M8 3h8v4H8zM8 5H5v16h14V5h-3M8 12h8m-8 4h5"/>',
        'logout'=>'<path d="M9 4H4v16h5m6-13 5 5-5 5M8 12h12"/>',
    ];
    $aliases=['clientpatcher'=>'clients','downloads'=>'clients','moderation'=>'clans','roles'=>'clans','securitycenter'=>'clans','social'=>'players','muchoprofiles'=>'players','contentpacks'=>'levels','monitoring'=>'analytics','intelligence'=>'analytics','dbbackups'=>'database','backups'=>'database','migration'=>'database','advanced'=>'settings','clientfeatures'=>'settings','updates'=>'system'];
    if (isset($aliases[$name])) $name=$aliases[$name];
    $path=$paths[$name] ?? $paths['plugins'];
    return '<svg class="mc-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$path.'</svg>';
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
