<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Actions — Levels
 *
 * level-save
 *
 * Extracted from verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */

if($action!=='level-save'){
    throw new RuntimeException(
        'Invalid Levels action.'
    );
}

try {

if ($action==='level-save') {
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

} catch(Throwable $e) {

    flash(
        'Error: '.$e->getMessage(),
        'error'
    );
}

$return=(string)(
    $_POST['return']
    ?? $_GET['page']
    ?? 'levels'
);

header(
    'Location:/admin/?page='.
    rawurlencode($return)
);

exit;
