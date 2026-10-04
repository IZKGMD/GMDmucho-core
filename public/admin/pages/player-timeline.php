<?php
declare(strict_types=1);

requirePermission('players.view');

$accountId = (int)($_GET['account_id'] ?? 0);
if ($accountId <= 0) {
    echo '<div class="card"><h2 style="margin-top:0">Player Timeline</h2><p class="muted">Open a player from the Players page to inspect their activity timeline.</p><a class="btn" href="/admin/?page=players">Open Players</a></div>';
    return;
}

$player = null;
$stmt = $db->prepare(
    'SELECT a.account_id,a.username,a.email,a.is_active,a.is_banned,a.created_at,
            COALESCE(r.code,"user") role,
            COALESCE(p.stars,0) stars,COALESCE(p.demons,0) demons,
            COALESCE(p.creator_points,0) creator_points
     FROM accounts a
     LEFT JOIN roles r ON r.id=a.role_id
     LEFT JOIN profiles p ON p.account_id=a.account_id
     WHERE a.account_id=:id LIMIT 1'
);
$stmt->execute(['id'=>$accountId]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    echo '<div class="card"><h2 style="margin-top:0">Player not found</h2><a class="btn" href="/admin/?page=players">Back to Players</a></div>';
    return;
}

$timeline = [];

$add = static function(array &$list, string $when, string $kind, string $title, string $detail, string $href=''): void {
    $list[] = ['when'=>$when,'kind'=>$kind,'title'=>$title,'detail'=>$detail,'href'=>$href];
};

$q = $db->prepare(
    'SELECT level_id,name,created_at,updated_at FROM levels
     WHERE account_id=:id ORDER BY updated_at DESC LIMIT 12'
);
$q->execute(['id'=>$accountId]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $add(
        $timeline,
        (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
        'level',
        'Level activity',
        '#' . $row['level_id'] . ' · ' . (string)$row['name'],
        '/admin/?page=levels&q=' . rawurlencode((string)$row['level_id'])
    );
}

if ((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mucho_level_scores'")->fetchColumn() > 0) {
    $q = $db->prepare(
        'SELECT s.updated_at,s.level_id,s.percent,l.name
         FROM mucho_level_scores s
         LEFT JOIN levels l ON l.level_id=s.level_id
         WHERE s.account_id=:id ORDER BY s.updated_at DESC LIMIT 12'
    );
    $q->execute(['id'=>$accountId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $add($timeline,(string)$row['updated_at'],'score','Score submitted','#'.$row['level_id'].' · '.(string)($row['name'] ?? 'Level').' · '.(int)$row['percent'].'%');
    }
}

$q = $db->prepare(
    'SELECT created_at,content,level_id FROM comments
     WHERE account_id=:id ORDER BY id DESC LIMIT 12'
);
$q->execute(['id'=>$accountId]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $preview = trim((string)$row['content']);
    if (mb_strlen($preview,'UTF-8') > 120) {
        $preview = mb_substr($preview,0,117,'UTF-8').'…';
    }
    $add($timeline,(string)$row['created_at'],'comment','Level comment','#'.(int)$row['level_id'].' · '.$preview);
}

if ((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mucho_security_events'")->fetchColumn() > 0) {
    $q = $db->prepare(
        'SELECT created_at,event_type,route,request_id
         FROM mucho_security_events
         WHERE metadata LIKE :needle OR route LIKE :route
         ORDER BY id DESC LIMIT 12'
    );
    $q->execute([
        'needle'=>'%"account_id":'.$accountId.'%',
        'route'=>'%account%',
    ]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $add($timeline,(string)$row['created_at'],'security','Security event',(string)$row['event_type'].' · '.(string)$row['route']);
    }
}

if ((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='admin_audit_logs'")->fetchColumn() > 0) {
    $q = $db->prepare(
        'SELECT created_at,action,target,username
         FROM admin_audit_logs
         WHERE target=:target
         ORDER BY id DESC LIMIT 12'
    );
    $q->execute(['target'=>(string)$accountId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $add($timeline,(string)$row['created_at'],'admin','Admin action',(string)$row['action'].' · '.(string)($row['username'] ?? 'operator'));
    }
}

usort($timeline, static fn(array $a,array $b): int => strcmp($b['when'],$a['when']));
$timeline = array_slice($timeline,0,40);
?>
<style>
.mc-tl-head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start;padding:20px;border:1px solid var(--border);border-radius:16px;background:linear-gradient(145deg,#121827,#0d121a)}
.mc-tl-head h2{margin:0 0 6px}.mc-tl-meta{color:var(--muted);line-height:1.5}
.mc-tl-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;min-width:300px}
.mc-tl-stat{padding:10px;border:1px solid var(--border);border-radius:10px;background:#090e15;text-align:center}
.mc-tl-stat b{display:block;font-size:18px}
.mc-tl{margin-top:14px}.mc-tl-item{display:grid;grid-template-columns:120px 82px minmax(0,1fr);gap:12px;padding:12px 0;border-bottom:1px solid #202836}
.mc-tl-item:last-child{border-bottom:0}.mc-tl-kind{font-size:10px;text-transform:uppercase;letter-spacing:.08em}.mc-tl-title{font-weight:800}.mc-tl-detail{margin-top:3px;color:var(--muted);font-size:12px}
@media(max-width:800px){.mc-tl-head{display:block}.mc-tl-stats{min-width:0;margin-top:14px}.mc-tl-item{grid-template-columns:1fr}.mc-tl-item time{color:var(--muted)}}
</style>

<section class="mc-tl-head">
    <div>
        <span class="hero-eyebrow">PLAYER INTELLIGENCE</span>
        <h2>#<?=h((string)$player['account_id'])?> <?=h((string)$player['username'])?></h2>
        <div class="mc-tl-meta">
            <?=h((string)$player['email'])?> · <?=h(ucfirst((string)$player['role']))?>
            · <?=((int)$player['is_banned']===1)?'Banned':'Active account'?>
            · Created <?=h((string)$player['created_at'])?>
        </div>
        <div style="margin-top:11px"><a class="btn gray" href="/admin/?page=players&q=<?=rawurlencode((string)$player['username'])?>">Back to Player</a></div>
    </div>
    <div class="mc-tl-stats">
        <div class="mc-tl-stat"><small>Stars</small><b><?=number_format((int)$player['stars'])?></b></div>
        <div class="mc-tl-stat"><small>Demons</small><b><?=number_format((int)$player['demons'])?></b></div>
        <div class="mc-tl-stat"><small>Creator Points</small><b><?=number_format((int)$player['creator_points'])?></b></div>
    </div>
</section>

<section class="card mc-tl">
    <div class="section-heading">
        <div><h2>Activity Timeline</h2><small>Recent content, score, comment, security and operator events.</small></div>
        <span class="badge"><?=count($timeline)?> events</span>
    </div>
    <?php if ($timeline === []): ?>
        <div class="empty-state">No timeline events are available for this player yet.</div>
    <?php else: ?>
        <?php foreach($timeline as $item): ?>
            <div class="mc-tl-item">
                <time class="mc-tl-detail"><?=h((string)$item['when'])?></time>
                <span class="mc-tl-kind"><?=h((string)$item['kind'])?></span>
                <div>
                    <div class="mc-tl-title"><?=h((string)$item['title'])?></div>
                    <div class="mc-tl-detail"><?=h((string)$item['detail'])?></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
