<?php
declare(strict_types=1);

require_once __DIR__.'/advanced-lib.php';

v4Init($db);

$tab=(string)($_GET['tab'] ?? 'search');

$tabs=[
    'search'=>'Search',
    'players'=>'Bulk Players',
    'levels'=>'Bulk Levels',
    'moderation'=>'Moderation',
    'history'=>'Player History',
    'settings'=>'GDPS Settings'
];

if(!isset($tabs[$tab])){
    $tab='search';
}

?>
<style>
.v4tabs{
display:flex;
gap:7px;
overflow:auto;
padding-bottom:7px;
margin-bottom:14px
}
.v4tabs a{
white-space:nowrap;
padding:9px 12px;
background:#151b27;
border:1px solid #263044;
border-radius:9px
}
.v4tabs a.active{
background:#2b2550;
border-color:#564c8c
}
.v4toolbar{
position:sticky;
bottom:10px;
z-index:20;
background:rgba(15,20,29,.96);
border:1px solid #30394c;
border-radius:13px;
padding:10px;
margin:12px 0;
backdrop-filter:blur(12px)
}
.v4check{
width:18px!important;
height:18px!important
}
.v4result{
margin:9px 0;
padding:13px;
background:#111620;
border:1px solid #242d3c;
border-radius:11px
}
.v4danger{
border:1px solid #6e2935!important
}
.v4actions{
display:flex;
gap:6px;
flex-wrap:wrap
}
@media(max-width:800px){
.v4tabs{
margin-left:-2px;
margin-right:-2px
}
.v4toolbar .row{
display:grid;
grid-template-columns:1fr 1fr
}
.v4toolbar select,
.v4toolbar input,
.v4toolbar button{
width:100%
}
}
</style>

<div class="v4tabs">
<?php foreach($tabs as $k=>$label): ?>
<a
 class="<?=$tab===$k?'active':''?>"
 href="/admin/?page=advanced&tab=<?=h($k)?>"
><?=h($label)?></a>
<?php endforeach ?>
</div>

<?php

/* =========================================================
   GLOBAL SEARCH
========================================================= */

if($tab==='search'){

$q=trim((string)($_GET['q'] ?? ''));

?>
<form class="search">
<input type="hidden" name="page" value="advanced">
<input type="hidden" name="tab" value="search">

<input
 name="q"
 value="<?=h($q)?>"
 placeholder="Username, email, Account ID, Level ID, name, Comment ID"
>

<button>Search everywhere</button>
</form>
<?php

if($q!==''){

    $like='%'.$q.'%';
    $numeric=ctype_digit($q) ? (int)$q : 0;

    $accounts=v4Rows(
        $db,
        'SELECT account_id,username,email,role,is_banned
         FROM accounts
         WHERE username LIKE :q1
            OR email LIKE :q2
            OR account_id=:id
         ORDER BY account_id DESC
         LIMIT 30',
        [
            'q1'=>$like,
            'q2'=>$like,
            'id'=>$numeric
        ]
    );

    $levels=v4Rows(
        $db,
        'SELECT level_id,name,account_id,stars,featured,epic
         FROM levels
         WHERE name LIKE :q1
            OR level_id=:id
         ORDER BY level_id DESC
         LIMIT 30',
        [
            'q1'=>$like,
            'id'=>$numeric
        ]
    );

    $comments=v4Rows(
        $db,
        'SELECT id,level_id,account_id,content
         FROM comments
         WHERE content LIKE :q1
            OR id=:id
         ORDER BY id DESC
         LIMIT 30',
        [
            'q1'=>$like,
            'id'=>$numeric
        ]
    );

    echo '<h2>Players</h2>';

    foreach($accounts as $r){
        echo '<div class="v4result">';
        echo '<b>#'.h($r['account_id']).' '.
            h($r['username']).'</b> ';
        echo '<span class="badge">'.h($r['role']).'</span>';
        if($r['is_banned']){
            echo ' <span class="badge bad">BANNED</span>';
        }
        echo '<br><small>'.h($r['email']).'</small>';
        echo '<br><a class="btn gray" href="/admin/?page=advanced&tab=history&id='.
            h($r['account_id']).
            '">Open history</a>';
        echo '</div>';
    }

    echo '<h2>Levels</h2>';

    foreach($levels as $r){
        echo '<div class="v4result">';
        echo '<b>#'.h($r['level_id']).' '.
            h($r['name']).'</b>';
        echo '<br><small>Account #'.
            h($r['account_id']).
            ' · Stars '.h($r['stars']).
            ' · Featured '.h($r['featured']).
            ' · Epic '.h($r['epic']).
            '</small>';
        echo '</div>';
    }

    echo '<h2>Comments</h2>';

    foreach($comments as $r){
        echo '<div class="v4result">';
        echo '<b>Comment #'.h($r['id']).'</b>';
        echo '<br>'.h($r['content']);
        echo '<br><small>Account #'.
            h($r['account_id']).
            ' · Level #'.h($r['level_id']).
            '</small>';
        echo '</div>';
    }

    if(!$accounts && !$levels && !$comments){
        echo '<div class="card">Nothing found.</div>';
    }
}
}

