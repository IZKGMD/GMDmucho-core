<?php

declare(strict_types=1);

/*
 * MuchoCore Admin — Gauntlet / Map Pack Maker actions.
 *
 * Copyright (C) 2026 IZK
 */

$allowed = [
    'gauntlet-save',
    'gauntlet-delete',
    'mappack-save',
    'mappack-delete',
];

if (!in_array($action, $allowed, true)) {
    throw new RuntimeException('Invalid content-pack action.');
}

requirePermission('contentpacks.manage');

function contentPackLevelIds(string $raw, int $expected): array
{
    $ids = [];

    foreach (preg_split('/[\\s,]+/', trim($raw)) ?: [] as $part) {
        if (!ctype_digit($part)) {
            continue;
        }

        $id = (int)$part;

        if ($id <= 0) {
            continue;
        }

        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    if (count($ids) !== $expected) {
        throw new RuntimeException(
            'Exactly '.$expected.' unique level IDs are required.'
        );
    }

    return $ids;
}

function assertContentPackLevels(PDO $db, array $ids): void
{
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        'SELECT level_id,name,is_deleted,is_unlisted
         FROM levels
         WHERE level_id IN ('.$marks.')'
    );
    $stmt->execute($ids);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int)$row['level_id']] = $row;
    }

    foreach ($ids as $id) {
        if (!isset($rows[$id])) {
            throw new RuntimeException('Level #'.$id.' does not exist.');
        }

        if ((int)$rows[$id]['is_deleted'] !== 0) {
            throw new RuntimeException('Level #'.$id.' is deleted.');
        }

        if ((int)$rows[$id]['is_unlisted'] !== 0) {
            throw new RuntimeException(
                'Level #'.$id.' is unlisted and cannot be published in a collection.'
            );
        }
    }
}

function contentPackLevelFieldFromPost(int $expected): string
{
    $ids = [];

    for ($i = 1; $i <= $expected; $i++) {
        $ids[] = (string)($_POST['level_'.$i] ?? '');
    }

    return implode(' ', $ids);
}

function contentPackName(string $value, int $max, string $fallback): string
{
    $value = trim(str_replace(["\0", "\r", "\n"], ' ', $value));

    if ($value === '') {
        $value = $fallback;
    }

    return mb_substr($value, 0, $max, 'UTF-8');
}

