<?php
declare(strict_types=1);

function v4Init(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS gdps_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_deleted_accounts_v4 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(64) NULL,
            snapshot LONGTEXT NOT NULL,
            deleted_by VARCHAR(64) NOT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_deleted_account(account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults=[
        'server_name'=>'Mucho GDPS',
        'announcement'=>'',
        'max_level_bytes'=>'8000000',
        'default_messages_state'=>'0',
        'default_friend_requests_state'=>'0',
        'default_comments_state'=>'0',
    ];

    $q=$db->prepare(
        'INSERT IGNORE INTO gdps_settings
         (setting_key,setting_value)
         VALUES (:k,:v)'
    );

    foreach($defaults as $k=>$v){
        $q->execute(['k'=>$k,'v'=>$v]);
    }
}

function v4Setting(PDO $db,string $key,string $default=''): string
{
    $q=$db->prepare(
        'SELECT setting_value
         FROM gdps_settings
         WHERE setting_key=:k'
    );
    $q->execute(['k'=>$key]);

    $v=$q->fetchColumn();

    return $v===false ? $default : (string)$v;
}

function v4Set(PDO $db,string $key,string $value): void
{
    $q=$db->prepare(
        'INSERT INTO gdps_settings(setting_key,setting_value)
         VALUES (:k,:v)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
    );

    $q->execute(['k'=>$key,'v'=>$value]);
}

function v4Ids(string $raw): array
{
    $ids=array_values(array_unique(array_filter(
        array_map(
            'intval',
            preg_split('/[^0-9]+/',$raw)
        ),
        fn($v)=>$v>0
    )));

    if(count($ids)>20){
        throw new RuntimeException(
            'Maximum 20 items can be selected at once.'
        );
    }

    if(!$ids){
        throw new RuntimeException('Nothing selected.');
    }

    return $ids;
}

function v4In(array $ids): string
{
    return implode(',',array_map('intval',$ids));
}

function v4Rows(
    PDO $db,
    string $sql,
    array $args=[]
): array {
    try{
        $q=$db->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable){
        return [];
    }
}

function v4HardDeleteAccount(PDO $db,int $id,string $by): void
{
    $q=$db->prepare(
        'SELECT * FROM accounts
         WHERE account_id=:id LIMIT 1'
    );
    $q->execute(['id'=>$id]);

    $account=$q->fetch(PDO::FETCH_ASSOC);

    if(!$account){
        throw new RuntimeException(
            "Account #$id not found"
        );
    }

    $levels=v4Rows(
        $db,
        'SELECT * FROM levels WHERE account_id=:id',
        ['id'=>$id]
    );

    $levelIds=array_map(
        fn($x)=>(int)$x['level_id'],
        $levels
    );

    $profile=v4Rows(
        $db,
        'SELECT * FROM profiles WHERE account_id=:id',
        ['id'=>$id]
    );

    $comments=v4Rows(
        $db,
        'SELECT * FROM comments WHERE account_id=:id',
        ['id'=>$id]
    );

    $accComments=v4Rows(
        $db,
        'SELECT * FROM account_comments WHERE account_id=:id',
        ['id'=>$id]
    );

    $messages=v4Rows(
        $db,
        'SELECT * FROM messages
         WHERE account_id=:a OR to_account_id=:b',
        ['a'=>$id,'b'=>$id]
    );

    $friendRequests=v4Rows(
        $db,
        'SELECT * FROM friend_requests
         WHERE account_id=:a OR to_account_id=:b',
        ['a'=>$id,'b'=>$id]
    );

    $friends=v4Rows(
        $db,
        'SELECT * FROM friends
         WHERE account_id=:a OR friend_account_id=:b',
        ['a'=>$id,'b'=>$id]
    );

    $blocks=v4Rows(
        $db,
        'SELECT * FROM blocks
         WHERE account_id=:a OR blocked_account_id=:b',
        ['a'=>$id,'b'=>$id]
    );

    $likes=v4Rows(
        $db,
        'SELECT * FROM likes WHERE account_id=:id',
        ['id'=>$id]
    );

    /*
     * Account deletion snapshots are for recovery/audit. Do not retain
     * password or GJP2 credential hashes after the account is deleted.
     */
    unset(
        $account['password_hash'],
        $account['gjp2_hash']
    );

    $snapshot=json_encode([
        'account'=>$account,
        'profile'=>$profile,
        'levels'=>$levels,
        'comments'=>$comments,
        'account_comments'=>$accComments,
        'messages'=>$messages,
        'friend_requests'=>$friendRequests,
        'friends'=>$friends,
        'blocks'=>$blocks,
        'likes'=>$likes,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    if($snapshot===false){
        throw new RuntimeException('Snapshot failed.');
    }

    $db->beginTransaction();

    try{
        $q=$db->prepare(
            'INSERT INTO admin_deleted_accounts_v4
             (account_id,username,snapshot,deleted_by)
             VALUES (:id,:u,:s,:b)'
        );

        $q->execute([
            'id'=>$id,
            'u'=>$account['username'] ?? null,
            's'=>$snapshot,
            'b'=>$by
        ]);

        if(tableExists($db,'likes')){
            $q=$db->prepare(
                'DELETE FROM likes WHERE account_id=:id'
            );
            $q->execute(['id'=>$id]);

            if($levelIds){
                $db->exec(
                    'DELETE FROM likes
                     WHERE type=1 AND item_id IN ('.
                    v4In($levelIds).')'
                );
            }
        }

        foreach([
            ['messages','account_id','to_account_id'],
            ['friend_requests','account_id','to_account_id'],
            ['friends','account_id','friend_account_id'],
            ['blocks','account_id','blocked_account_id'],
        ] as [$table,$a,$b]){
            if(!tableExists($db,$table)) continue;

            $q=$db->prepare(
                "DELETE FROM `$table`
                 WHERE `$a`=:a OR `$b`=:b"
            );

            $q->execute(['a'=>$id,'b'=>$id]);
        }

        if(tableExists($db,'comments')){
            $q=$db->prepare(
                'DELETE FROM comments WHERE account_id=:id'
            );
            $q->execute(['id'=>$id]);

            if($levelIds){
                $db->exec(
                    'DELETE FROM comments
                     WHERE level_id IN ('.
                    v4In($levelIds).')'
                );
            }
        }

        if(tableExists($db,'account_comments')){
            $q=$db->prepare(
                'DELETE FROM account_comments
                 WHERE account_id=:id'
            );
            $q->execute(['id'=>$id]);
        }

        $q=$db->prepare(
            'DELETE FROM levels WHERE account_id=:id'
        );
        $q->execute(['id'=>$id]);

        $q=$db->prepare(
            'DELETE FROM profiles WHERE account_id=:id'
        );
        $q->execute(['id'=>$id]);

        $q=$db->prepare(
            'DELETE FROM accounts WHERE account_id=:id'
        );
        $q->execute(['id'=>$id]);

        if($q->rowCount()!==1){
            throw new RuntimeException(
                "Account #$id was not deleted"
            );
        }

        $db->commit();

    }catch(Throwable $e){
        if($db->inTransaction()){
            $db->rollBack();
        }

        throw $e;
    }
}
