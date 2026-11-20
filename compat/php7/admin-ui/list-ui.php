<?php
declare(strict_types=1);

/** Shared, bounded list state. Only allowlisted query fields survive a POST. */
function adminListState(array $input): array
{
    $query = $input['q'] ?? '';
    $filter = $input['filter'] ?? '';
    $page = $input['p'] ?? 1;
    return [
        'q' => is_string($query) ? mucho7_ui_substr(trim($query), 0, 128, 'UTF-8') : '',
        'filter' => is_string($filter) ? $filter : '',
        'p' => max(1, min(100000, adminRecordId($page) ?? 1)),
    ];
}

function adminRecordId($value)
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function adminListReturnFields(array $state)
{
    foreach (['q','filter','p'] as $key) {
        echo '<input type="hidden" name="return_'.$key.'" value="'.h($state[$key] ?? '').'">';
    }
}

function adminListUrl(string $page, array $state, int $number): string
{
    return '/admin/?'.http_build_query(['page'=>$page, 'q'=>$state['q'], 'filter'=>$state['filter'], 'p'=>$number]);
}

function adminListPager(string $page, array $state, bool $hasMore)
{
    // A single page has no navigation. Do not display an orphaned "1".
    if ((int)$state['p'] === 1 && !$hasMore) return;
    echo '<nav class="admin-pagination" aria-label="Pagination">';
    if ($state['p'] > 1) echo '<a class="btn gray" href="'.h(adminListUrl($page,$state,$state['p']-1)).'">Previous</a>';
    echo '<span class="muted" aria-current="page">Page '.h((string)$state['p']).'</span>';
    if ($hasMore) echo '<a class="btn gray" href="'.h(adminListUrl($page,$state,$state['p']+1)).'">Next</a>';
    echo '</nav>';
}

function adminGdIcon(string $name, string $class = 'gd-icon'): string
{
    // Callers use fixed, source-controlled names, never filesystem paths.
    if (!preg_match('/^[a-z0-9_-]+$/D', $name)) $name='unrated';
    return '<img class="'.h($class).'" src="/admin/assets/gd/'.h($name).'.png" width="32" height="32" alt="" aria-hidden="true" decoding="async">';
}
