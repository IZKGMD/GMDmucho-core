<?php
declare(strict_types=1);

function renderIntelligenceScalePage(PDO $db): void
{
    $tables = [
        'Search index' => 'mucho_level_search_index',
        'Level revisions' => 'mucho_level_revisions',
        'Background jobs' => 'mucho_jobs',
        'Cache records' => 'mucho_cache',
        'Backup verifications' => 'mucho_backup_verifications',
    ];

    echo '<div class="grid">';

    foreach ($tables as $label => $table) {
        $count = 0;
        try {
            $count = countTable($db, $table);
        } catch (Throwable) {
        }

        echo '<div class="card">';
        echo '<small>'.h($label).'</small>';
        echo '<div class="value">'.number_format($count).'</div>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="card" style="margin-top:14px">';
    echo '<h2 style="margin-top:0">Level Intelligence</h2>';
    echo '<p class="muted" style="line-height:1.6">
        Every supported level write can be validated, fingerprinted, indexed and
        revisioned. Search indexing is derived data and can be rebuilt safely.
    </p>';

    $coverage = [
        'levels_total' => 0,
        'indexed' => 0,
        'visible_unindexed' => 0,
    ];

    try {
        $coverage['levels_total'] = (int)$db->query(
            'SELECT COUNT(*) FROM levels'
        )->fetchColumn();

        $coverage['indexed'] = (int)$db->query(
            'SELECT COUNT(*)
             FROM mucho_level_search_index
             WHERE is_deleted=0 AND is_unlisted=0'
        )->fetchColumn();

        $coverage['visible_unindexed'] = (int)$db->query(
            'SELECT COUNT(*)
             FROM levels l
             LEFT JOIN mucho_level_search_index si
               ON si.level_id=l.level_id
             WHERE l.is_deleted=0
               AND l.is_unlisted=0
               AND si.level_id IS NULL'
        )->fetchColumn();
    } catch (Throwable) {
    }

    echo '<div class="grid">';
    echo '<div><small>All levels</small><div class="value">'.number_format($coverage['levels_total']).'</div></div>';
    echo '<div><small>Indexed & visible</small><div class="value">'.number_format($coverage['indexed']).'</div></div>';
    echo '<div><small>Visible but not indexed</small><div class="value">'.number_format($coverage['visible_unindexed']).'</div></div>';
    echo '</div>';

    $coverageClass = $coverage['visible_unindexed'] === 0 ? 'ok' : 'warning';
    $coverageText = $coverage['visible_unindexed'] === 0
        ? 'Search index is fully covering visible levels.'
        : 'Some visible levels are not indexed yet. Run the rebuild command from the operator CLI.';

    echo '<div class="'.$coverageClass.'" style="margin-top:13px">';
    echo h($coverageText);
    echo '</div>';
    echo '</div>';

    echo '<div class="grid" style="margin-top:14px">';

    $jobStats = [
        'queued' => 0,
        'running' => 0,
        'failed' => 0,
    ];

    try {
        $rows = $db->query(
            "SELECT status,COUNT(*) AS c
             FROM mucho_jobs
             WHERE status IN ('queued','running','failed')
             GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $status = (string)$row['status'];
            if (isset($jobStats[$status])) {
                $jobStats[$status] = (int)$row['c'];
            }
        }
    } catch (Throwable) {
    }

    foreach ($jobStats as $status => $count) {
        echo '<div class="card">';
        echo '<small>Jobs '.h(ucfirst($status)).'</small>';
        echo '<div class="value">'.number_format($count).'</div>';
        echo '</div>';
    }

    echo '</div>';

    echo '<div class="card" style="margin-top:14px">';
    echo '<h2 style="margin-top:0">Operator commands</h2>';
    echo '<pre>sudo ./bin/mucho search rebuild
sudo ./bin/mucho test
sudo ./bin/mucho backup-verify
sudo ./bin/mucho worker --loop
sudo ./bin/mucho trace inspect /path/to/client-trace.ndjson
sudo ./bin/mucho level validate LEVEL_ID
sudo ./bin/mucho level revisions LEVEL_ID</pre>';
    echo '<p class="muted" style="margin-bottom:0;line-height:1.5">
        The worker is safe to leave enabled. Cache failures and background job
        failures are isolated from Geometry Dash protocol responses.
    </p>';
    echo '</div>';
}
