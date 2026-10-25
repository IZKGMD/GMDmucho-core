<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Dashboard
 * Copyright (C) 2026 IZK
 */

require_once __DIR__.'/../core/AdminUi.php';

function renderAdminDashboard(PDO $db): void
{
    $count = static function (PDO $db, string $table): ?int {
        try {
            return countTable($db, $table);
        } catch (Throwable) {
            return null;
        }
    };

    $accounts = canAdminPage('players') ? $count($db, 'accounts') : null;
    $levels = canAdminPage('levels') ? $count($db, 'levels') : null;
    $songs = canAdminPage('songs') ? $count($db, 'songs') : null;
    $verifiedSongs = null;
    $pendingSongs = null;

    if (canAdminPage('songs')) try {
        $q = $db->query(
            'SELECT
                COALESCE(SUM(CASE WHEN is_verified=1 THEN 1 ELSE 0 END),0) AS verified,
                COALESCE(SUM(CASE WHEN is_verified=0 THEN 1 ELSE 0 END),0) AS pending
             FROM songs'
        );
        $songStats = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $verifiedSongs = (int)($songStats['verified'] ?? 0);
        $pendingSongs = (int)($songStats['pending'] ?? 0);
    } catch (Throwable) {
    }

    $todayAccounts = null;
    $todaySongs = null;
    if (canAdminPage('players')) try {
        $q = $db->query(
            'SELECT COUNT(*) FROM accounts
             WHERE created_at >= CURRENT_DATE'
        );
        $todayAccounts = (int)$q->fetchColumn();
    } catch (Throwable) {
    }

    if (canAdminPage('songs')) try {
        $q = $db->query(
            'SELECT COUNT(*) FROM songs
             WHERE created_at >= CURRENT_DATE'
        );
        $todaySongs = (int)$q->fetchColumn();
    } catch (Throwable) {
    }

    $recentPlayers = [];
    if (canAdminPage('players')) try {
        $recentPlayers = $db->query(
            'SELECT
                a.account_id,
                a.username,
                a.created_at,
                COALESCE(r.code, "user") AS role_code,
                COALESCE(p.stars,0) AS stars,
                COALESCE(p.moons,0) AS moons
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             LEFT JOIN profiles p ON p.account_id = a.account_id
             ORDER BY a.account_id DESC
             LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
    }

    $recentSongs = [];
    if (canAdminPage('songs')) try {
        $recentSongs = $db->query(
            'SELECT
                s.id,
                s.name,
                s.author_name,
                s.size,
                s.is_verified,
                s.created_at
             FROM songs s
             ORDER BY s.id DESC
             LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
    }

    echo '<div class="admin-overview-heading"><h2>Server overview</h2><a class="btn gray" href="/dashboard" target="_blank" rel="noopener">Open Player Portal ↗</a></div>';

    // The most common operator tasks belong one click away. These are links,
    // not side-effecting actions, and never bypass normal admin permissions.
    $quickActions = [
        ['rating', 'Review levels', 'Rate and moderate submitted levels'],
        ['news', 'Publish news', 'Create an in-game announcement'],
        ['clientpatcher', 'Patch clients', 'Prepare GDPS Windows and Android clients'],
        ['dbbackups', 'Back up database', 'Create or restore a verified backup'],
        ['settings', 'Server settings', 'Configure the GDPS'],
    ];
    $visibleActions = array_values(array_filter(
        $quickActions,
        static fn(array $action): bool => canAdminPage($action[0])
    ));
    if ($visibleActions) {
        echo '<section class="admin-quick-section" aria-labelledby="adminQuickHeading">';
        echo '<div class="section-heading"><div><h2 id="adminQuickHeading">Quick actions</h2><small>Jump to a task without searching through menus</small></div></div>';
        echo '<div class="admin-quick-actions">';
        foreach ($visibleActions as [$section, $label, $description]) {
            echo '<a class="admin-quick-action" href="/admin/?page='.h($section).'">';
            echo '<strong>'.h($label).'</strong><small>'.h($description).'</small>';
            echo '</a>';
        }
        echo '</div></section>';
    }

    echo '<div class="admin-kpi-grid">';

    $kpis = [
        ['Players', $accounts, ($todayAccounts === null ? 'Daily data unavailable' : 'Today +' . $todayAccounts), 'violet', 'players'],
        ['Levels', $levels, 'Published content', 'blue', 'levels'],
        ['Music', $songs, ($verifiedSongs === null ? 'Summary unavailable' : $verifiedSongs . ' verified'), 'green', 'songs'],
        ['Pending Music', $pendingSongs, ($todaySongs === null ? 'Daily data unavailable' : $todaySongs . ' uploaded today'), $pendingSongs > 0 ? 'amber' : 'green', 'songs'],
    ];

    foreach ($kpis as [$label, $value, $meta, $tone, $section]) {
        if (!canAdminPage($section)) continue;
        echo '<a class="admin-kpi ' . h((string)$tone) . '" href="/admin/?page='.h($section).($label==='Pending Music'?'&amp;filter=pending':'').'">';
        echo adminGdIcon($section);
        echo '<div class="admin-kpi-label">' . h((string)$label) . '</div>';
        echo '<div class="admin-kpi-value">' . ($value === null ? '—' : number_format((int)$value)) . '</div>';
        echo '<div class="admin-kpi-meta">' . h((string)$meta) . '</div>';
        echo '</a>';
    }

    echo '</div>';

    echo '<div class="admin-dashboard-columns">';

    if (canAdminPage('players')) {
        echo '<section class="card admin-feed-card">';
        echo '<div class="section-heading"><div><h2>Latest players</h2><small>Newest account registrations</small></div><a href="/admin/?page=players">View all →</a></div>';

        if ($recentPlayers) {
            echo '<div class="admin-player-feed">';

            foreach ($recentPlayers as $player) {
                $role = str_replace('_', ' ', (string)($player['role_code'] ?? 'user'));
                echo '<a class="admin-player-row" href="/admin/?page=players&q=' . rawurlencode((string)$player['username']) . '">';
                echo '<div class="admin-player-avatar">' . h(mb_strtoupper(mb_substr((string)($player['username'] ?? '?'), 0, 1, 'UTF-8'), 'UTF-8')) . '</div>';
                echo '<div class="admin-player-main">';
                echo '<b>' . h($player['username'] ?? '') . '</b>';
                echo '<small>#' . h($player['account_id'] ?? '') . ' · ' . h(ucfirst($role)) . '</small>';
                echo '</div>';
                echo '<div class="admin-player-stats">';
                echo '<span>' . number_format((int)($player['stars'] ?? 0)) . ' ★</span>';
                echo '<span>' . number_format((int)($player['moons'] ?? 0)) . ' ◇</span>';
                echo '</div>';
                echo '</a>';
            }

            echo '</div>';
        } else {
            echo '<div class="empty-state">No player data available.</div>';
        }

        echo '</section>';

    }
    if (canAdminPage('songs')) {
        echo '<section class="card admin-feed-card">';
        echo '<div class="section-heading"><div><h2>Music activity</h2><small>Latest uploads from players</small></div><a href="/admin/?page=songs">Open queue →</a></div>';

        if ($recentSongs) {
            echo '<div class="admin-song-feed">';

            foreach ($recentSongs as $song) {
                $verified = (int)($song['is_verified'] ?? 0) === 1;
                echo '<div class="admin-song-row">';
                echo '<div class="song-wave"><span></span><span></span><span></span><span></span></div>';
                echo '<div class="admin-song-main">';
                echo '<b>' . h($song['name'] ?? '') . '</b>';
                echo '<small>' . h($song['author_name'] ?? '') . ' · #' . h($song['id'] ?? '') . ' · ' . h($song['size'] ?? '0') . ' MB</small>';
                echo '</div>';
                echo '<span class="badge ' . ($verified ? 'green' : '') . '">' . ($verified ? 'Verified' : 'Pending') . '</span>';
                echo '</div>';
            }

            echo '</div>';
        } else {
            echo '<div class="empty-state">No songs uploaded yet.</div>';
        }

        echo '</section>';

    }
    echo '</div>';

    if (canPermission('audit.view')) {
        $liveAuditHtml = <<<'HTML'
<details class="card admin-live-audit-card" id="auditDetails"><summary>Audit Log</summary>
        <div class="section-heading">
            <div>
                <h2>Live audit feed</h2>
                <small>Latest administrator events · refreshes every 15 seconds</small>
            </div>
            <span class="badge" id="auditLiveState" role="status">Loading…</span>
        </div>
        <div id="muchoLiveAudit" class="admin-live-audit-list">
            <div class="empty-state">Loading audit events…</div>
        </div>
</details>
<style>
    .admin-live-audit-list{display:grid;gap:8px}
    .admin-live-audit-row{display:grid;grid-template-columns:150px 110px 1fr auto;gap:10px;align-items:center;padding:10px 11px;border:1px solid #202a39;background:#0b1018;border-radius:9px}
    .admin-live-audit-row b{font-size:12px}
    .admin-live-audit-row small{font-size:10px;color:#7f8ca0}
    .admin-live-audit-action{font:700 11px ui-monospace,SFMono-Regular,Menlo,monospace;color:#b9c4d6}
    .admin-live-audit-target{font-size:11px;color:#dbe2ef;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        @media(max-width:760px){
            .admin-live-audit-row{grid-template-columns:1fr auto}
            .admin-live-audit-action,.admin-live-audit-target{grid-column:1 / -1}
    }
</style>
<script src="/admin/product-dashboard.js?v=2" defer></script>
HTML;

        echo $liveAuditHtml;
    }

}
