<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Dashboard
 * Copyright (C) 2026 IZK
 */

function renderAdminDashboard(PDO $db): void
{
    $count = static function (PDO $db, string $table): int {
        try {
            return countTable($db, $table);
        } catch (Throwable) {
            return 0;
        }
    };

    $accounts = $count($db, 'accounts');
    $levels = $count($db, 'levels');
    $songs = $count($db, 'songs');
    $verifiedSongs = 0;
    $pendingSongs = 0;

    try {
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

    $todayAccounts = 0;
    $todaySongs = 0;
    try {
        $q = $db->query(
            'SELECT COUNT(*) FROM accounts
             WHERE created_at >= CURRENT_DATE'
        );
        $todayAccounts = (int)$q->fetchColumn();
    } catch (Throwable) {
    }

    try {
        $q = $db->query(
            'SELECT COUNT(*) FROM songs
             WHERE created_at >= CURRENT_DATE'
        );
        $todaySongs = (int)$q->fetchColumn();
    } catch (Throwable) {
    }

    $recentPlayers = [];
    try {
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
    try {
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

    echo '
    <section class="admin-hero">
        <div>
            <span class="hero-eyebrow">MUCHOCORE CONTROL CENTER</span>
            <h2>Everything important, one screen.</h2>
            <p>Monitor the server, moderate community content and jump straight into the player portal.</p>
            <div class="hero-actions">
                <a class="btn" href="/dashboard" target="_blank" rel="noopener">Open Player Portal ↗</a>
                <a class="btn gray" href="/admin/?page=songs">Moderate Music</a>
                <a class="btn gray" href="/admin/?page=players">Manage Players</a>
            </div>
        </div>
        <div class="hero-orb">
            <div class="hero-orb-core">M</div>
        </div>
    </section>
    ';

    echo '<div class="admin-kpi-grid">';

    $kpis = [
        ['Players', $accounts, 'Today +' . $todayAccounts, 'violet'],
        ['Levels', $levels, 'Published content', 'blue'],
        ['Music', $songs, $verifiedSongs . ' verified', 'green'],
        ['Pending Music', $pendingSongs, $todaySongs . ' uploaded today', $pendingSongs > 0 ? 'amber' : 'green'],
    ];

    foreach ($kpis as [$label, $value, $meta, $tone]) {
        echo '<div class="admin-kpi ' . h((string)$tone) . '">';
        echo '<div class="admin-kpi-label">' . h((string)$label) . '</div>';
        echo '<div class="admin-kpi-value">' . number_format((int)$value) . '</div>';
        echo '<div class="admin-kpi-meta">' . h((string)$meta) . '</div>';
        echo '</div>';
    }

    echo '</div>';

    echo '
    <div class="quick-grid admin-quick-grid">
        <a class="quick-link" href="/admin/?page=players">
            <b>Players</b><small>Accounts, bans, roles and statistics</small>
        </a>
        <a class="quick-link" href="/admin/?page=songs">
            <b>Music Queue</b><small>Review pending uploads and verified tracks</small>
        </a>
        <a class="quick-link" href="/admin/?page=moderation">
            <b>Moderation</b><small>Levels, ratings and community review</small>
        </a>
        <a class="quick-link" href="/admin/?page=endpoints">
            <b>API Tester</b><small>Probe Geometry Dash-compatible endpoints</small>
        </a>
        <a class="quick-link" href="/admin/?page=dbbackups">
            <b>DB Backup Center</b><small>Snapshots and recovery workflow</small>
        </a>
        <a class="quick-link" href="/admin/?page=securitycenter">
            <b>Security Center</b><small>Requests, alerts and operational health</small>
        </a>
    </div>
    ';

    echo '<div class="admin-dashboard-columns">';

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

    echo '</div>';

    $liveAuditHtml = <<<'HTML'
<section class="card admin-live-audit-card">
    <div class="section-heading">
        <div>
            <h2>Live audit feed</h2>
            <small>Latest administrator events · refreshes every 5 seconds</small>
        </div>
        <span class="badge green" id="auditLiveState">LIVE</span>
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
<script>
(() => {
    const root=document.getElementById('muchoLiveAudit');
    const state=document.getElementById('auditLiveState');
    if(!root) return;

    const esc=(value) => String(value ?? '').replace(/[&<>"]/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'
    }[c]));

    async function refreshAudit(){
        try{
            const response=await fetch('/admin/?audit_feed=1&t='+Date.now(),{
                credentials:'same-origin',
                cache:'no-store',
                headers:{'Accept':'application/json'}
            });

            if(!response.ok) throw new Error('feed');
            const data=await response.json();
            const events=Array.isArray(data.events) ? data.events : [];

            state.textContent='LIVE';
            state.classList.add('green');

            root.innerHTML=events.length
                ? events.map(item => \`
                    <div class="admin-live-audit-row">
                        <small>\${esc(item.created_at)}</small>
                        <b>\${esc(item.username)}</b>
                        <span class="admin-live-audit-action">\${esc(item.action)}</span>
                        <span class="admin-live-audit-target">\${esc(item.target || '—')}</span>
                    </div>
                \`).join('')
                : '<div class="empty-state">No administrator events yet.</div>';
        }catch{
            state.textContent='OFFLINE';
            state.classList.remove('green');
        }
    }

    refreshAudit();
    setInterval(refreshAudit,5000);
})();
</script>
HTML;

    echo $liveAuditHtml;

    echo '<section class="card admin-server-card">';
    echo '<div class="section-heading"><div><h2>Server status</h2><small>Quick operational snapshot</small></div><a href="/admin/?page=monitoring">Monitoring →</a></div>';

    try {
        $status = rootOp('status');
    } catch (Throwable $e) {
        $status = 'Failed to get status: ' . $e->getMessage();
    }

    echo '<pre class="admin-status-pre">' . h($status) . '</pre>';
    echo '</section>';
}