/* =========================================================
   BULK PLAYERS
========================================================= */

elseif($tab==='players'){

$q=trim((string)($_GET['q'] ?? ''));

$sql=
'SELECT account_id,username,email,role,
        is_active,is_banned
 FROM accounts';

$args=[];

if($q!==''){
    $sql.=
    ' WHERE username LIKE :q1
       OR email LIKE :q2
       OR account_id=:id';

    $args=[
        'q1'=>'%'.$q.'%',
        'q2'=>'%'.$q.'%',
        'id'=>ctype_digit($q)?(int)$q:0
    ];
}

$sql.=' ORDER BY account_id DESC LIMIT 100';

$rows=v4Rows($db,$sql,$args);

?>
<form class="search">
<input type="hidden" name="page" value="advanced">
<input type="hidden" name="tab" value="players">
<input name="q" value="<?=h($q)?>" placeholder="Filter players">
<button>Search</button>
</form>

<div class="table">
<table>
<tr>
<th><input type="checkbox" id="v4AllPlayers"></th>
<th>ID</th>
<th>Username</th>
<th>Email</th>
<th>Role</th>
<th>Active</th>
<th>Ban</th>
</tr>

<?php foreach($rows as $r): ?>
<tr>
<td>
<input
 class="v4check v4-player"
 type="checkbox"
 value="<?=h($r['account_id'])?>"
>
</td>
<td><?=h($r['account_id'])?></td>
<td>
<a href="/admin/?page=advanced&tab=history&id=<?=h($r['account_id'])?>">
<b><?=h($r['username'])?></b>
</a>
</td>
<td><?=h($r['email'])?></td>
<td><?=h($r['role'])?></td>
<td><?=h($r['is_active'])?></td>
<td><?=h($r['is_banned'])?></td>
</tr>
<?php endforeach ?>
</table>
</div>

<form
 method="post"
 class="v4toolbar"
 id="v4PlayerForm"
>
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="v4-bulk-players">
<input type="hidden" name="tab" value="players">
<input type="hidden" name="ids" id="v4PlayerIds">
<input type="hidden" name="confirm" id="v4DeleteConfirm">

<div class="row">
<select name="operation" id="v4PlayerOperation">
<option value="ban">Ban</option>
<option value="unban">Unban</option>
<option value="activate">Activate</option>
<option value="deactivate">Deactivate</option>
<option value="role">Change role</option>
<option value="delete">Move to trash</option>
</select>

<select name="role">
<option>user</option>
<option>helper</option>
<option>moderator</option>
<option>admin</option>
<option>owner</option>
</select>

<button>Apply to selected</button>
</div>
</form>
<?php
}

/* =========================================================
   BULK LEVELS
========================================================= */

