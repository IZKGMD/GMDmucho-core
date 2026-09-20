<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Actions — Accounts
 *
 * account-save
 * password-reset
 * account-delete
 *
 * Extracted from the verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */

if(
    !in_array(
        $action,
        [
            'account-save',
            'password-reset',
            'account-delete'
        ],
        true
    )
){
    throw new RuntimeException(
        'Invalid Accounts action.'
    );
}

try {

        if ($action==='account-save') {
            requireRank(30);

            $id=(int)$_POST['id'];

            $legacyRoleAliases=[
                'mod'=>'moderator',
                'helper'=>'moderator',
                'elder'=>'moderator'
            ];

            $role=strtolower(trim((string)$_POST['role']));
            $role=$legacyRoleAliases[$role] ?? $role;

            $allowedRoles=[
                'user',
                'moderator',
                'admin',
                'owner'
            ];

            if (!in_array($role,$allowedRoles,true)) {
                throw new RuntimeException('Bad role');
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

            if ($roleId<=0) {
                throw new RuntimeException('Role not found');
            }

            $targetQuery=$db->prepare(
                'SELECT
                    a.account_id,
                    COALESCE(r.code, "user") AS role_code
                 FROM accounts a
                 LEFT JOIN roles r ON r.id=a.role_id
                 WHERE a.account_id=:id
                 LIMIT 1'
            );

            $targetQuery->execute(['id'=>$id]);
            $target=$targetQuery->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('Account not found');
            }

            $adminRank=rank((string)(admin()['role'] ?? ''));
            $targetRole=strtolower((string)$target['role_code']);

            if (
                $adminRank < 40 &&
                ($targetRole === 'owner' || $role === 'owner')
            ) {
                throw new RuntimeException(
                    'Only an owner can modify owner accounts or grant the owner role.'
                );
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
                'role_id'=>$roleId,
                'active'=>isset($_POST['active'])?1:0,
                'banned'=>isset($_POST['banned'])?1:0,
                'id'=>$id
            ]);

            audit($db,'account.save',(string)$id);
            flash('Account saved.');
        }

        elseif ($action==='password-reset') {
            requireRank(30);

            $id=(int)$_POST['id'];
            $password=(string)$_POST['new_password'];

            if (strlen($password)<12) {
                throw new RuntimeException(
                    'Password must be at least 12 characters.'
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
