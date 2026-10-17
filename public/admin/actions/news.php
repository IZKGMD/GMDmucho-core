<?php

declare(strict_types=1);

use MuchoCore\News\NewsService;
use MuchoCore\News\NewsImage;
use MuchoCore\News\NewsMediaService;
use MuchoCore\News\YouTubeVideo;
use MuchoCore\News\NewsValidationException;

if ((string)(admin()['role'] ?? '') !== 'owner') {
    throw new RuntimeException('Only the GDPS owner may publish or edit news.');
}
requireRank(40);

$news = new NewsService($db);
$id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
if ($id === false || $id < 0) {
    throw new RuntimeException('Invalid news ID.');
}

if ($action === 'news-save') {
    $_SESSION['news_editor_draft']=['id'=>$id,'post'=>[
        'id'=>$id,
        'title'=>mb_substr((string)($_POST['title'] ?? ''),0,140),
        'body'=>mb_substr((string)($_POST['body'] ?? ''),0,5000),
        'external_url'=>substr((string)($_POST['external_url'] ?? ''),0,512),
        'is_published'=>isset($_POST['published']) && $_POST['published']==='1'?1:0,
        'is_pinned'=>isset($_POST['pinned']) && $_POST['pinned']==='1'?1:0,
    ],'youtube_url'=>substr((string)($_POST['youtube_url'] ?? ''),0,512)];
    try {
        $mediaService=new NewsMediaService($db);
        $changes=['images'=>[],'remove'=>$_POST['remove_media'] ?? []];
        if (!is_array($changes['remove'])) throw new NewsValidationException('Invalid image selection.');
        $uploads=$_FILES['images'] ?? null;
        if ($uploads!==null) {
            if (!is_array($uploads['error'] ?? null) || count($uploads['error'])>NewsMediaService::MAX_IMAGES) throw new NewsValidationException('Upload at most six images at a time.');
            foreach ($uploads['error'] as $key=>$error) {
                $image=NewsImage::fromUpload(['error'=>$error,'tmp_name'=>$uploads['tmp_name'][$key] ?? '']);
                if ($image!==null) $changes['images'][]=$image;
            }
        }
        $videoUrl=YouTubeVideo::canonicalUrl((string)($_POST['youtube_url'] ?? ''));
        $thumbnail=NewsImage::fromUpload($_FILES['video_thumbnail'] ?? []);
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
            (string)($_POST['title'] ?? ''),
            (string)($_POST['body'] ?? ''),
            (string)($_POST['external_url'] ?? ''),
            isset($_POST['published']) && $_POST['published'] === '1',
            isset($_POST['pinned']) && $_POST['pinned'] === '1',
            (int)(admin()['id'] ?? 0),
            $changes
        );
    } catch (NewsValidationException $error) {
        flash($error->getMessage(),'err');
        return;
    }
    unset($_SESSION['news_editor_draft']);
    audit($db, 'news.save', (string)$id);
    flash('News post saved.');
} elseif ($action === 'news-toggle') {
    $news->togglePublished($id);
    audit($db, 'news.toggle', (string)$id);
    flash('News publication status updated.');
} elseif ($action === 'news-delete') {
    $deleted = $news->delete($id);
    audit($db, 'news.delete', (string)$id);
    flash($deleted ? 'News post deleted.' : 'News post not found.');
} else {
    throw new RuntimeException('Invalid news action.');
}