elseif($tab==='levels'){

$rows=v4Rows(
    $db,
    'SELECT level_id,name,account_id,stars,
            requested_stars,featured,epic,
            demon,is_deleted
     FROM levels
     ORDER BY level_id DESC LIMIT 120'
);

?>
<div class="table">
<table>
<tr>
<th><input type="checkbox" id="v4AllLevels"></th>
<th>ID</th>
<th>Level</th>
<th>Author</th>
<th>Stars</th>
<th>Requested</th>
<th>Featured</th>
<th>Epic</th>
<th>Demon</th>
<th>Deleted</th>
</tr>

<?php foreach($rows as $r): ?>
<tr>
<td>
<input
 class="v4check v4-level"
 type="checkbox"
 value="<?=h($r['level_id'])?>"
>
</td>
<td><?=h($r['level_id'])?></td>
<td><b><?=h($r['name'])?></b></td>
<td>#<?=h($r['account_id'])?></td>
<td><?=h($r['stars'])?></td>
<td><?=h($r['requested_stars'])?></td>
<td><?=h($r['featured'])?></td>
<td><?=h($r['epic'])?></td>
<td><?=h($r['demon'])?></td>
<td><?=h($r['is_deleted'])?></td>
</tr>
<?php endforeach ?>
</table>
</div>

<form
 method="post"
 class="v4toolbar"
 id="v4LevelForm"
>
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="v4-bulk-levels">
<input type="hidden" name="tab" value="levels">
<input type="hidden" name="ids" id="v4LevelIds">

<div class="row">
<select name="operation">
<option value="rate">Set stars</option>
<option value="feature">Featured</option>
<option value="unfeature">Remove Featured</option>
<option value="epic">Epic</option>
<option value="unepic">Remove Epic</option>
<option value="delete">Delete</option>
<option value="restore">Restore</option>
</select>

<input
 type="number"
 name="stars"
 value="5"
 min="0"
 max="10"
 style="width:80px"
>

<button>Apply to selected</button>
</div>
</form>
<?php
}

/* =========================================================
   MODERATION
========================================================= */

elseif($tab==='moderation'){

$rows=v4Rows(
    $db,
    'SELECT level_id,name,account_id,stars,
            requested_stars,difficulty,
            demon,demon_difficulty,
            featured,epic,downloads,likes
     FROM levels
     WHERE is_deleted=0
       AND (requested_stars>0 OR stars=0)
     ORDER BY
       requested_stars DESC,
       level_id DESC
     LIMIT 100'
);

if(!$rows){
    echo '<div class="card">Queue is empty 🎉</div>';
}

foreach($rows as $r):
?>
<div class="card" style="margin-bottom:11px">

<div class="row">
<b>#<?=h($r['level_id'])?> <?=h($r['name'])?></b>

<span class="badge">
request <?=h($r['requested_stars'])?>
</span>

<span class="badge">
downloads <?=h($r['downloads'])?>
</span>

<span class="badge">
likes <?=h($r['likes'])?>
</span>
</div>

<small>Author Account #<?=h($r['account_id'])?></small>

<div class="v4actions" style="margin-top:12px">

<form method="post" class="row">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="v4-mod-preset">
<input type="hidden" name="tab" value="moderation">
<input type="hidden" name="id" value="<?=h($r['level_id'])?>">
<input type="hidden" name="preset" value="rate">

<select name="stars">
<?php for($i=1;$i<=10;$i++): ?>
<option
 value="<?=$i?>"
 <?=$r['requested_stars']===$i?'selected':''?>
><?=$i?>★</option>
<?php endfor ?>
</select>

<button>Rate</button>
</form>

<?php
$buttons=[
    'featured'=>'Featured',
    'epic'=>'Epic',
    'demon-easy'=>'Easy Demon',
    'demon-medium'=>'Medium',
    'demon-hard'=>'Hard',
    'demon-insane'=>'Insane',
    'demon-extreme'=>'Extreme',
    'unrate'=>'Unrate'
];

foreach($buttons as $preset=>$label):
?>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="v4-mod-preset">
<input type="hidden" name="tab" value="moderation">
<input type="hidden" name="id" value="<?=h($r['level_id'])?>">
<input type="hidden" name="preset" value="<?=h($preset)?>">

<button class="<?=$preset==='unrate'?'red':'gray'?>">
<?=h($label)?>
</button>
</form>
<?php endforeach ?>

</div>
</div>
<?php
endforeach;
}

/* =========================================================
   PLAYER HISTORY
========================================================= */

