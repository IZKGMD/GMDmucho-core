<?php

declare(strict_types=1);

namespace MuchoCore\Score;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDO;
use Throwable;

/*
 * MuchoCore Platformer Level Scores
 * Copyright (C) 2026 IZK
 */
final readonly class PlatformerScoreController
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth
    ) {}


    public function handle(Request $request): Response
    {
        $d = !empty($request->post)
            ? $request->post
            : $_POST;

        $accountId=(int)($d['accountID'] ?? 0);
        $levelId=(int)($d['levelID'] ?? 0);

        $gjp = $request->gdCredential();

        $mode=(int)($d['mode'] ?? 0);
        $type=(int)($d['type'] ?? 0);

        if (
            $accountId<=0 ||
            $levelId<=0 ||
            $gjp==='' ||
            !in_array($mode,[0,1],true) ||
            !in_array($type,[0,1,2],true)
        ) {
            return Response::text('-1');
        }

        try {
            $this->auth->authenticate($accountId,$gjp);

            if(!$this->isPlatformer($levelId)){
                return Response::text('-1');
            }

            $time=min(
                86_400_000,
                max(0,(int)($d['time'] ?? 0))
            );

            $points=min(
                10_000_000,
                max(0,(int)($d['points'] ?? 0))
            );

            /*
             * Cvolton only stores platformer results with a valid time.
             * Points-mode still works because the same row carries points.
             */
            if($time>0){
                $this->save(
                    $accountId,
                    $levelId,
                    $time,
                    $points,
                    $mode
                );
            }

            return Response::text(
                $this->leaderboard(
                    $accountId,
                    $levelId,
                    $mode,
                    $type
                )
            );

        } catch(Throwable) {
            return Response::text('-1');
        }
    }


    private function isPlatformer(int $levelId): bool
    {
        $q=$this->db->prepare("
            SELECT 1
            FROM levels
            WHERE level_id=:id
              AND length=5
              AND COALESCE(is_deleted,0)=0
            LIMIT 1
        ");

        $q->execute(['id'=>$levelId]);

        return (bool)$q->fetchColumn();
    }


    private function save(
        int $accountId,
        int $levelId,
        int $time,
        int $points,
        int $mode
    ): void {
        $now=time();

        $q=$this->db->prepare("
            INSERT INTO mucho_platformer_scores
            (
                account_id,
                level_id,
                time_ms,
                points,
                created_at,
                updated_at
            )
            VALUES
            (
                :account,
                :level,
                :time,
                :points,
                :created,
                :updated
            )
            ON DUPLICATE KEY UPDATE
                time_ms=IF(
                    :mode_time=1 AND (time_ms<=0 OR VALUES(time_ms)<time_ms),
                    VALUES(time_ms),
                    time_ms
                ),
                points=IF(
                    :mode_points=1 AND VALUES(points)>points,
                    VALUES(points),
                    points
                ),
                updated_at=IF(
                    (:mode_time=1 AND (time_ms<=0 OR VALUES(time_ms)<time_ms))
                    OR
                    (:mode_points=1 AND VALUES(points)>points),
                    VALUES(updated_at),
                    updated_at
                )
        ");

        $q->execute([
            'account'=>$accountId,
            'level'=>$levelId,
            'time'=>$time,
            'points'=>$points,
            'created'=>$now,
            'updated'=>$now,
            'mode_time'=>$mode===0 ? 1 : 0,
            'mode_points'=>$mode===1 ? 1 : 0,
        ]);
    }

    private function leaderboard(
        int $accountId,
        int $levelId,
        int $mode,
        int $type
    ): string {

        $params=[
            'level'=>$levelId,
        ];

        $where=[
            's.level_id=:level',
            's.time_ms>0',
            'a.is_banned=0',
        ];

        if($type===0){

            $friends=$this->friendIds($accountId);
            $holders=[];

            foreach($friends as $i=>$id){
                $key='f'.$i;
                $holders[]=':'.$key;
                $params[$key]=$id;
            }

            if(!$holders){
                return '';
            }

            $where[]=
                's.account_id IN ('.
                implode(',',$holders).
                ')';
        }

        if($type===2){
            $where[]='s.updated_at>:recent';
            $params['recent']=time()-604800;
        }

        $scoreColumn=
            $mode===0
                ? 's.time_ms'
                : 's.points';

        $direction=
            $mode===0
                ? 'ASC'
                : 'DESC';

        $sql="
            SELECT
                s.account_id,
                s.time_ms,
                s.points,
                s.updated_at,

                a.username,

                COALESCE(p.user_id,s.account_id) AS user_id,
                COALESCE(p.cube,1) AS cube,
                COALESCE(p.color1,0) AS color1,
                COALESCE(p.color2,3) AS color2,
                COALESCE(p.color3,0) AS color3,
                COALESCE(p.special,0) AS special

            FROM mucho_platformer_scores s

            JOIN accounts a
              ON a.account_id=s.account_id

            LEFT JOIN profiles p
              ON p.account_id=s.account_id

            WHERE ".implode(' AND ',$where)."

            ORDER BY
                ".$scoreColumn." ".$direction.",
                s.updated_at ASC

            LIMIT 100
        ";

        $q=$this->db->prepare($sql);
        $q->execute($params);

        $rows=$q->fetchAll(PDO::FETCH_ASSOC);

        if(!$rows){
            return '';
        }

        $out=[];
        $rank=0;

        foreach($rows as $row){

            $rank++;

            $score=
                $mode===0
                    ? (int)$row['time_ms']
                    : (int)$row['points'];

            $out[]=
                '1:'.\MuchoCore\Protocol\ProtocolText::username(
                    $row['username'] ?? 'Player'
                ).
                ':2:'.(int)$row['user_id'].
                ':9:'.(int)$row['cube'].
                ':10:'.(int)$row['color1'].
                ':11:'.(int)$row['color2'].
                ':51:'.(int)$row['color3'].
                ':14:0'.
                ':15:'.(int)$row['special'].
                ':16:'.(int)$row['account_id'].
                ':3:'.$score.
                ':6:'.$rank.
                ':42:'.gmdate(
                    'd/m/Y G.i',
                    (int)$row['updated_at']
                );
        }

        return implode('|',$out);
    }


    private function friendIds(int $accountId): array
    {
        $ids=[
            $accountId=>$accountId
        ];

        $pairs=[
            ['account_id','friend_account_id'],
            ['account_id','friend_id'],
            ['account_id_1','account_id_2'],
            ['account1_id','account2_id'],
            ['user_id','friend_id'],
            ['user1','user2'],
        ];

        foreach(['friends','friendships'] as $table){

            try {
                $columns=$this->db
                    ->query("SHOW COLUMNS FROM `".$table."`")
                    ->fetchAll(PDO::FETCH_COLUMN);
            } catch(Throwable) {
                continue;
            }

            foreach($pairs as [$a,$b]){

                if(
                    !in_array($a,$columns,true) ||
                    !in_array($b,$columns,true)
                ){
                    continue;
                }

                $q=$this->db->prepare(
                    "SELECT `$a` a, `$b` b
                     FROM `$table`
                     WHERE `$a`=:left_id
                        OR `$b`=:right_id"
                );

                $q->execute([
                    'left_id'=>$accountId,
                    'right_id'=>$accountId,
                ]);

                foreach(
                    $q->fetchAll(PDO::FETCH_ASSOC)
                    as $row
                ){
                    $x=(int)$row['a'];
                    $y=(int)$row['b'];

                    if($x===$accountId && $y>0){
                        $ids[$y]=$y;
                    }

                    if($y===$accountId && $x>0){
                        $ids[$x]=$x;
                    }
                }

                return array_values($ids);
            }
        }

        return array_values($ids);
    }


}
