<?php
declare(strict_types=1);

/*
 * MuchoCore Public Dashboard API
 * Copyright (C) 2026 IZK
 */

require_once __DIR__.'/bootstrap.php';

muchoV2RequireMethod('GET');

function tableExistsPublic(PDO $db,string $table): bool
{
    $q=$db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema=DATABASE()
          AND table_name=?
        LIMIT 1
    ");
    $q->execute([$table]);

    return (bool)$q->fetchColumn();
}

function countPublic(PDO $db,string $table): int
{
    if (!tableExistsPublic($db,$table)) return 0;

    return (int)$db->query(
        "SELECT COUNT(*) FROM `$table`"
    )->fetchColumn();
}

function pick(array $row,array $names,mixed $default=null): mixed
{
    foreach($names as $name){
        if (
            array_key_exists($name,$row) &&
            $row[$name] !== null &&
            $row[$name] !== ''
        ){
            return $row[$name];
        }
    }

    return $default;
}

try {

    $db=muchoV2Db();

    $stats=[
        'players'=>countPublic($db,'accounts'),
        'levels'=>countPublic($db,'levels'),
        'comments'=>countPublic($db,'comments')
    ];

    $latest=[];

    if (tableExistsPublic($db,'levels')) {

        $columns=$db->query(
            'SHOW COLUMNS FROM levels'
        )->fetchAll(PDO::FETCH_COLUMN);

        $order=null;

        foreach([
            'levelID',
            'level_id',
            'id'
        ] as $candidate){
            if(in_array($candidate,$columns,true)){
                $order=$candidate;
                break;
            }
        }

        $sql='SELECT * FROM levels';

        if($order){
            $sql.=" ORDER BY `$order` DESC";
        }

        $sql.=' LIMIT 6';

        foreach($db->query($sql)->fetchAll() as $row){

            $id=(int)pick(
                $row,
                ['levelID','level_id','id'],
                0
            );

            $name=(string)pick(
                $row,
                ['levelName','level_name','name'],
                'Unnamed Level'
            );

            $creator=(string)pick(
                $row,
                [
                    'userName',
                    'username',
                    'creatorName',
                    'creator_name'
                ],
                ''
            );

            if($creator===''){
                $uid=pick(
                    $row,
                    ['userID','user_id','accountID','account_id'],
                    null
                );

                $creator=$uid!==null
                    ? 'User #'.(int)$uid
                    : 'Unknown';
            }

            $latest[]=[
                'id'=>$id,
                'name'=>$name,
                'creator'=>$creator,
                'downloads'=>(int)pick(
                    $row,
                    ['downloads','downloadCount'],
                    0
                ),
                'likes'=>(int)pick(
                    $row,
                    ['likes','likeCount'],
                    0
                ),
                'stars'=>(int)pick(
                    $row,
                    ['stars','starStars'],
                    0
                )
            ];
        }
    }

    muchoV2Send([
        'ok'=>true,
        'api'=>'MuchoCore',
        'version'=>defined('MUCHO_V2_VERSION')
            ? MUCHO_V2_VERSION
            : '2',
        'stats'=>$stats,
        'latest_levels'=>$latest
    ]);

} catch(Throwable $e){

    error_log(
        '[MuchoCore Public Dashboard] '.
        $e->getMessage()
    );

    muchoV2Fail('internal_error',500);
}