elseif($tab==='history'){

$id=(int)($_GET['id'] ?? 0);

if($id<=0){
?>
<form class="search">
<input type="hidden" name="page" value="advanced">
<input type="hidden" name="tab" value="history">

<input
 name="id"
 inputmode="numeric"
 placeholder="Account ID"
>

<button>Open player</button>
</form>
<?php
}else{

    $q=$db->prepare(
        'SELECT a.*,p.*
         FROM accounts a
         LEFT JOIN profiles p
           ON p.account_id=a.account_id
         WHERE a.account_id=:id
         LIMIT 1'
    );

    $q->execute(['id'=>$id]);
    $user=$q->fetch(PDO::FETCH_ASSOC);

    if(!$user){
        echo '<div class="card">Player not found.</div>';
    }else{

?>
<div class="card">
<h2 style="margin-top:0">
#<?=h($id)?> <?=h($user['username'])?>
</h2>

<div class="grid">
<div>
<small>Registration</small><br>
<b><?=h($user['created_at'] ?? '—')?></b>
</div>

<div>
<small>Last activity</small><br>
<b><?=h($user['last_played'] ?? '—')?></b>
</div>

<div>
<small>Last IP</small><br>
<b><?=h($user['last_ip'] ?? '—')?></b>
</div>

<div>
<small>Role</small><br>
<b><?=h($user['role'] ?? 'user')?></b>
</div>
</div>
</div>

<?php

$levels=v4Rows(
    $db,
    'SELECT level_id,name,stars,downloads,likes,created_at
     FROM levels
     WHERE account_id=:id
     ORDER BY level_id DESC LIMIT 50',
    ['id'=>$id]
);

$comments=v4Rows(
    $db,
    'SELECT id,level_id,content,likes,created_at
     FROM comments
     WHERE account_id=:id
     ORDER BY id DESC LIMIT 50',
    ['id'=>$id]
);

$accComments=v4Rows(
    $db,
    'SELECT *
     FROM account_comments
     WHERE account_id=:id
     ORDER BY id DESC LIMIT 50',
    ['id'=>$id]
);

$friends=v4Rows(
    $db,
    'SELECT f.friend_account_id,a.username,f.created_at
     FROM friends f
     LEFT JOIN accounts a
       ON a.account_id=f.friend_account_id
     WHERE f.account_id=:id
     ORDER BY f.id DESC LIMIT 50',
    ['id'=>$id]
);

$blocks=v4Rows(
    $db,
    'SELECT b.blocked_account_id,a.username,b.created_at
     FROM blocks b
     LEFT JOIN accounts a
       ON a.account_id=b.blocked_account_id
     WHERE b.account_id=:id
     ORDER BY b.id DESC LIMIT 50',
    ['id'=>$id]
);

$adminLog=v4Rows(
    $db,
    'SELECT created_at,username,action,target
     FROM admin_audit_logs
     WHERE target=:target
        OR metadata LIKE :meta
     ORDER BY id DESC LIMIT 100',
    [
        'target'=>(string)$id,
        'meta'=>'%'.$id.'%'
    ]
);

function v4SimpleTable(string $title,array $rows): void
{
    echo '<h2>'.h($title).'</h2>';

    if(!$rows){
        echo '<div class="card"><small>No data</small></div>';
        return;
    }

    echo '<div class="table"><table><tr>';

    foreach(array_keys($rows[0]) as $k){
        echo '<th>'.h($k).'</th>';
    }

    echo '</tr>';

    foreach($rows as $r){
        echo '<tr>';

        foreach($r as $v){
            $text=(string)$v;

            if(strlen($text)>140){
                $text=substr($text,0,140).'…';
            }

            echo '<td>'.h($text).'</td>';
        }

        echo '</tr>';
    }

    echo '</table></div>';
}

v4SimpleTable('Uploaded levels',$levels);
v4SimpleTable('Comments',$comments);
v4SimpleTable('Profile comments',$accComments);
v4SimpleTable('Friends',$friends);
v4SimpleTable('Blocks',$blocks);
v4SimpleTable('Admin actions',$adminLog);

    }
}
}

/* =========================================================
   SETTINGS
========================================================= */

