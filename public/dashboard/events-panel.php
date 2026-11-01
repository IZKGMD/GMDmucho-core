<?php
declare(strict_types=1);

/**
 * Read-only REBORN event surface.
 * This file is included by index.php, which owns $db, $account and pdH().
 * Events are always sourced from the enabled Creator Events plugin.
 */
use MuchoCore\CreatorEvents\CreatorEventsService;
use MuchoCore\News\NewsService;

$meEvents = [];
$meNews = [];
$mePluginEnabled = false;
$meUnavailable = false;
$meEntryCounts = [];
$meMyEntries = [];

try {
    $meService = new CreatorEventsService($db);
    $mePluginEnabled = $meService->enabled();

    if ($mePluginEnabled) {
        $meNow = time();
        $meRaw = $meService->events();
        foreach ($meRaw as $meItem) {
            $meState = (string)($meItem['state'] ?? '');
            if (!in_array($meState, ['open', 'judging', 'published'], true)) {
                continue;
            }
            $meDeadline = new DateTimeImmutable(
                (string)$meItem['deadline'],
                new DateTimeZone('UTC')
            );
            $meTimestamp = $meDeadline->getTimestamp();
            $meStatus = $meState === 'published' ? 'results'
                : ($meState === 'judging' ? 'judging'
                : ($meTimestamp > $meNow ? 'live' : 'closed'));
            $meItem['display_status'] = $meStatus;
            $meItem['deadline_ts'] = $meTimestamp;
            $meEvents[] = $meItem;
        }

        $meSortOrder = ['live' => 0, 'judging' => 1, 'closed' => 2, 'results' => 3];
        usort($meEvents, static function (array $a, array $b) use ($meSortOrder): int {
            $aOrder = $meSortOrder[$a['display_status']] ?? 4;
            $bOrder = $meSortOrder[$b['display_status']] ?? 4;
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }
            return $aOrder === 0
                ? ((int)$a['deadline_ts'] <=> (int)$b['deadline_ts'])
                : ((int)$b['id'] <=> (int)$a['id']);
        });
        $meEvents = array_slice($meEvents, 0, 8);

        if ($meEvents !== []) {
            $meIds = array_map(static fn(array $row): int => (int)$row['id'], $meEvents);
            $meMarks = implode(',', array_fill(0, count($meIds), '?'));
            $meQuery = $db->prepare(
                'SELECT event_id, COUNT(*) AS participants
                 FROM mucho_creator_entries
                 WHERE disqualified = 0 AND event_id IN (' . $meMarks . ')
                 GROUP BY event_id'
            );
            $meQuery->execute($meIds);
            foreach ($meQuery->fetchAll(PDO::FETCH_ASSOC) as $meRow) {
                $meEntryCounts[(int)$meRow['event_id']] = (int)$meRow['participants'];
            }

            if (is_array($account) && (int)($account['id'] ?? 0) > 0) {
                $meOwn = $db->prepare(
                    'SELECT event_id, level_id, level_name, result_rank, result_score
                     FROM mucho_creator_entries
                     WHERE account_id = ? AND event_id IN (' . $meMarks . ')'
                );
                $meOwn->execute(array_merge([(int)$account['id']], $meIds));
                foreach ($meOwn->fetchAll(PDO::FETCH_ASSOC) as $meRow) {
                    $meMyEntries[(int)$meRow['event_id']] = $meRow;
                }
            }
        }
    }
} catch (Throwable $meException) {
    error_log('[MuchoGDPS Events] Read failure: ' . $meException::class);
    $meUnavailable = true;
    $meEvents = [];
}

try {
    $meNews = (new NewsService($db))->published(3);
} catch (Throwable $meException) {
    error_log('[MuchoGDPS Events] News read failure: ' . $meException::class);
}