function contentPackInt(string $key, int $min, int $max): int
{
    $value = filter_var(
        $_POST[$key] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($value === false) {
        throw new RuntimeException('Invalid '.$key.'.');
    }

    return max($min, min($max, (int)$value));
}

if ($action === 'gauntlet-save') {
    $id = max(0, (int)($_POST['id'] ?? 0));
    $name = contentPackName(
        (string)($_POST['name'] ?? ''),
        96,
        'Gauntlet'
    );
    $levelIds = contentPackLevelIds(
        contentPackLevelFieldFromPost(5),
        5
    );
    assertContentPackLevels($db, $levelIds);

    $sort = contentPackInt('sort_order', -1000000, 1000000);
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    if ($id > 0) {
        $stmt = $db->prepare(
            'UPDATE mucho_gauntlets
             SET name=:name,
                 level1=:level1,
                 level2=:level2,
                 level3=:level3,
                 level4=:level4,
                 level5=:level5,
                 enabled=:enabled,
                 sort_order=:sort_order
             WHERE id=:id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'level1' => $levelIds[0],
            'level2' => $levelIds[1],
            'level3' => $levelIds[2],
            'level4' => $levelIds[3],
            'level5' => $levelIds[4],
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        $target = (string)$id;
        audit($db, 'gauntlet.save', $target, [
            'name' => $name,
            'levels' => $levelIds,
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        flash('Gauntlet #'.$id.' saved.');
    } else {
        $stmt = $db->prepare(
            'INSERT INTO mucho_gauntlets
             (name,level1,level2,level3,level4,level5,enabled,sort_order)
             VALUES (:name,:level1,:level2,:level3,:level4,:level5,:enabled,:sort_order)'
        );
        $stmt->execute([
            'name' => $name,
            'level1' => $levelIds[0],
            'level2' => $levelIds[1],
            'level3' => $levelIds[2],
            'level4' => $levelIds[3],
            'level5' => $levelIds[4],
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        $id = (int)$db->lastInsertId();
        audit($db, 'gauntlet.create', (string)$id, [
            'name' => $name,
            'levels' => $levelIds,
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        flash('Gauntlet #'.$id.' created.');
    }
}

if ($action === 'gauntlet-delete') {
    $id = max(0, (int)($_POST['id'] ?? 0));

    if ($id <= 0) {
        throw new RuntimeException('Invalid gauntlet ID.');
    }

    $stmt = $db->prepare(
        'DELETE FROM mucho_gauntlets WHERE id=:id'
    );
    $stmt->execute(['id' => $id]);

    audit($db, 'gauntlet.delete', (string)$id);
    flash(
        $stmt->rowCount() === 1
            ? 'Gauntlet deleted.'
            : 'Gauntlet was not found.'
    );
}

if ($action === 'mappack-save') {
    $id = max(0, (int)($_POST['id'] ?? 0));
    $name = contentPackName(
        (string)($_POST['name'] ?? ''),
        64,
        'Map Pack'
    );
    $levelIds = contentPackLevelIds(
        (string)packLevelFieldFromPost(3),
        3
    );
    assertContentPackLevels($db, $levelIds);

    $stars = contentPackInt('stars', 0, 255);
    $coins = contentPackInt('coins', 0, 255);
    $difficulty = contentPackInt('difficulty', 0, 10);
    $color1 = contentPackInt('color1', 0, 255);
    $color2 = contentPackInt('color2', 0, 255);
    $sort = contentPackInt('sort_order', -1000000, 1000000);
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $levels = implode(',', $levelIds);

    if ($id > 0) {
        $stmt = $db->prepare(
            'UPDATE mucho_map_packs
             SET name=:name,
                 levels=:levels,
                 stars=:stars,
                 coins=:coins,
                 difficulty=:difficulty,
                 color1=:color1,
                 color2=:color2,
                 enabled=:enabled,
                 sort_order=:sort_order
             WHERE id=:id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'levels' => $levels,
            'stars' => $stars,
            'coins' => $coins,
            'difficulty' => $difficulty,
            'color1' => $color1,
            'color2' => $color2,
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        audit($db, 'mappack.save', (string)$id, [
            'name' => $name,
            'levels' => $levelIds,
            'stars' => $stars,
            'coins' => $coins,
            'difficulty' => $difficulty,
            'color1' => $color1,
            'color2' => $color2,
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        flash('Map Pack #'.$id.' saved.');
    } else {
        $stmt = $db->prepare(
            'INSERT INTO mucho_map_packs
             (name,levels,stars,coins,difficulty,color1,color2,enabled,sort_order)
             VALUES (:name,:levels,:stars,:coins,:difficulty,:color1,:color2,:enabled,:sort_order)'
        );
        $stmt->execute([
            'name' => $name,
            'levels' => $levels,
            'stars' => $stars,
            'coins' => $coins,
            'difficulty' => $difficulty,
            'color1' => $color1,
            'color2' => $color2,
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        $id = (int)$db->lastInsertId();
        audit($db, 'mappack.create', (string)$id, [
            'name' => $name,
            'levels' => $levelIds,
            'stars' => $stars,
            'coins' => $coins,
            'difficulty' => $difficulty,
            'color1' => $color1,
            'color2' => $color2,
            'enabled' => $enabled,
            'sort_order' => $sort,
        ]);
        flash('Map Pack #'.$id.' created.');
    }
}

if ($action === 'mappack-delete') {
    $id = max(0, (int)($_POST['id'] ?? 0));

    if ($id <= 0) {
        throw new RuntimeException('Invalid Map Pack ID.');
    }

    $stmt = $db->prepare(
        'DELETE FROM mucho_map_packs WHERE id=:id'
    );
    $stmt->execute(['id' => $id]);

    audit($db, 'mappack.delete', (string)$id);
    flash(
        $stmt->rowCount() === 1
            ? 'Map Pack deleted.'
            : 'Map Pack was not found.'
    );
}

$return = 'contentpacks';

header('Location:/admin/?page='.$return);
exit;
