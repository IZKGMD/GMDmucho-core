<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Actions — Profiles
 *
 * profile-save
 * muchoprofile-save
 * muchoprofile-token
 *
 * Extracted from verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */

if(
    !in_array(
        $action,
        [
            'profile-save',
            'muchoprofile-save',
            'muchoprofile-token'
        ],
        true
    )
){
    throw new RuntimeException(
        'Invalid Profiles action.'
    );
}

try {

if ($action==='profile-save') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $fields=[
                'stars',
                'moons',
                'diamonds',
                'secret_coins',
                'user_coins',
                'demons',
                'creator_points'
            ];

            $set=[];
            $args=['id'=>$id];

            foreach($fields as $f) {
                $set[]="`$f`=:$f";
                $args[$f]=max(
                    0,
                    (int)($_POST[$f] ?? 0)
                );
            }

            $q=$db->prepare(
                'UPDATE profiles SET '.
                implode(',',$set).
                ' WHERE account_id=:id'
            );

            $q->execute($args);

            audit($db,'profile.save',(string)$id);
            flash('Statistics saved.');
        }


        /* MUCHO_PROFILE_ADMIN_V1_ACTIONS */

        elseif ($action==='muchoprofile-save') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid Account ID.');
            }

            $check=$db->prepare(
                'SELECT account_id FROM accounts
                 WHERE account_id=:id LIMIT 1'
            );
            $check->execute(['id'=>$id]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Account not found.');
            }

            $db->exec("
                CREATE TABLE IF NOT EXISTS mucho_profile_customization (
                    account_id BIGINT PRIMARY KEY,
                    display_name VARCHAR(32) NOT NULL DEFAULT '',
                    status VARCHAR(48) NOT NULL DEFAULT '',
                    bio VARCHAR(240) NOT NULL DEFAULT '',
                    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
                    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
                    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
                    title VARCHAR(48) NOT NULL DEFAULT '',
                    badges TEXT NOT NULL,
                    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
                    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
                    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
                    online_visible TINYINT NOT NULL DEFAULT 1,
                    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $ensure=$db->prepare("
                INSERT INTO mucho_profile_customization
                (
                    account_id,
                    badges
                )
                VALUES
                (
                    :id,
                    '[]'
                )
                ON DUPLICATE KEY UPDATE
                    account_id=VALUES(account_id)
            ");
            $ensure->execute(['id'=>$id]);

            $cut=static function(string $v,int $max): string {
                $v=trim(str_replace(["\0","\r"],'',$v));

                return function_exists('mb_substr')
                    ? mb_substr($v,0,$max,'UTF-8')
                    : substr($v,0,$max);
            };

            $displayName=$cut(
                (string)($_POST['display_name'] ?? ''),
                32
            );

            $status=$cut(
                (string)($_POST['status'] ?? ''),
                48
            );

            $bio=$cut(
                (string)($_POST['bio'] ?? ''),
                240
            );

            $title=$cut(
                (string)($_POST['title'] ?? ''),
                48
            );

            $primary=strtoupper(
                trim((string)($_POST['theme_primary'] ?? ''))
            );

            if (!preg_match('/^#[0-9A-F]{6}$/',$primary)) {
                $primary='#42D9CF';
            }

            $secondary=strtoupper(
                trim((string)($_POST['theme_secondary'] ?? ''))
            );

            if (!preg_match('/^#[0-9A-F]{6}$/',$secondary)) {
                $secondary='#806EFF';
            }

            $banner=trim(
                (string)($_POST['banner'] ?? 'gradient_01')
            );

            if (!preg_match('/^[A-Za-z0-9_-]{0,64}$/',$banner)) {
                $banner='gradient_01';
            }

            $favorite=$cut(
                (string)($_POST['favorite_difficulty'] ?? ''),
                32
            );

            $showcase=$cut(
                (string)($_POST['showcase'] ?? ''),
                200
            );

            /*
             * Badges are controlled by administrators only.
             * Accept:
             * OWNER, IZK
             * or JSON ["OWNER","IZK"]
             */
            $badgeInput=trim(
                (string)($_POST['badges'] ?? '')
            );

            $badges=[];

            if ($badgeInput!=='') {
                if (str_starts_with($badgeInput,'[')) {
                    $decoded=json_decode($badgeInput,true);

                    if (is_array($decoded)) {
                        $badges=$decoded;
                    }
                } else {
                    $badges=preg_split(
                        '/[,\r\n]+/',
                        $badgeInput
                    ) ?: [];
                }
            }

            $cleanBadges=[];

            foreach($badges as $badge) {
                $badge=$cut((string)$badge,32);

                if ($badge==='') {
                    continue;
                }

                if (!in_array($badge,$cleanBadges,true)) {
                    $cleanBadges[]=$badge;
                }

                if (count($cleanBadges)>=8) {
                    break;
                }
            }

            $badgeJson=json_encode(
                $cleanBadges,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $pinned=[];

            foreach(
                preg_split(
                    '/[,\s]+/',
                    trim((string)($_POST['pinned_levels'] ?? ''))
                ) ?: []
                as $levelID
            ) {
                if (
                    $levelID!=='' &&
                    ctype_digit($levelID) &&
                    (int)$levelID>0
                ) {
                    $pinned[]=(string)(int)$levelID;
                }

                if (count($pinned)>=3) {
                    break;
                }
            }

            $q=$db->prepare("
                UPDATE mucho_profile_customization SET
                    display_name=:display_name,
                    status=:status,
                    bio=:bio,
                    theme_primary=:primary,
                    theme_secondary=:secondary,
                    banner=:banner,
                    title=:title,
                    badges=:badges,
                    pinned_levels=:pinned,
                    showcase=:showcase,
                    favorite_difficulty=:favorite,
                    online_visible=:online
                WHERE account_id=:id
            ");

            $q->execute([
                'display_name'=>$displayName,
                'status'=>$status,
                'bio'=>$bio,
                'primary'=>$primary,
                'secondary'=>$secondary,
                'banner'=>$banner,
                'title'=>$title,
                'badges'=>$badgeJson,
                'pinned'=>implode(',',$pinned),
                'showcase'=>$showcase,
                'favorite'=>$favorite,
                'online'=>isset($_POST['online_visible']) ? 1 : 0,
                'id'=>$id
            ]);

            audit(
                $db,
                'muchoprofile.save',
                (string)$id,
                [
                    'title'=>$title,
                    'badges'=>$cleanBadges
                ]
            );

            flash('Mucho Profile saved.');
        }

        elseif ($action==='muchoprofile-token') {
            requireRank(30);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid Account ID.');
            }

            $check=$db->prepare(
                'SELECT account_id FROM accounts
                 WHERE account_id=:id LIMIT 1'
            );
            $check->execute(['id'=>$id]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Account not found.');
            }

            $db->exec("
                CREATE TABLE IF NOT EXISTS mucho_profile_customization (
                    account_id BIGINT PRIMARY KEY,
                    display_name VARCHAR(32) NOT NULL DEFAULT '',
                    status VARCHAR(48) NOT NULL DEFAULT '',
                    bio VARCHAR(240) NOT NULL DEFAULT '',
                    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
                    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
                    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
                    title VARCHAR(48) NOT NULL DEFAULT '',
                    badges TEXT NOT NULL,
                    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
                    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
                    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
                    online_visible TINYINT NOT NULL DEFAULT 1,
                    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $ensure=$db->prepare("
                INSERT INTO mucho_profile_customization
                (account_id,badges)
                VALUES (:id,'[]')
                ON DUPLICATE KEY UPDATE
                    account_id=VALUES(account_id)
            ");
            $ensure->execute(['id'=>$id]);

            $token=rtrim(
                strtr(
                    base64_encode(random_bytes(24)),
                    '+/',
                    '-_'
                ),
                '='
            );

            $q=$db->prepare("
                UPDATE mucho_profile_customization
                SET edit_token_hash=:hash
                WHERE account_id=:id
            ");

            $q->execute([
                'hash'=>hash('sha256',$token),
                'id'=>$id
            ]);

            $_SESSION['muchoprofile_issued_token']=[
                'id'=>$id,
                'token'=>$token
            ];

            audit(
                $db,
                'muchoprofile.token.regenerate',
                (string)$id
            );

            flash(
                'New Mucho Profile edit token generated.'
            );
        }

} catch(Throwable $e) {

    flash(
        'Error: '.$e->getMessage(),
        'error'
    );
}

$return=(string)(
    $_POST['return']
    ?? $_GET['page']
    ?? 'muchoprofiles'
);

header(
    'Location:/admin/?page='.
    rawurlencode($return)
);

exit;
