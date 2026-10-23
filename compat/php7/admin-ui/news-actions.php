<?php

declare(strict_types=1);

use MuchoCore\News\NewsService;
use MuchoCore\News\NewsImage;
use MuchoCore\News\NewsMediaService;
use MuchoCore\News\YouTubeVideo;
use MuchoCore\News\NewsValidationException;



$news = new NewsService($db,$data['news_version'] ?? '');
$id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
if ($id === false || $id < 0) {
    throw new RuntimeException('Invalid news ID.');
}

if ($action === 'news-save') {
    $_SESSION['news_editor_draft']=['id'=>$id,'post'=>[
        'id'=>$id,
        'title'=>mucho7_ui_substr((string)($data['title'] ?? ''),0,140),
        'body'=>mucho7_ui_substr((string)($data['body'] ?? ''),0,5000),
        'external_url'=>substr((string)($data['external_url'] ?? ''),0,512),
        'is_published'=>isset($data['published']) && $data['published']==='1'?1:0,
        'is_pinned'=>isset($data['pinned']) && $data['pinned']==='1'?1:0,
    ],'youtube_url'=>substr((string)($data['youtube_url'] ?? ''),0,512)];
    try {
        $mediaService=new NewsMediaService($db);
        $changes=['images'=>[],'remove'=>$data['remove_media'] ?? []];
        if (!is_array($changes['remove'])) throw new NewsValidationException('Invalid image selection.');
        $uploads=$files['images'] ?? null;
        if ($uploads!==null) {
            if (!is_array($uploads['error'] ?? null) || count($uploads['error'])>NewsMediaService::MAX_IMAGES) throw new NewsValidationException('Upload at most six images at a time.');
            foreach ($uploads['error'] as $key=>$error) {
                $image=NewsImage::fromUpload(['error'=>$error,'tmp_name'=>$uploads['tmp_name'][$key] ?? '']);
                if ($image!==null) $changes['images'][]=$image;
            }
        }
        $videoUrl=YouTubeVideo::canonicalUrl((string)($data['youtube_url'] ?? ''));
        $thumbnail=NewsImage::fromUpload($files['video_thumbnail'] ?? []);
        $oldVideoUrl='';
        foreach ($id>0?$mediaService->forOwner($id):[] as $item) {
            if ($item['kind']==='youtube') $oldVideoUrl=(string)$item['target_url'];
        }
        if ($videoUrl==='') {
            if ($thumbnail!==null) throw new NewsValidationException('Add a YouTube link for this video preview.');
            $changes['video']=null;
        } elseif ($videoUrl!==$oldVideoUrl || $thumbnail!==null) {
            $changes['video']=['url'=>$videoUrl,'image'=>$thumbnail ?? YouTubeVideo::thumbnail($videoUrl)];
        }
        $id = $news->save(
            $id,
            (string)($data['title'] ?? ''),
            (string)($data['body'] ?? ''),
            (string)($data['external_url'] ?? ''),
            isset($data['published']) && $data['published'] === '1',
            isset($data['pinned']) && $data['pinned'] === '1',
            $aid,
            $changes
        );
    } catch (NewsValidationException $error) {
        throw $error;
    }
    unset($_SESSION['news_editor_draft']);
    mucho7_admin_audit($db,$aid,'news.save',$id);
} elseif ($action === 'news-toggle') {
    $news->togglePublished($id);
    mucho7_admin_audit($db,$aid,'news.toggle',$id);
} elseif ($action === 'news-delete') {
    $deleted = $news->delete($id);
    mucho7_admin_audit($db,$aid,'news.delete',$id);
} else {
    throw new RuntimeException('Invalid news action.');
}
