<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Actions — Administrators
 *
 * admin-create
 * admin-toggle
 * 2fa-generate
 * 2fa-enable
 * 2fa-disable
 *
 * Extracted from verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */

if(
    !in_array(
        $action,
        [
            'admin-create',
            'admin-toggle',
            '2fa-generate',
            '2fa-enable',
            '2fa-disable'
        ],
        true
    )
){
    throw new RuntimeException(
        'Invalid Administrators action.'
    );
}

try {

if ($action==='admin-create') {
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
    ?? 'admins'
);

header(
    'Location:/admin/?page='.
    rawurlencode($return)
);

exit;
