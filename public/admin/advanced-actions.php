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
    $actorRank=rank((string)(admin()['role'] ?? ''));

    if(in_array($operation,['ban','unban','activate','deactivate','role'],true)){
        $q=$db->prepare(
            "SELECT a.account_id, COALESCE(r.code,'user') AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id=a.role_id
             WHERE a.account_id IN ($in)"
        );
        $q->execute();
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $target){
            $targetRank=match (strtolower((string)$target['role'])) {
                'owner' => 40,
                'elder_moderator' => 30,
                'moderator' => 20,
                default => 0,
            };

            if($targetRank > 0 && $targetRank >= $actorRank){
                requireRank(40);
                break;
            }
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
        $role=(string)($_POST['role'] ?? 'user');

        try {
            $role=\MuchoCore\User\GameRole::normalize($role);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('Invalid role');
        }

        $roleQuery=$db->prepare(
            'SELECT id
             FROM roles
             WHERE code=:role
             LIMIT 1'
        );
        $roleQuery->execute(['role'=>$role]);
        $roleRow=$roleQuery->fetch(PDO::FETCH_ASSOC);

        if(!$roleRow){
            throw new RuntimeException('Role not found.');
        }

        $requestedRoleRank=match ($role) {
            \MuchoCore\User\GameRole::OWNER => 40,
            \MuchoCore\User\GameRole::ELDER_MODERATOR => 30,
            \MuchoCore\User\GameRole::MODERATOR => 20,
            default => 0,
        };

        if($requestedRoleRank >= $actorRank && $requestedRoleRank > 0){
            requireRank(40);
        }

        $roleId=(int)$roleRow['id'];

        $q=$db->prepare(
            "UPDATE accounts
             SET role_id=:role_id
             WHERE account_id IN ($in)"
        );

        $q->execute(['role_id'=>(int)$roleId]);
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

        $difficulty=match($stars) {
            0 => 0, 1,8,9,10 => 50,
            2 => 10, 3 => 20, 4,5 => 30, 6,7 => 40
        };
        $demon=$stars===10 ? 1 : 0;
        $auto=$stars===1 ? 1 : 0;
        $db->exec(
            "UPDATE levels
             SET stars=$stars,
                 difficulty=$difficulty,
                 demon_difficulty=CASE
                    WHEN $demon=1 AND demon=1 AND custom_difficulty=0
                    THEN demon_difficulty ELSE 0 END,
                 demon=$demon,
                 auto_level=$auto,
                 custom_difficulty=0,
                 requested_stars=0,
                 updated_at=NOW()
             WHERE level_id IN ($in) AND is_deleted=0"
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

    // Bulk rating and quality changes affect public search and creator stats.
    $owners=$db->query("SELECT DISTINCT account_id FROM levels WHERE level_id IN ($in)")
        ->fetchAll(PDO::FETCH_COLUMN);
    $cpQuery=$db->prepare('SELECT COALESCE(SUM(
        CASE WHEN stars>0 THEN 1 ELSE 0 END
        + CASE WHEN featured>0 THEN 1 ELSE 0 END
        + epic),0) FROM levels WHERE account_id=:author AND is_deleted=0');
    $cpSync=$db->prepare('UPDATE profiles SET creator_points=:points WHERE account_id=:author');
    foreach($owners as $ownerId) {
        $ownerId=(int)$ownerId;
        if($ownerId<=0)continue;
        $cpQuery->execute(['author'=>$ownerId]);
        $cpSync->execute(['points'=>(int)$cpQuery->fetchColumn(),'author'=>$ownerId]);
    }
    try {
        \MuchoCore\Cache\CacheManager::fromEnvironment($db)->deletePrefix('mucho:levels:');
    } catch(Throwable $error) {
        error_log('[MuchoCore] bulk rating cache invalidation failed: '.$error->getMessage());
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

        $newDifficulty=match($stars) {
            1,8,9,10 => 50,
            2 => 10, 3 => 20, 4,5 => 30, 6,7 => 40
        };
        $isDemon=$stars===10 ? 1 : 0;
        $isAuto=$stars===1 ? 1 : 0;
        $q=$db->prepare(
            'UPDATE levels SET
                stars=:stars,
                difficulty=:difficulty,
                demon_difficulty=CASE
                    WHEN :keep_demon=1 AND demon=1 AND custom_difficulty=0
                    THEN demon_difficulty ELSE 0 END,
                demon=:demon,
                auto_level=:auto,
                custom_difficulty=0,
                requested_stars=0,
                updated_at=NOW()
             WHERE level_id=:id AND is_deleted=0'
        );
        $q->execute([
            'stars'=>$stars,
            'difficulty'=>$newDifficulty,
            'keep_demon'=>$isDemon,
            'demon'=>$isDemon,
            'auto'=>$isAuto,
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
                 difficulty=50,
                 auto_level=0,
                 custom_difficulty=0,
                 demon_difficulty=:diff,
                 requested_stars=0,
                 updated_at=NOW()
             WHERE level_id=:id AND is_deleted=0'
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
                 difficulty=0,
                 auto_level=0,
                 featured=0,
                 epic=0,
                 demon=0,
                 demon_difficulty=0,
                 custom_difficulty=0,
                 requested_stars=0,
                 updated_at=NOW()
             WHERE level_id=:id AND is_deleted=0'
        );

        $q->execute(['id'=>$id]);
    }
    else{
        throw new RuntimeException('Unknown preset');
    }

    // Every rating/feature preset changes Creator Points and cached lists.
    $creator=$db->prepare('SELECT account_id FROM levels WHERE level_id=:id AND is_deleted=0');
    $creator->execute(['id'=>$id]);
    $authorId=(int)($creator->fetchColumn() ?: 0);
    if($authorId>0){
        $cp=$db->prepare('SELECT COALESCE(SUM(
            CASE WHEN stars>0 THEN 1 ELSE 0 END
            + CASE WHEN featured>0 THEN 1 ELSE 0 END
            + epic),0) FROM levels WHERE account_id=:author AND is_deleted=0');
        $cp->execute(['author'=>$authorId]);
        $sync=$db->prepare('UPDATE profiles SET creator_points=:cp WHERE account_id=:author');
        $sync->execute(['cp'=>(int)$cp->fetchColumn(),'author'=>$authorId]);
    }
    try{
        \MuchoCore\Cache\CacheManager::fromEnvironment($db)->deletePrefix('mucho:levels:');
    }catch(Throwable $cacheError){
        error_log('[MuchoCore] moderation preset cache invalidation failed: '.$cacheError->getMessage());
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
