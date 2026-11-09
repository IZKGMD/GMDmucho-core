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

    $kpis = [
        ['Players', $accounts, ($todayAccounts === null ? 'Today unavailable' : '+' . $todayAccounts . ' today'), 'players'],
        ['Levels', $levels, 'Published levels', 'levels'],
        ['Songs', $songs, ($verifiedSongs === null ? 'Verification unavailable' : $verifiedSongs . ' verified'), 'songs'],
    ];
    $actions = [
        ['rating', 'Review levels', 'Ratings and moderation'],
        ['news', 'Publish news', 'In-game announcements'],
        ['clientpatcher', 'Patch a client', 'Windows and Android'],
        ['dbbackups', 'Backups', 'Database snapshots'],
        ['settings', 'Server settings', 'Configuration and branding'],
    ];
    $visibleActions = array_values(array_filter($actions, static fn (array $action): bool => canAdminPage($action[0])));
    ob_start();
    ?>
    <div class="mc-ops">
        <header class="mc-ops-intro">
            <div>
                <p class="mc-ops-kicker">OPERATIONS / DASHBOARD</p>
                <h2>Server overview</h2>
                <p>Live data and the work that needs your attention.</p>
            </div>
            <a class="mc-ops-secondary" href="/dashboard" target="_blank" rel="noopener noreferrer">Player portal <span aria-hidden="true">↗</span></a>
        </header>

        <?php if (canAdminPage('songs')): ?>
        <a class="mc-ops-attention" href="/admin/?page=songs&amp;filter=pending" aria-label="Review pending music">
            <span class="mc-ops-attention-mark" aria-hidden="true">♪</span>
            <span class="mc-ops-attention-copy">
                <small>MUSIC MODERATION</small>
                <strong><?= $pendingSongs === null ? 'Queue unavailable' : ($pendingSongs === 0 ? 'No tracks waiting for review' : number_format($pendingSongs) . ' tracks waiting for review') ?></strong>
                <span><?= $todaySongs === null ? 'Daily upload data unavailable' : h((string)$todaySongs) . ' uploaded today' ?></span>
            </span>
            <span class="mc-ops-attention-go">Open queue ↗</span>
        </a>
        <?php endif; ?>

        <section class="mc-ops-summary" aria-label="Current server totals">
            <?php foreach ($kpis as [$label, $value, $note, $section]): ?>
                <?php if (!canAdminPage($section)) continue; ?>
                <a class="mc-ops-summary-item" href="/admin/?page=<?=h($section)?>">
                    <span class="mc-ops-summary-top"><?=h($label)?><span aria-hidden="true">↗</span></span>
                    <strong><?= $value === null ? '—' : number_format((int)$value) ?></strong>
                    <small><?=h((string)$note)?></small>
                </a>
            <?php endforeach; ?>
        </section>

        <div class="mc-ops-content">
            <div class="mc-ops-feeds">
                <?php if (canAdminPage('players')): ?>
                <section class="mc-ops-panel" aria-labelledby="mcRecentPlayers">
                    <div class="mc-ops-section-head">
                        <div><p class="mc-ops-kicker">COMMUNITY</p><h3 id="mcRecentPlayers">New players</h3></div>
                        <a href="/admin/?page=players">All players ↗</a>
                    </div>
                    <?php if ($recentPlayers): ?>
                    <div class="mc-ops-table-scroll">
                        <table class="mc-ops-table">
                            <thead><tr><th>Player</th><th>Role</th><th class="mc-ops-num">Stars</th><th class="mc-ops-num">Moons</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentPlayers as $player): ?>
                            <tr>
                                <td>
                                    <a class="mc-ops-player" href="/admin/?page=players&amp;q=<?=rawurlencode((string)$player['username'])?>">
                                        <span class="mc-ops-initial" aria-hidden="true"><?=h(mb_strtoupper(mb_substr((string)($player['username'] ?? '?'),0,1,'UTF-8'),'UTF-8'))?></span>
                                        <span><strong><?=h($player['username'] ?? '')?></strong><small>Account #<?=h($player['account_id'] ?? '')?></small></span>
                                    </a>
                                </td>
                                <td><span class="mc-ops-role"><?=h(str_replace('_',' ',(string)($player['role_code'] ?? 'user')))?></span></td>
                                <td class="mc-ops-num"><?=number_format((int)($player['stars'] ?? 0))?></td>
                                <td class="mc-ops-num"><?=number_format((int)($player['moons'] ?? 0))?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><p class="mc-ops-empty">No registrations yet. New players will appear here.</p><?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if (canAdminPage('songs')): ?>
                <section class="mc-ops-panel" aria-labelledby="mcMusicActivity">
                    <div class="mc-ops-section-head">
                        <div><p class="mc-ops-kicker">CONTENT</p><h3 id="mcMusicActivity">Latest music</h3></div>
                        <a href="/admin/?page=songs">Manage music ↗</a>
                    </div>
                    <?php if ($recentSongs): ?>
                    <div class="mc-ops-table-scroll">
                        <table class="mc-ops-table">
                            <thead><tr><th>Track</th><th>Artist</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentSongs as $song): ?>
                            <tr>
                                <td><strong><?=h($song['name'] ?? '')?></strong><small>#<?=h($song['id'] ?? '')?> · <?=h($song['size'] ?? '')?> MB</small></td>
                                <td><?=h($song['author_name'] ?? '')?></td>
                                <td><span class="mc-ops-status <?=((int)($song['is_verified'] ?? 0) === 1) ? 'verified' : 'pending'?>"><?=((int)($song['is_verified'] ?? 0) === 1) ? 'Verified' : 'Pending'?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><p class="mc-ops-empty">No music has been uploaded yet.</p><?php endif; ?>
                </section>
                <?php endif; ?>
            </div>

            <?php if ($visibleActions): ?>
            <aside class="mc-ops-actions" aria-labelledby="mcOperatorActions">
                <div class="mc-ops-section-head"><div><p class="mc-ops-kicker">SHORTCUTS</p><h3 id="mcOperatorActions">Operator tools</h3></div></div>
                <nav aria-label="Operator shortcuts">
                    <?php foreach ($visibleActions as [$section,$label,$description]): ?>
                    <a href="/admin/?page=<?=h($section)?>"><span><strong><?=h($label)?></strong><small><?=h($description)?></small></span><span aria-hidden="true">↗</span></a>
                    <?php endforeach; ?>
                </nav>
                <p class="mc-ops-side-note">Only tools your administrator role can access are shown.</p>
            </aside>
            <?php endif; ?>
        </div>

        <?php if (canPermission('audit.view')): ?>
        <details class="mc-ops-audit" id="auditDetails">
            <summary><span><strong>Audit log</strong><small>Recent administrator activity · refreshes every 15 seconds</small></span><span id="auditLiveState" role="status">Loading…</span></summary>
            <div id="muchoLiveAudit" class="admin-live-audit-list"><div class="mc-ops-empty">Loading audit events…</div></div>
        </details>
        <script src="/admin/product-dashboard.js?v=2" defer></script>
        <?php endif; ?>
    </div>
    <?php
    echo ob_get_clean();
}
