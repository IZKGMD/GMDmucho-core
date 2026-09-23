<?php
declare(strict_types=1);

/*
 * MuchoCore Legacy Admin Action Dispatcher
 *
 * Extracted verbatim from working index.php.
 * This file is intentionally kept behavior-compatible
 * during Architecture v6 migration.
 *
 * Copyright (C) 2026 IZK
 */

if ($_SERVER['REQUEST_METHOD']==='POST') {
    checkCsrf();

    $action=(string)($_POST['action'] ?? '');

    if (str_starts_with($action,'v4-')) {
        require __DIR__.'/advanced-actions.php';
    }


    try {

        /* ACCOUNTS */

        if ($action==='account-save') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $role=(string)$_POST['role'];

            $roleQuery=$db->prepare(
                'SELECT id
                 FROM roles
                 WHERE code=:role
                 LIMIT 1'
            );

            $roleQuery->execute([
                'role'=>$role
            ]);

            $roleId=$roleQuery->fetchColumn();

            if($roleId===false) {
                throw new RuntimeException('Bad role');
            }

            $q=$db->prepare(
                'UPDATE accounts SET
                    username=:username,
                    email=:email,
                    role_id=:role_id,
                    is_active=:active,
                    is_banned=:banned
                 WHERE account_id=:id'
            );

            $q->execute([
                'username'=>substr(
                    trim((string)$_POST['username']),
                    0,
                    20
                ),
                'email'=>substr(
                    trim((string)$_POST['email']),
                    0,
                    254
                ),
                'role_id'=>(int)$roleId,
                'active'=>isset($_POST['active'])?1:0,
                'banned'=>isset($_POST['banned'])?1:0,
                'id'=>$id
            ]);

            audit($db,'account.save',(string)$id);
            flash('Account saved.');
        }

        elseif ($action==='profile-save') {
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

        elseif ($action==='password-reset') {
            requireRank(30);

            $id=(int)$_POST['id'];
            $password=(string)$_POST['new_password'];

            if (strlen($password)<8) {
                throw new RuntimeException(
                    'Password must be at least 8 characters.'
                );
            }

            $gjp2=sha1(
                $password.'mI29fmAnxgTs'
            );

            $q=$db->prepare(
                'UPDATE accounts SET
                    password_hash=:p,
                    gjp2_hash=:g
                 WHERE account_id=:id'
            );

            $q->execute([
                'p'=>password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'g'=>password_hash(
                    $gjp2,
                    PASSWORD_DEFAULT
                ),
                'id'=>$id
            ]);

            audit($db,'account.password.reset',(string)$id);
            flash('Password changed.');
        }

        elseif ($action==='account-delete') {
            requireRank(40);

            $id=(int)($_POST['id'] ?? 0);

            if ($id<=0) {
                throw new RuntimeException('Invalid account.');
            }

            $q=$db->prepare(
                'SELECT * FROM accounts
                 WHERE account_id=:id LIMIT 1'
            );
            $q->execute(['id'=>$id]);
            $account=$q->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                throw new RuntimeException(
                    'Account not found.'
                );
            }

            $q=$db->prepare(
                'SELECT * FROM profiles
                 WHERE account_id=:id LIMIT 1'
            );
            $q->execute(['id'=>$id]);
            $profile=$q->fetch(PDO::FETCH_ASSOC);

            $db->exec("
                CREATE TABLE IF NOT EXISTS admin_deleted_accounts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    account_id BIGINT UNSIGNED NOT NULL,
                    username VARCHAR(64) NULL,
                    snapshot LONGTEXT NOT NULL,
                    deleted_by VARCHAR(64) NOT NULL,
                    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_deleted_account(account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $snapshot=json_encode(
                [
                    'account'=>$account,
                    'profile'=>$profile
                ],
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $db->beginTransaction();

            try {
                $q=$db->prepare(
                    'INSERT INTO admin_deleted_accounts
                     (account_id,username,snapshot,deleted_by)
                     VALUES (:id,:username,:snapshot,:by)'
                );

                $q->execute([
                    'id'=>$id,
                    'username'=>$account['username'] ?? null,
                    'snapshot'=>$snapshot,
                    'by'=>admin()['username']
                ]);

                /* User level IDs */
                $q=$db->prepare(
                    'SELECT level_id FROM levels
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                $levelIds=array_map(
                    'intval',
                    $q->fetchAll(PDO::FETCH_COLUMN)
                );

                /* User comments + comments on user's levels */
                $commentIds=[];

                if (tableExists($db,'comments')) {
                    if ($levelIds) {
                        $in=implode(',',$levelIds);

                        $q=$db->prepare(
                            "SELECT id FROM comments
                             WHERE account_id=:id
                                OR level_id IN ($in)"
                        );
                    } else {
                        $q=$db->prepare(
                            'SELECT id FROM comments
                             WHERE account_id=:id'
                        );
                    }

                    $q->execute(['id'=>$id]);

                    $commentIds=array_map(
                        'intval',
                        $q->fetchAll(PDO::FETCH_COLUMN)
                    );
                }

                /* Likes */
                if (tableExists($db,'likes')) {
                    $q=$db->prepare(
                        'DELETE FROM likes
                         WHERE account_id=:id'
                    );
                    $q->execute(['id'=>$id]);

                    if ($levelIds) {
                        $db->exec(
                            'DELETE FROM likes
                             WHERE type=1
                               AND item_id IN ('.
                            implode(',',$levelIds).
                            ')'
                        );
                    }

                    if ($commentIds) {
                        $db->exec(
                            'DELETE FROM likes
                             WHERE type=2
                               AND item_id IN ('.
                            implode(',',$commentIds).
                            ')'
                        );
                    }
                }

                /* Messages */
                if (tableExists($db,'messages')) {
                    $q=$db->prepare(
                        'DELETE FROM messages
                         WHERE account_id=:a
                            OR to_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Friend requests */
                if (tableExists($db,'friend_requests')) {
                    $q=$db->prepare(
                        'DELETE FROM friend_requests
                         WHERE account_id=:a
                            OR to_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Friends */
                if (tableExists($db,'friends')) {
                    $q=$db->prepare(
                        'DELETE FROM friends
                         WHERE account_id=:a
                            OR friend_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Blocks */
                if (tableExists($db,'blocks')) {
                    $q=$db->prepare(
                        'DELETE FROM blocks
                         WHERE account_id=:a
                            OR blocked_account_id=:b'
                    );
                    $q->execute([
                        'a'=>$id,
                        'b'=>$id
                    ]);
                }

                /* Comments */
                if (tableExists($db,'comments')) {
                    if ($levelIds) {
                        $in=implode(',',$levelIds);

                        $q=$db->prepare(
                            "DELETE FROM comments
                             WHERE account_id=:id
                                OR level_id IN ($in)"
                        );
                    } else {
                        $q=$db->prepare(
                            'DELETE FROM comments
                             WHERE account_id=:id'
                        );
                    }

                    $q->execute(['id'=>$id]);
                }

                if (tableExists($db,'account_comments')) {
                    $q=$db->prepare(
                        'DELETE FROM account_comments
                         WHERE account_id=:id'
                    );
                    $q->execute(['id'=>$id]);
                }

                /* Levels */
                $q=$db->prepare(
                    'DELETE FROM levels
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                /* Profile */
                $q=$db->prepare(
                    'DELETE FROM profiles
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                /* Account */
                $q=$db->prepare(
                    'DELETE FROM accounts
                     WHERE account_id=:id'
                );
                $q->execute(['id'=>$id]);

                if ($q->rowCount()!==1) {
                    throw new RuntimeException(
                        'Failed to delete account.'
                    );
                }

                $db->commit();

                audit(
                    $db,
                    'account.delete',
                    (string)$id,
                    [
                        'username'=>$account['username']
                    ]
                );

                flash(
                    'Account #'.$id.' permanently deleted.'
                );

            } catch(Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $e;
            }
        }

        /* LEVELS */

        elseif ($action==='level-save') {
            requireRank(20);

            $id=(int)$_POST['id'];

            $q=$db->prepare(
                'UPDATE levels SET
                    name=:name,
                    stars=:stars,
                    difficulty=:difficulty,
                    demon=:demon,
                    demon_difficulty=:dd,
                    featured=:featured,
                    epic=:epic,
                    requested_stars=:requested,
                    downloads=:downloads,
                    likes=:likes,
                    is_deleted=:deleted
                 WHERE level_id=:id'
            );

            $q->execute([
                'name'=>substr(
                    (string)$_POST['name'],
                    0,
                    100
                ),
                'stars'=>max(
                    0,
                    min(10,(int)$_POST['stars'])
                ),
                'difficulty'=>max(
                    0,
                    (int)$_POST['difficulty']
                ),
                'demon'=>isset($_POST['demon'])?1:0,
                'dd'=>max(
                    0,
                    (int)($_POST['demon_difficulty'] ?? 0)
                ),
                'featured'=>isset($_POST['featured'])?1:0,
                'epic'=>max(0,(int)$_POST['epic']),
                'requested'=>max(
                    0,
                    min(10,(int)$_POST['requested_stars'])
                ),
                'downloads'=>max(
                    0,
                    (int)$_POST['downloads']
                ),
                'likes'=>(int)$_POST['likes'],
                'deleted'=>isset($_POST['deleted'])?1:0,
                'id'=>$id
            ]);

            audit($db,'level.save',(string)$id);
            flash('Level saved.');
        }

        elseif ($action==='comment-delete') {
            requireRank(20);

            $table=(string)$_POST['table'];
            $id=(int)$_POST['id'];

            if (!in_array(
                $table,
                ['comments','account_comments'],
                true
            )) {
                throw new RuntimeException('Invalid table');
            }

            $q=$db->prepare(
                "DELETE FROM `$table` WHERE id=:id"
            );
            $q->execute(['id'=>$id]);

            audit(
                $db,
                'comment.delete',
                "$table:$id"
            );

            flash('Comment deleted.');
        }

        elseif ($action==='message-delete') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $q=$db->prepare(
                'DELETE FROM messages WHERE id=:id'
            );
            $q->execute(['id'=>$id]);

            audit($db,'message.delete',(string)$id);
            flash('Message deleted.');
        }

        elseif ($action==='relation-delete') {
            requireRank(20);

            $table=(string)$_POST['table'];
            $id=(int)$_POST['id'];

            if (!in_array(
                $table,
                [
                    'friends',
                    'blocks',
                    'friend_requests'
                ],
                true
            )) {
                throw new RuntimeException('Invalid relation');
            }

            $q=$db->prepare(
                "DELETE FROM `$table` WHERE id=:id"
            );

            $q->execute(['id'=>$id]);

            audit(
                $db,
                'relation.delete',
                "$table:$id"
            );

            flash('Relation deleted.');
        }

        /* SETTINGS */

        elseif ($action==='settings-save') {
            requireRank(40);

            if (isset($_POST['maintenance'])) {
                file_put_contents(
                    CONTROL_DIR.'/maintenance.flag',
                    '1'
                );
            } else {
                @unlink(
                    CONTROL_DIR.'/maintenance.flag'
                );
            }

            if (isset($_POST['registrations_disabled'])) {
                file_put_contents(
                    CONTROL_DIR.'/registrations-disabled.flag',
                    '1'
                );
            } else {
                @unlink(
                    CONTROL_DIR.'/registrations-disabled.flag'
                );
            }

            audit($db,'settings.save');
            flash('Settings applied.');
        }

        /* ENDPOINT TESTER */

        elseif ($action==='endpoint-test') {
            requireRank(30);

            $endpoint=trim(
                (string)$_POST['endpoint']
            );

            $raw=trim(
                (string)$_POST['payload']
            );

            parse_str($raw,$data);

            $_SESSION['endpoint_result']=
                postLocal(
                    $endpoint,
                    is_array($data)?$data:[]
                );

            audit(
                $db,
                'endpoint.test',
                $endpoint
            );
        }

        /* SYSTEM */

        elseif ($action==='system-op') {
            requireRank(40);

            $op=(string)$_POST['op'];

            $_SESSION['system_output']=rootOp($op);

            audit($db,'system.'.$op);
            flash('Operation completed.');
        }

        /* ADMINS */

        elseif ($action==='admin-create') {
            requireRank(40);

            $username=trim(
                (string)$_POST['username']
            );

            $password=(string)$_POST['password'];
            $role=(string)$_POST['role'];

            if (
                !preg_match(
                    '/^[A-Za-z0-9_.-]{3,32}$/',
                    $username
                )
            ) {
                throw new RuntimeException(
                    'Invalid username.'
                );
            }

            if (strlen($password)<10) {
                throw new RuntimeException(
                    'Password must be at least 10 characters.'
                );
            }

            if (!in_array(
                $role,
                [
                    'owner',
                    'admin',
                    'moderator',
                    'viewer'
                ],
                true
            )) {
                throw new RuntimeException('Bad role');
            }

            $q=$db->prepare(
                'INSERT INTO admin_users
                 (username,password_hash,role)
                 VALUES (:u,:p,:r)'
            );

            $q->execute([
                'u'=>$username,
                'p'=>password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'r'=>$role
            ]);

            audit($db,'admin.create',$username);
            flash('Administrator created.');
        }

        elseif ($action==='admin-toggle') {
            requireRank(40);

            $id=(int)$_POST['id'];

            if ($id===(int)admin()['id']) {
                throw new RuntimeException(
                    'You cannot disable your own account.'
                );
            }

            $db->prepare(
                'UPDATE admin_users
                 SET is_active=1-is_active
                 WHERE id=:id'
            )->execute(['id'=>$id]);

            audit(
                $db,
                'admin.toggle',
                (string)$id
            );

            flash('Status changed.');
        }

        elseif ($action==='2fa-generate') {
            requireRank(10);

            $_SESSION['pending_totp']=
                newTotpSecret();

            flash(
                'Secret created. Add it to your authenticator app and confirm the code.'
            );
        }

        elseif ($action==='2fa-enable') {
            requireRank(10);

            $secret=$_SESSION['pending_totp']
                ?? '';

            $code=(string)$_POST['otp'];

            if (
                !$secret ||
                !verifyTotp($secret,$code)
            ) {
                throw new RuntimeException(
                    'Invalid 2FA code.'
                );
            }

            $q=$db->prepare(
                'UPDATE admin_users
                 SET totp_secret=:s
                 WHERE id=:id'
            );

            $q->execute([
                's'=>$secret,
                'id'=>admin()['id']
            ]);

            unset($_SESSION['pending_totp']);

            audit($db,'2fa.enable');
            flash('2FA enabled.');
        }

        elseif ($action==='2fa-disable') {
            requireRank(10);

            $q=$db->prepare(
                'UPDATE admin_users
                 SET totp_secret=NULL
                 WHERE id=:id'
            );

            $q->execute([
                'id'=>admin()['id']
            ]);

            audit($db,'2fa.disable');
            flash('2FA disabled.');
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
        ?? 'dashboard'
    );

    header(
        'Location:/admin/?page='.
        rawurlencode($return)
    );

    exit;
}
