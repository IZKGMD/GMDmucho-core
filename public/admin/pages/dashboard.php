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
    $pendingLevels = null;

    // Queue counts follow the same page permissions as their destinations.
    if (canAdminPage('rating')) try {
        $pendingLevels = (int)$db->query(
            'SELECT COUNT(*) FROM levels WHERE requested_stars > 0 AND is_deleted = 0'
        )->fetchColumn();
    } catch (Throwable) {
        // Unknown is not the same as an empty queue.
    }

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

    $recentPlayers = null;
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

    $recentSongs = null;
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

    $recentLevels = null;
    if (canAdminPage('levels')) try {
        $recentLevels = $db->query(
            'SELECT l.level_id,l.name,l.stars,l.requested_stars,
                    COALESCE(a.username, \'Unknown\') AS creator_name
             FROM levels l
             LEFT JOIN accounts a ON a.account_id=l.account_id
             WHERE l.is_deleted=0
             ORDER BY l.level_id DESC LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
    }

    $kpis = [
        ['Players', $accounts, ($todayAccounts === null ? 'Today unavailable' : '+' . $todayAccounts . ' today'), 'players'],
        ['Levels', $levels, 'Stored levels', 'levels'],
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
                <h2>Server overview</h2>
                <p>Players, levels and content at a glance.</p>
            </div>
            <div class="mc-ops-intro-aside">
                <span class="mc-ops-snapshot" title="Database figures are refreshed when the page loads"><span>Snapshot</span> · <?=h(date('H:i'))?> <span>server time</span></span>
                <a class="mc-ops-secondary" href="/dashboard" target="_blank" rel="noopener noreferrer">Player portal <span aria-hidden="true">↗</span></a>
            </div>
        </header>

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
            <?php if (canAdminPage('rating') || canAdminPage('songs') || $visibleActions): ?>
            <div class="mc-ops-rail" role="complementary" aria-label="Operator workspace">
                <?php if (canAdminPage('rating') || canAdminPage('songs')): ?>
                <section class="mc-ops-review" aria-labelledby="mcReviewTitle">
                    <div class="mc-ops-section-head">
                        <div><p class="mc-ops-kicker">MODERATION</p><h3 id="mcReviewTitle">Needs attention</h3></div>
                    </div>
            <nav class="mc-ops-queue" aria-label="Review queues">
                <?php if (canAdminPage('rating')): ?>
                <a class="mc-ops-attention <?= $pendingLevels === 0 ? 'is-clear' : '' ?>"
                   href="/admin/?page=rating" aria-label="Open level rating requests">
                    <span class="mc-ops-attention-mark" aria-hidden="true">★</span>
                    <span class="mc-ops-attention-copy">
                        <small>LEVEL MODERATION</small>
                        <strong><?php if ($pendingLevels === null): ?>Queue unavailable<?php elseif ($pendingLevels === 0): ?>Level queue is clear<?php else: ?><?=number_format($pendingLevels)?> <span>rating requests</span><?php endif; ?></strong>
                        <span>Review requested ratings</span>
                    </span>
                    <span class="mc-ops-attention-go" aria-hidden="true">↗</span>
                </a>
                <?php endif; ?>
                <?php if (canAdminPage('songs')): ?>
                <a class="mc-ops-attention <?= $pendingSongs === 0 ? 'is-clear' : '' ?>"
                   href="/admin/?page=songs&amp;filter=pending" aria-label="Review pending music">
                    <span class="mc-ops-attention-mark" aria-hidden="true">♪</span>
                    <span class="mc-ops-attention-copy">
                        <small>MUSIC MODERATION</small>
                        <strong><?php if ($pendingSongs === null): ?>Queue unavailable<?php elseif ($pendingSongs === 0): ?>Music queue is clear<?php else: ?><?=number_format($pendingSongs)?> <span>tracks to review</span><?php endif; ?></strong>
                        <span><?php if ($todaySongs === null): ?>Daily uploads unavailable<?php else: ?><?=h((string)$todaySongs)?> <span>uploads today</span><?php endif; ?></span>
                    </span>
                    <span class="mc-ops-attention-go" aria-hidden="true">↗</span>
                </a>
                <?php endif; ?>
            </nav>

                </section>
                <?php endif; ?>
            <?php if ($visibleActions): ?>
            <section class="mc-ops-actions" aria-labelledby="mcOperatorActions">
                <div class="mc-ops-section-head"><div><p class="mc-ops-kicker">SHORTCUTS</p><h3 id="mcOperatorActions">Operator tools</h3></div></div>
                <nav aria-label="Operator shortcuts">
                    <?php foreach ($visibleActions as [$section,$label,$description]): ?>
                    <a href="/admin/?page=<?=h($section)?>"><span><strong><?=h($label)?></strong><small><?=h($description)?></small></span><span aria-hidden="true">↗</span></a>
                    <?php endforeach; ?>
                </nav>
                <p class="mc-ops-side-note">Only tools your administrator role can access are shown.</p>
            </section>
            <?php endif; ?>

            </div>
            <?php endif; ?>
            <div class="mc-ops-feeds">
                <?php if (canAdminPage('levels')): ?>
                <section class="mc-ops-panel" aria-labelledby="mcRecentLevels">
                    <div class="mc-ops-section-head">
                        <div><p class="mc-ops-kicker">LEVELS</p><h3 id="mcRecentLevels">Latest uploads</h3></div>
                        <a href="/admin/?page=levels">All levels ↗</a>
                    </div>
                    <?php if ($recentLevels === null): ?>
                    <p class="mc-ops-empty">Level feed unavailable.</p>
                    <?php elseif ($recentLevels): ?>
                    <div class="mc-ops-table-scroll">
                        <table class="mc-ops-table">
                            <thead><tr><th>Level</th><th>Creator</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentLevels as $level): ?>
                            <tr>
                                <td><a class="mc-ops-level-link" href="/admin/?page=<?= canAdminPage('rating') ? 'rating&amp;id=' : 'levels&amp;q=' ?><?= (int)$level['level_id'] ?>"><?=h($level['name'] ?? '')?></a><small>#<?= (int)$level['level_id'] ?> · <?=h($level['creator_name'] ?? '')?></small></td>
                                <td><?=h($level['creator_name'] ?? '')?></td>
                                <td>
                                    <?php if ((int)$level['requested_stars'] > 0): ?>
                                    <span class="mc-ops-status">Requested</span>
                                    <?php elseif ((int)$level['stars'] > 0): ?>
                                    <span class="mc-ops-status verified">Rated</span>
                                    <?php else: ?>
                                    <span class="mc-ops-neutral">Unrated</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><p class="mc-ops-empty">No published levels to show yet.</p><?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if (canAdminPage('players')): ?>
                <section class="mc-ops-panel" aria-labelledby="mcRecentPlayers">
                    <div class="mc-ops-section-head">
                        <div><p class="mc-ops-kicker">COMMUNITY</p><h3 id="mcRecentPlayers">New players</h3></div>
                        <a href="/admin/?page=players">All players ↗</a>
                    </div>
                    <?php if ($recentPlayers === null): ?>
                    <p class="mc-ops-empty">Player feed unavailable.</p>
                    <?php elseif ($recentPlayers): ?>
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
                    <?php if ($recentSongs === null): ?>
                    <p class="mc-ops-empty">Music feed unavailable.</p>
                    <?php elseif ($recentSongs): ?>
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
