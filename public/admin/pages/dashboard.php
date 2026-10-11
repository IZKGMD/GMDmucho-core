<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Dashboard
 * Copyright (C) 2026 IZK
 */

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
             LIMIT 8'
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
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
    }

    if (canPermission('system.manage')) {
            try {
                $releaseStatus = \MuchoCore\Release\ReleaseService::check(
                    defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3),
                    defined('CONTROL_DIR') ? CONTROL_DIR : dirname(__DIR__, 3) . '/storage/control'
                );

                if (!empty($releaseStatus['update_available'])) {
                    $latest = h((string)($releaseStatus['latest_version'] ?? ''));
                    $current = h((string)($releaseStatus['current_version'] ?? ''));
                    echo '<section class="card" style="margin-bottom:13px;border-color:#5e4fd8;background:linear-gradient(135deg,#121529,#10141f)">';
                    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">';
                    echo '<div><span style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#9a8eff">MUCHOCORE UPDATE</span>';
                    echo '<h2 style="margin:6px 0 4px">New stable core release detected</h2>';
                    echo '<p class="muted" style="margin:0">Installed v'.$current.' · Stable release <b style="color:#eef2ff">v'.$latest.'</b> will be applied automatically.</p></div>';
                    echo '<a class="btn" href="/admin/?page=updates">View release →</a>';
                    echo '</div>';
                    echo '</section>';
                }
            } catch (Throwable) {
                // Release detection is advisory and must never break the dashboard.
            }
    }

    echo '<section class="admin-hero"><div><span class="hero-eyebrow">MUCHO / CONTROL CENTER</span>';
    echo '<h2>Everything important, one screen.</h2><p>Monitor the server, review content and keep your community moving.</p><div class="hero-actions">';
    echo '<a class="btn" href="/dashboard" target="_blank" rel="noopener">Open Player Portal ↗</a>';
    if (canAdminPage('songs')) echo '<a class="btn gray" href="/admin/?page=songs">Moderate Music</a>';
    if (canAdminPage('players')) echo '<a class="btn gray" href="/admin/?page=players">Manage Players</a>';
    echo '</div></div></section>';

    echo '<div class="admin-kpi-grid">';

    $kpis = [
        ['Players', $accounts, ($todayAccounts === null ? 'Daily data unavailable' : 'Today +' . $todayAccounts), 'violet', 'players'],
        ['Levels', $levels, 'Published content', 'blue', 'levels'],
        ['Music', $songs, ($verifiedSongs === null ? 'Summary unavailable' : $verifiedSongs . ' verified'), 'green', 'songs'],
        ['Pending Music', $pendingSongs, ($todaySongs === null ? 'Daily data unavailable' : $todaySongs . ' uploaded today'), $pendingSongs > 0 ? 'amber' : 'green', 'songs'],
    ];

    foreach ($kpis as [$label, $value, $meta, $tone, $section]) {
        if (!canAdminPage($section)) continue;
        echo '<div class="admin-kpi ' . h((string)$tone) . '">';
        echo '<div class="admin-kpi-label">' . h((string)$label) . '</div>';
        echo '<div class="admin-kpi-value">' . ($value === null ? '—' : number_format((int)$value)) . '</div>';
        echo '<div class="admin-kpi-meta">' . h((string)$meta) . '</div>';
        echo '</div>';
    }

    echo '</div>';

    $quickLinks = [
        ['players', 'Players', 'Accounts, bans, roles and statistics'],
        ['songs', 'Music Queue', 'Review pending uploads and verified tracks'],
        ['moderation', 'Moderation', 'Levels, ratings and community review'],
        ['rating', 'Rating Studio', 'Review and rate community creations'],
        ['dbbackups', 'DB Backup Center', 'Snapshots and recovery workflow'],
        ['securitycenter', 'Security Center', 'Requests, alerts and operational health'],
    ];
    echo '<div class="quick-grid admin-quick-grid">';
    foreach ($quickLinks as [$section, $title, $description]) {
        if (!canAdminPage($section)) continue;
        echo '<a class="quick-link" href="/admin/?page=' . h($section) . '"><b>' . h($title) . '</b><small>' . h($description) . '</small></a>';
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
                echo '<a class="admin-player-row" href="/admin/?page=players&search=' . rawurlencode((string)$player['username']) . '">';
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
<section class="card admin-live-audit-card">
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
</section>
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
<script src="/admin/product-dashboard.js?v=1" defer></script>
HTML;

        echo $liveAuditHtml;
    }

    if (canPermission('system.manage')) {
        echo '<section class="card admin-server-card">';
        echo '<div class="section-heading"><div><h2>Server status</h2><small>Quick operational snapshot</small></div>';
        if (canAdminPage('monitoring')) echo '<a href="/admin/?page=monitoring">Monitoring →</a>';
        echo '</div>';

        try {
            $status = rootOp('status');
        } catch (Throwable $e) {
            $status = 'Failed to get status: ' . $e->getMessage();
        }

        echo '<pre class="admin-status-pre">' . h($status) . '</pre>';
        echo '</section>';
    }
}
