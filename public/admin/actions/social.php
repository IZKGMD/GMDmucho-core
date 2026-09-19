<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Actions — Social
 *
 * comment-delete
 * message-delete
 * relation-delete
 *
 * Extracted from verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */

if(
    !in_array(
        $action,
        [
            'comment-delete',
            'message-delete',
            'relation-delete'
        ],
        true
    )
){
    throw new RuntimeException(
        'Invalid Social action.'
    );
}

try {

if ($action==='comment-delete') {
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

} catch(Throwable $e) {

    flash(
        'Error: '.$e->getMessage(),
        'error'
    );
}

$return=(string)(
    $_POST['return']
    ?? $_GET['page']
    ?? 'moderation'
);

header(
    'Location:/admin/?page='.
    rawurlencode($return)
);

exit;
