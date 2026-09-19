<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Dashboard
 * Copyright (C) 2026 IZK
 */

function renderAdminDashboard(PDO $db): void
{
    echo '
    <div class="quick-grid">

        <a class="quick-link" href="/admin/?page=players">
            <b>Players</b><br>
            <small>Accounts, bans and statistics</small>
        </a>

        <a class="quick-link" href="/admin/?page=muchoprofiles">
            <b>Mucho Profiles</b><br>
            <small>Titles, badges and personalization</small>
        </a>

        <a class="quick-link" href="/admin/?page=moderation">
            <b>Moderation</b><br>
            <small>Level rating and review</small>
        </a>

        <a class="quick-link" href="/admin/?page=endpoints">
            <b>API Tester</b><br>
            <small>Test GD endpoints</small>
        </a>

        <a class="quick-link" href="/admin/?page=dbbackups">
            <b>DB Backups</b><br>
            <small>Database recovery center</small>
        </a>

        <a class="quick-link" href="/admin/?page=securitycenter">
            <b>Security</b><br>
            <small>Monitoring, alerts and API metrics</small>
        </a>

    </div>
    ';


    $stats=[
        'Accounts'=>'accounts',
        'Levels'=>'levels',
        'Comments'=>'comments',
        'Messages'=>'messages',
        'Friends'=>'friends',
        'Blocks'=>'blocks',
        'Likes'=>'likes',
        'Songs'=>'songs'
    ];


    echo '<div class="grid">';

    foreach($stats as $name=>$table){

        try{
            $n=countTable(
                $db,
                $table
            );
        }catch(Throwable){
            $n=0;
        }

        echo '<div class="card">';
        echo '<small>'.h($name).'</small>';

        echo '<div class="value">'.
             number_format($n).
             '</div>';

        echo '</div>';
    }

    echo '</div>';


    echo '<h2>Server</h2>';

    try{
        $status=rootOp('status');
    }catch(Throwable $e){
        $status=
            'Failed to get status: '.
            $e->getMessage();
    }

    echo '<div class="card"><pre>'.
         h($status).
         '</pre></div>';


    echo '<h2>Latest registrations</h2>';

    try{

        $recent=$db->query(
            'SELECT
                a.account_id,
                a.username,
                a.created_at,
                p.icon_type,
                p.cube,
                p.ship,
                p.ball,
                p.ufo,
                p.wave,
                p.robot,
                p.spider,
                p.swing,
                p.jetpack,
                p.color1,
                p.color2,
                p.color3,
                p.glow
             FROM accounts a
             LEFT JOIN profiles p
                ON p.account_id = a.account_id
             ORDER BY a.account_id DESC
             LIMIT 10'
        )->fetchAll(
            PDO::FETCH_ASSOC
        );

    }catch(Throwable){
        $recent=[];
    }

    echo '<div class="table"><table>';

    echo '
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Date</th>
        </tr>
    ';

    foreach($recent as $r){

        echo '<tr>';

        echo '<td>'.
            h($r['account_id'] ?? '').
            '</td>';

        /* MUCHO_DASHBOARD_ICON_KIT_V2 */
        $iconTypes=[
            0=>['cube','cube'],
            1=>['ship','ship'],
            2=>['ball','ball'],
            3=>['ufo','ufo'],
            4=>['wave','wave'],
            5=>['robot','robot'],
            6=>['spider','spider'],
            7=>['swing','swing'],
            8=>['jetpack','jetpack'],
        ];

        $selectedType=(int)($r['icon_type'] ?? 0);

        if(!isset($iconTypes[$selectedType])){
            $selectedType=0;
        }

        [$selectedName,$selectedColumn]=$iconTypes[$selectedType];

        $color1=max(0,min(106,(int)($r['color1'] ?? 0)));
        $color2=max(0,min(106,(int)($r['color2'] ?? 3)));

        $mainIconId=max(1,(int)($r[$selectedColumn] ?? 1));

        $mainIconUrl=
            'https://gdicon.oat.zone/icon.png?'.
            http_build_query([
                'type'=>$selectedName,
                'value'=>$mainIconId,
                'color1'=>$color1,
                'color2'=>$color2,
            ]);

        $glow=(int)($r['glow'] ?? 0)===1;

        $mainFilter=
            $glow
            ? 'drop-shadow(0 0 5px rgba(255,255,255,.92)) drop-shadow(0 5px 7px rgba(0,0,0,.38))'
            : 'drop-shadow(0 5px 7px rgba(0,0,0,.38))';

        echo '<td>';

        echo '<details style="min-width:185px">';

        echo '<summary style="'.
             'display:flex;'.
             'align-items:center;'.
             'gap:11px;'.
             'cursor:pointer;'.
             'list-style:none;'.
             'user-select:none'.
             '">';

        echo '<img '.
             'src="'.h($mainIconUrl).'" '.
             'alt="" '.
             'loading="lazy" '.
             'referrerpolicy="no-referrer" '.
             'width="56" '.
             'height="56" '.
             'style="'.
             'width:56px;'.
             'height:56px;'.
             'object-fit:contain;'.
             'flex:0 0 56px;'.
             'filter:'.$mainFilter.
             '">';

        echo '<div>';

        echo '<b>'.h($r['username'] ?? '').'</b>';

        echo '<div style="'.
             'font-size:11px;'.
             'opacity:.58;'.
             'margin-top:3px'.
             '">';

        echo '#'.h($r['account_id'] ?? '').
             ' · '.h(ucfirst($selectedName)).
             ' #'.h($mainIconId);

        if($glow){
            echo ' · Glow';
        }

        echo '</div>';
        echo '</div>';
        echo '</summary>';

        echo '<div style="'.
             'display:flex;'.
             'flex-wrap:wrap;'.
             'gap:9px;'.
             'margin-top:10px;'.
             'padding:10px;'.
             'border-radius:12px;'.
             'background:rgba(255,255,255,.04)'.
             '">';

        foreach($iconTypes as [$kitType,$kitColumn]){

            $kitId=max(1,(int)($r[$kitColumn] ?? 1));

            $kitUrl=
                'https://gdicon.oat.zone/icon.png?'.
                http_build_query([
                    'type'=>$kitType,
                    'value'=>$kitId,
                    'color1'=>$color1,
                    'color2'=>$color2,
                ]);

            echo '<div style="width:58px;text-align:center">';

            echo '<img '.
                 'src="'.h($kitUrl).'" '.
                 'alt="" '.
                 'loading="lazy" '.
                 'referrerpolicy="no-referrer" '.
                 'width="46" '.
                 'height="46" '.
                 'style="'.
                 'width:46px;'.
                 'height:46px;'.
                 'object-fit:contain;'.
                 'filter:'.$mainFilter.
                 '">';

            echo '<div style="'.
                 'font-size:9px;'.
                 'opacity:.62;'.
                 'margin-top:2px'.
                 '">'.
                 h(ucfirst($kitType)).
                 '<br>#'.
                 h($kitId).
                 '</div>';

            echo '</div>';
        }

        echo '</div>';
        echo '</details>';
        echo '</td>';

        echo '<td>'.
            h($r['created_at'] ?? '').
            '</td>';

        echo '</tr>';
    }

    echo '</table></div>';


    echo '<h2>Latest levels</h2>';

    try{

        $levels=$db->query(
            'SELECT
                level_id,
                name,
                account_id,
                stars,
                downloads,
                likes
             FROM levels
             ORDER BY level_id DESC
             LIMIT 10'
        )->fetchAll(
            PDO::FETCH_ASSOC
        );

    }catch(Throwable){
        $levels=[];
    }


    echo '<div class="table"><table>';

    echo '
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Account</th>
            <th>Stars</th>
            <th>Downloads</th>
            <th>Likes</th>
        </tr>
    ';


    foreach($levels as $level){

        echo '<tr>';

        echo '<td>'.
            h($level['level_id'] ?? '').
            '</td>';

        echo '<td>'.
            h($level['name'] ?? '').
            '</td>';

        echo '<td>'.
            h($level['account_id'] ?? '').
            '</td>';

        echo '<td>'.
            h($level['stars'] ?? '').
            '</td>';

        echo '<td>'.
            h($level['downloads'] ?? '').
            '</td>';

        echo '<td>'.
            h($level['likes'] ?? '').
            '</td>';

        echo '</tr>';
    }

    echo '</table></div>';
}