$meActiveCount = count(array_filter(
    $meEvents,
    static fn(array $event): bool => $event['display_status'] === 'live'
));
?>
<section class="me-hub" id="events" aria-labelledby="me-title">
    <div class="me-heading">
        <div>
            <div class="me-eyebrow"><span class="me-signal" aria-hidden="true"></span> MUCHO / COMMUNITY</div>
            <h2 id="me-title">Where legends <em>begin.</em></h2>
            <p>Discover competitions, join creator events and follow the latest results.</p>
        </div>
        <?php if ($mePluginEnabled && !$meUnavailable): ?>
            <a class="me-all-link" href="/extensions/creator-events">Open Creator Events <span aria-hidden="true">↗</span></a>
        <?php else: ?>
            <span class="me-all-link me-coming-soon">Events coming soon</span>
        <?php endif; ?>
    </div>

    <div class="me-overview">
        <div class="me-overview-icon" aria-hidden="true">✦</div>
        <div>
            <span class="me-overview-label">EVENT CONTROL</span>
            <strong><?= $meActiveCount > 0 ? pdFormatNumber($meActiveCount) . ' accepting entries' : 'New beginnings await' ?></strong>
            <span class="me-overview-sub">Only published server events appear here. All deadlines are UTC.</span>
        </div>
        <span class="me-overview-ornament" aria-hidden="true">M<span>✦</span></span>
    </div>

    <div class="me-layout">
        <div class="me-main">
            <div class="me-list-head">
                <h3>Event board <span><?= count($meEvents) ?></span></h3>
                <div class="me-filters" role="group" aria-label="Filter events">
                    <button type="button" class="me-filter is-active" data-me-filter="all" aria-pressed="true">All</button>
                    <button type="button" class="me-filter" data-me-filter="live" aria-pressed="false">Live</button>
                    <button type="button" class="me-filter" data-me-filter="judging" aria-pressed="false">Judging</button>
                    <button type="button" class="me-filter" data-me-filter="results" aria-pressed="false">Results</button>
                </div>
            </div>

            <div class="me-event-list">
            <?php if ($meUnavailable): ?>
                <div class="me-empty"><strong>Events are temporarily unavailable.</strong><p>Reload the page later to see the latest competitions.</p></div>
            <?php elseif (!$mePluginEnabled): ?>
                <div class="me-empty"><strong>Events haven't opened yet.</strong><p>The event board will go live once Creator Events is enabled by the server team.</p></div>
            <?php elseif ($meEvents === []): ?>
                <div class="me-empty"><strong>The calendar is clear — for now.</strong><p>New contests will appear as soon as they're published.</p></div>
            <?php else: ?>
                <?php foreach ($meEvents as $meIndex => $meEvent): ?>
                    <?php
                    $meId = (int)$meEvent['id'];
                    $meStatus = (string)$meEvent['display_status'];
                    $meStatusLabel = [
                        'live' => 'OPEN FOR ENTRIES',
                        'judging' => 'UNDER REVIEW',
                        'closed' => 'SUBMISSIONS CLOSED',
                        'results' => 'RESULTS PUBLISHED',
                    ][$meStatus] ?? 'EVENT';
                    $meDeadlineTs = (int)$meEvent['deadline_ts'];
                    $meMyEntry = $meMyEntries[$meId] ?? null;
                    $meRules = trim(preg_replace('/\s+/u', ' ', (string)$meEvent['rules']) ?? '');
                    ?>
                    <article class="me-event-card<?= $meIndex === 0 ? ' me-event-featured' : '' ?>" data-me-state="<?=pdH($meStatus)?>">
                        <div class="me-card-art" aria-hidden="true"><span>✦</span></div>
                        <div class="me-card-body">
                            <div class="me-card-meta">
                                <span class="me-status me-status-<?=pdH($meStatus)?>"><?=pdH($meStatusLabel)?></span>
                                <span class="me-event-number">EVENT #<?= $meId ?></span>
                            </div>
                            <h4><?=pdH($meEvent['title'])?></h4>
                            <p class="me-rules"><?=pdH(mb_strimwidth($meRules, 0, 205, '…', 'UTF-8'))?></p>
                            <div class="me-card-facts">
                                <span><b><?= (int)($meEntryCounts[$meId] ?? 0) ?></b> entries</span>
                                <span><b><?= (int)$meEvent['min_judges'] ?></b> minimum judges</span>
                                <span>Deadline <b><?=gmdate('M j, Y', $meDeadlineTs)?></b></span>
                            </div>
                            <?php if ($meMyEntry): ?>
                                <div class="me-my-entry">Your entry: <?=pdH($meMyEntry['level_name'])?> · #<?= (int)$meMyEntry['level_id'] ?>
                                    <?php if ($meStatus === 'results' && $meMyEntry['result_rank'] !== null): ?>
                                        · Rank #<?= (int)$meMyEntry['result_rank'] ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="me-card-foot">
                                <?php if ($meStatus === 'live'): ?>
                                    <span class="me-deadline" data-me-deadline="<?=pdH(gmdate('c', $meDeadlineTs))?>">Closes <?=gmdate('M j, H:i', $meDeadlineTs)?> UTC</span>
                                <?php else: ?>
                                    <span class="me-deadline"><?= $meStatus === 'results' ? 'Final standings available' : 'Entry deadline has passed' ?></span>
                                <?php endif; ?>
                                <a class="me-enter-link" href="/extensions/creator-events"><?= $meStatus === 'live' ? 'Enter event' : 'View event' ?><span aria-hidden="true"> ↗</span></a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="me-filter-empty" hidden>No events match this filter yet.</div>
            <?php endif; ?>
            </div>
        </div>

        <aside class="me-side" aria-label="Latest community updates">
            <div class="me-side-head">
                <span class="me-side-icon" aria-hidden="true">⌁</span>
                <h3>Latest updates</h3>
                <a href="/news.php">All news ↗</a>
            </div>
            <?php if ($meNews === []): ?>
                <div class="me-news-empty">No announcements published yet. Official updates will appear here.</div>
            <?php else: ?>
                <div class="me-news-list">
                    <?php foreach ($meNews as $mePost): ?>
                        <a class="me-news-item" href="/news.php">
                            <span class="me-news-date"><?=pdH(substr((string)($mePost['published_at'] ?? ''), 0, 10))?><?= !empty($mePost['pinned']) ? ' · PINNED' : '' ?></span>
                            <strong><?=pdH($mePost['title'])?></strong>
                            <span class="me-news-arrow" aria-hidden="true">↗</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="me-side-bottom">
                <strong>Make something unforgettable.</strong>
                <p>Build your next level, submit it to a live contest and let the community discover your work.</p>
                <a href="/dashboard/clans.php">Explore clans <span aria-hidden="true">↗</span></a>
            </div>
        </aside>
    </div>
</section>
