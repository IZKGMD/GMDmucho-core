<?php
declare(strict_types=1);

require_once __DIR__.'/advanced-lib.php';

v4Init($db);

try{

/* =========================================================
   BULK PLAYERS
========================================================= */

if($action==='v4-bulk-players'){
    requireRank(30);

    $ids=v4Ids((string)($_POST['ids'] ?? ''));
    $operation=(string)($_POST['operation'] ?? '');
    $in=v4In($ids);

    if (rank((string)(admin()['role'] ?? '')) < 40) {
        $q=$db->prepare(
            "SELECT COUNT(*)
             FROM accounts a
             LEFT JOIN roles r ON r.id=a.role_id
             WHERE a.account_id IN ($in)
               AND COALESCE(r.code,'user')='owner'"
        );
        $q->execute();

        $ownerCount=(int)$q->fetchColumn();

        if ($ownerCount>0) {
            throw new RuntimeException(
                'Only an owner can modify owner accounts.'
            );
        }
    }

    if($operation==='ban'){
        $db->exec(
            "UPDATE accounts
             SET is_banned=1
             WHERE account_id IN ($in)"
        );
    }
    elseif($operation==='unban'){
        $db->exec(
            "UPDATE accounts
             SET is_banned=0
             WHERE account_id IN ($in)"
        );
    }
    elseif($operation==='activate'){
        $db->exec(
            "UPDATE accounts
             SET is_active=1
             WHERE account_id IN ($in)"
        );
    }
    elseif($operation==='deactivate'){
        $db->exec(
            "UPDATE accounts
             SET is_active=0
             WHERE account_id IN ($in)"
        );
    }
    elseif($operation==='role'){
        $legacyRoleAliases=[
            'mod'=>'moderator',
            'helper'=>'moderator',
            'elder'=>'moderator'
        ];

        $role=strtolower(trim((string)($_POST['role'] ?? 'user')));
        $role=$legacyRoleAliases[$role] ?? $role;

        $allowed=[
            'user','moderator','admin','owner'
        ];

        if(!in_array($role,$allowed,true)){
            throw new RuntimeException('Invalid role');
        }

        if($role==='owner'){
            requireRank(40);
        }

        $roleQuery=$db->prepare(
            'SELECT id
             FROM roles
             WHERE code=:role
             LIMIT 1'
        );

        $roleQuery->execute([
            'role'=>$role
        ]);

        $roleId=(int)$roleQuery->fetchColumn();

        if($roleId<=0){
            throw new RuntimeException('Role not found');
        }

        $q=$db->prepare(
            "UPDATE accounts
             SET role_id=:role_id
             WHERE account_id IN ($in)"
        );

        $q->execute(['role_id'=>$roleId]);
    }
    elseif($operation==='delete'){
        requireRank(40);

        if(
            (string)($_POST['confirm'] ?? '')
            !== 'DELETE'
        ){
            throw new RuntimeException(
                'Deletion requires DELETE confirmation.'
            );
        }

        foreach($ids as $id){
            v4HardDeleteAccount(
                $db,
                $id,
                admin()['username']
            );
        }
    }
    else{
        throw new RuntimeException('Unknown operation.');
    }

    audit(
        $db,
        'v4.players.bulk',
        implode(',',$ids),
        ['operation'=>$operation]
    );

    flash(
        'Bulk action completed for '.
        count($ids).' accounts.'
    );
}

/* =========================================================
   BULK LEVELS
========================================================= */

elseif($action==='v4-bulk-levels'){
    requireRank(20);

    $ids=v4Ids((string)($_POST['ids'] ?? ''));
    $operation=(string)($_POST['operation'] ?? '');
    $in=v4In($ids);

    if($operation==='rate'){
        $stars=max(
            0,
            min(10,(int)($_POST['stars'] ?? 0))
        );

        $db->exec(
            "UPDATE levels
             SET stars=$stars,
                 requested_stars=0
             WHERE level_id IN ($in)"
        );
    }
    elseif($operation==='feature'){
        $db->exec(
            "UPDATE levels
             SET featured=1
             WHERE level_id IN ($in)"
        );
    }
    elseif($operation==='unfeature'){
        $db->exec(
            "UPDATE levels
             SET featured=0
             WHERE level_id IN ($in)"
        );
    }
    elseif($operation==='epic'){
        $db->exec(
            "UPDATE levels
             SET epic=1
             WHERE level_id IN ($in)"
        );
    }
    elseif($operation==='unepic'){
        $db->exec(
            "UPDATE levels
             SET epic=0
             WHERE level_id IN ($in)"
        );
    }
    elseif($operation==='delete'){
        $db->exec(
            "UPDATE levels
             SET is_deleted=1
             WHERE level_id IN ($in)"
        );
    }
    elseif($operation==='restore'){
        $db->exec(
            "UPDATE levels
             SET is_deleted=0
             WHERE level_id IN ($in)"
        );
    }
    else{
        throw new RuntimeException('Unknown operation.');
    }

    audit(
        $db,
        'v4.levels.bulk',
        implode(',',$ids),
        ['operation'=>$operation]
    );

    flash(
        'Levels updated: '.count($ids)
    );
}

/* =========================================================
   MODERATION PRESET
========================================================= */

elseif($action==='v4-mod-preset'){
    requireRank(20);

    $id=(int)($_POST['id'] ?? 0);
    $preset=(string)($_POST['preset'] ?? '');

    if($id<=0){
        throw new RuntimeException('Invalid level');
    }

    if($preset==='rate'){
        $stars=max(
            1,
            min(10,(int)($_POST['stars'] ?? 1))
        );

        $q=$db->prepare(
            'UPDATE levels
             SET stars=:stars,
                 requested_stars=0
             WHERE level_id=:id'
        );

        $q->execute([
            'stars'=>$stars,
            'id'=>$id
        ]);
    }
    elseif($preset==='featured'){
        $q=$db->prepare(
            'UPDATE levels
             SET featured=1
             WHERE level_id=:id'
        );
        $q->execute(['id'=>$id]);
    }
    elseif($preset==='epic'){
        $q=$db->prepare(
            'UPDATE levels
             SET featured=1,
                 epic=1
             WHERE level_id=:id'
        );
        $q->execute(['id'=>$id]);
    }
    elseif(str_starts_with($preset,'demon-')){
        $map=[
            'demon-easy'=>3,
            'demon-medium'=>4,
            'demon-hard'=>0,
            'demon-insane'=>5,
            'demon-extreme'=>6
        ];

        if(!array_key_exists($preset,$map)){
            throw new RuntimeException(
                'Invalid demon preset'
            );
        }

        $q=$db->prepare(
            'UPDATE levels
             SET demon=1,
                 stars=10,
                 demon_difficulty=:diff,
                 requested_stars=0
             WHERE level_id=:id'
        );

        $q->execute([
            'diff'=>$map[$preset],
            'id'=>$id
        ]);
    }
    elseif($preset==='unrate'){
        $q=$db->prepare(
            'UPDATE levels
             SET stars=0,
                 featured=0,
                 epic=0,
                 demon=0
             WHERE level_id=:id'
        );

        $q->execute(['id'=>$id]);
    }
    else{
        throw new RuntimeException('Unknown preset');
    }

    audit(
        $db,
        'v4.level.moderate',
        (string)$id,
        ['preset'=>$preset]
    );

    flash("Level #$id updated.");
}

/* =========================================================
   SETTINGS
========================================================= */

elseif($action==='v4-settings'){
    requireRank(40);

    $serverName=substr(
        trim((string)($_POST['server_name'] ?? '')),
        0,
        80
    );

    $announcement=substr(
        trim((string)($_POST['announcement'] ?? '')),
        0,
        1000
    );

    $maxLevel=max(
        100000,
        min(
            50000000,
            (int)($_POST['max_level_bytes'] ?? 8000000)
        )
    );

    v4Set($db,'server_name',$serverName);
    v4Set($db,'announcement',$announcement);
    v4Set($db,'max_level_bytes',(string)$maxLevel);

    foreach([
        'default_messages_state',
        'default_friend_requests_state',
        'default_comments_state'
    ] as $key){
        v4Set(
            $db,
            $key,
            (string)max(
                0,
                min(2,(int)($_POST[$key] ?? 0))
            )
        );
    }

    if(isset($_POST['maintenance'])){
        file_put_contents(
            CONTROL_DIR.'/maintenance.flag',
            '1'
        );
    }else{
        @unlink(CONTROL_DIR.'/maintenance.flag');
    }

    if(isset($_POST['registrations'])){
        @unlink(
            CONTROL_DIR.'/registrations-disabled.flag'
        );
    }else{
        file_put_contents(
            CONTROL_DIR.'/registrations-disabled.flag',
            '1'
        );
    }

    audit($db,'v4.settings.update');
    flash('GDPS settings saved.');
}

else{
    throw new RuntimeException(
        'Unknown v4 action.'
    );
}

}catch(Throwable $e){
    flash(
        'Error: '.$e->getMessage(),
        'error'
    );
}

$tab=(string)($_POST['tab'] ?? 'search');

header(
    'Location:/admin/?page=advanced&tab='.
    rawurlencode($tab)
);
exit;
