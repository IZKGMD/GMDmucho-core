<?php
declare(strict_types=1);

/*
 * MuchoCore Music
 * Copyright (C) 2026 IZK
 */

require_once __DIR__.'/bootstrap.php';

muchoV2RequireMethod('GET');

try {
    $db=muchoV2Db();

    $rows=$db->query("
        SELECT
            id,
            name,
            author_name,
            size,
            download_url,
            created_at
        FROM songs
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll();

    muchoV2Send([
        'ok'=>true,
        'songs'=>array_map(
            static fn(array $r)=>[
                'id'=>(int)$r['id'],
                'name'=>(string)$r['name'],
                'artist'=>(string)$r['author_name'],
                'size_mb'=>(float)$r['size'],
                'download_url'=>(string)$r['download_url'],
                'created_at'=>$r['created_at']
            ],
            $rows
        )
    ]);

} catch(Throwable $e) {
    muchoV2Fail('internal_error',500);
}
