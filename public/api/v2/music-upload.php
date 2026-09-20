<?php
declare(strict_types=1);

/*
 * MuchoCore Music Upload
 * Copyright (C) 2026 IZK
 */

require_once __DIR__.'/bootstrap.php';

muchoV2RequireMethod('POST');

const MUSIC_DIR = '/var/www/mucho-core/storage/music-public';
const MUSIC_MAX = 20 * 1024 * 1024;
const MUSIC_COOLDOWN = 180;

try {
    if (!is_dir(MUSIC_DIR) && !mkdir(MUSIC_DIR, 0750, true) && !is_dir(MUSIC_DIR)) {
        throw new RuntimeException('music_directory_unavailable');
    }

    $db = muchoV2Db();

    $title = trim((string)($_POST['title'] ?? ''));
    $artist = trim((string)($_POST['artist'] ?? ''));

    if ($title === '' || mb_strlen($title) > 128) {
        muchoV2Fail('invalid_title',400);
    }

    if ($artist === '' || mb_strlen($artist) > 128) {
        muchoV2Fail('invalid_artist',400);
    }

    if (!isset($_FILES['file'])) {
        muchoV2Fail('file_required',400);
    }

    $file = $_FILES['file'];

    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
        muchoV2Fail('upload_failed',400);
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0 || $size > MUSIC_MAX) {
        muchoV2Fail('file_too_large',413);
    }

    $tmp = (string)$file['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        muchoV2Fail('invalid_upload',400);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

    if (!in_array($mime,[
        'audio/mpeg',
        'audio/mp3',
        'audio/x-mpeg'
    ],true)) {
        muchoV2Fail('mp3_only',415);
    }

    $ip = ClientIp::detect($_SERVER);

    $db->beginTransaction();

    $q=$db->prepare("
        INSERT IGNORE INTO mucho_music_rate_limits
        (ip,last_upload_at)
        VALUES (?,NULL)
    ");
    $q->execute([$ip]);

    $q=$db->prepare("
        SELECT last_upload_at
        FROM mucho_music_rate_limits
        WHERE ip=?
        FOR UPDATE
    ");
    $q->execute([$ip]);

    $last=$q->fetchColumn();

    if ($last) {
        $elapsed=time()-strtotime((string)$last);

        if ($elapsed < MUSIC_COOLDOWN) {
            $remaining=MUSIC_COOLDOWN-$elapsed;
            $db->rollBack();

            if (PHP_SAPI !== 'cli') {
                header('Retry-After: '.$remaining);
            }

            muchoV2Send([
                'ok'=>false,
                'error'=>'music_rate_limited',
                'retry_after_seconds'=>$remaining
            ],429);
        }
    }

    $stored=bin2hex(random_bytes(20)).'.mp3';
    $target=MUSIC_DIR.'/'.$stored;

    if (!move_uploaded_file($tmp,$target)) {
        throw new RuntimeException('cannot_store_file');
    }

    chmod($target,0640);

    $baseUrl=rtrim(
        (string)(
            getenv('MUCHO_ACCOUNT_URL')
            ?: (
                'https://'.
                (string)($_SERVER['HTTP_HOST'] ?? 'localhost')
            )
        ),
        '/'
    );

    $download=
        $baseUrl.
        '/music/'.
        rawurlencode($stored);

    try {
        $q=$db->prepare("
            INSERT INTO songs
            (
                name,
                author_id,
                author_name,
                size,
                download_url,
                is_verified
            )
            VALUES
            (
                :name,
                0,
                :author,
                :size,
                :url,
                1
            )
        ");

        $q->execute([
            'name'=>$title,
            'author'=>$artist,
            'size'=>round($size/1024/1024,2),
            'url'=>$download
        ]);

        $songId=(int)$db->lastInsertId();

        $q=$db->prepare("
            UPDATE mucho_music_rate_limits
            SET last_upload_at=NOW()
            WHERE ip=?
        ");
        $q->execute([$ip]);

        $db->commit();

    } catch(Throwable $e) {
        @unlink($target);

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }

    muchoV2Send([
        'ok'=>true,
        'song'=>[
            'id'=>$songId,
            'name'=>$title,
            'artist'=>$artist,
            'size_mb'=>round($size/1024/1024,2),
            'download_url'=>$download
        ],
        'next_upload_seconds'=>180
    ],201);

} catch(Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log(
        '[Mucho Music] '.
        $e->getMessage()
    );

    muchoV2Fail('internal_error',500);
}