elseif($tab==='settings'){

$maintenance=is_file(
    CONTROL_DIR.'/maintenance.flag'
);

$registrations=!is_file(
    CONTROL_DIR.'/registrations-disabled.flag'
);

?>
<div class="card">

<form method="post">

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="v4-settings">
<input type="hidden" name="tab" value="settings">

<p>
<small>Name GDPS</small><br>
<input
 style="width:100%"
 name="server_name"
 value="<?=h(v4Setting($db,'server_name','Mucho GDPS'))?>"
>
</p>

<p>
<small>Announcement / MOTD</small>
<textarea name="announcement"><?=h(
v4Setting($db,'announcement')
)?></textarea>
</p>

<p>
<small>Maximum level data size, bytes</small><br>
<input
 type="number"
 name="max_level_bytes"
 value="<?=h(v4Setting($db,'max_level_bytes','8000000'))?>"
>
</p>

<div class="boxgrid">

<div>
<small>Default message privacy</small><br>
<select name="default_messages_state">
<?php foreach([0=>'Everyone',1=>'Friends only',2=>'Nobody'] as $v=>$label): ?>
<option
 value="<?=$v?>"
 <?=v4Setting($db,'default_messages_state')==(string)$v?'selected':''?>
><?=h($label)?></option>
<?php endforeach ?>
</select>
</div>

<div>
<small>Friend requests</small><br>
<select name="default_friend_requests_state">
<?php foreach([0=>'Allowed',1=>'Restricted',2=>'Disabled'] as $v=>$label): ?>
<option
 value="<?=$v?>"
 <?=v4Setting($db,'default_friend_requests_state')==(string)$v?'selected':''?>
><?=h($label)?></option>
<?php endforeach ?>
</select>
</div>

<div>
<small>Comments</small><br>
<select name="default_comments_state">
<?php foreach([0=>'Everyone',1=>'Friends',2=>'Disabled'] as $v=>$label): ?>
<option
 value="<?=$v?>"
 <?=v4Setting($db,'default_comments_state')==(string)$v?'selected':''?>
><?=h($label)?></option>
<?php endforeach ?>
</select>
</div>

</div>

<p>
<label>
<input
 type="checkbox"
 name="registrations"
 <?=$registrations?'checked':''?>
>
Registration enabled
</label>
</p>

<p>
<label>
<input
 type="checkbox"
 name="maintenance"
 <?=$maintenance?'checked':''?>
>
Maintenance mode
</label>
</p>

<button>Save settings</button>

</form>
</div>
<?php
}

?>

<script>
(() => {

function selected(cls){
    return [...document.querySelectorAll(cls+':checked')]
        .map(x=>x.value);
}

const pa=document.getElementById('v4AllPlayers');

pa?.addEventListener('change',()=>{
    document.querySelectorAll('.v4-player')
        .forEach(x=>x.checked=pa.checked);
});

const la=document.getElementById('v4AllLevels');

la?.addEventListener('change',()=>{
    document.querySelectorAll('.v4-level')
        .forEach(x=>x.checked=la.checked);
});

document.getElementById('v4PlayerForm')
?.addEventListener('submit',e=>{
    const ids=selected('.v4-player');

    if(!ids.length){
        e.preventDefault();
        alert('Select players.');
        return;
    }

    if(ids.length>20){
        e.preventDefault();
        alert('Maximum 20 players.');
        return;
    }

    document.getElementById('v4PlayerIds').value=
        ids.join(',');

    const op=
        document.getElementById('v4PlayerOperation').value;

    if(op==='delete'){
        const x=prompt(
            'Удалятся аккаунты и связанные данные.\\nВведите DELETE'
        );

        if(x!=='DELETE'){
            e.preventDefault();
            return;
        }

        document.getElementById('v4DeleteConfirm').value=
            'DELETE';
    }
});

document.getElementById('v4LevelForm')
?.addEventListener('submit',e=>{
    const ids=selected('.v4-level');

    if(!ids.length){
        e.preventDefault();
        alert('Select levels.');
        return;
    }

    if(ids.length>20){
        e.preventDefault();
        alert('Maximum 20 levels.');
        return;
    }

    document.getElementById('v4LevelIds').value=
        ids.join(',');
});

})();
</script>
