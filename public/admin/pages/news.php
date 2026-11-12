<?php

declare(strict_types=1);

use MuchoCore\News\NewsService;
use MuchoCore\News\NewsMediaService;

if ((string)(admin()['role'] ?? '') !== 'owner') {
    throw new RuntimeException('Only the GDPS owner may edit the news board.');
}

$nativeNewsFlag = (string)(\MuchoCore\Core\Environment::get('MUCHO_NATIVE_NEWS', '0') ?? '0') === '1';
$nativeNewsUrl = (string)(\MuchoCore\Core\Environment::get('MUCHO_ACCOUNT_URL', '') ?? '');
$nativeNewsUrl = rtrim($nativeNewsUrl, '/') . '/gd-content/news/news.dat';
$newsService = new NewsService($db);
$posts = $newsService->allForOwner();
$postMedia=(new NewsMediaService($db))->forPosts(array_column($posts,'id'));
$editId = max(0, (int)($_GET['edit'] ?? 0));
$editPost = null;
foreach ($posts as $post) {
    if ((int)$post['id'] === $editId) {
        $editPost = $post;
        break;
    }
}
$editMedia=$postMedia[$editId] ?? [];
$videoUrl='';
foreach ($editMedia as $item) if ($item['kind']==='youtube') $videoUrl=(string)$item['target_url'];
$draft=$_SESSION['news_editor_draft'] ?? null;
if (is_array($draft) && (int)($draft['id'] ?? -1)===$editId) {
    $editPost=array_replace($editPost ?? [],$draft['post']);
    $videoUrl=(string)$draft['youtube_url'];
    unset($_SESSION['news_editor_draft']);
}
$isEditing=$editId>0;
?>

<div class="section-actions mc-news-status">
    <span class="muted">Owner-only editor. Drafts are private. Published posts appear in the public feed.</span>
    <?php if ($nativeNewsFlag): ?>
    <span class="badge green">Native GD News: enabled</span>
    <?php else: ?>
    <span class="badge">Native GD News: off (set MUCHO_NATIVE_NEWS=1 on server)</span>
    <?php endif; ?>
    <a class="btn gray" href="/api/muchocore/news" target="_blank" rel="noopener">View public JSON ↗</a>
</div>
<div class="mucho-news-layout mc-news-workspace">
    <section class="card">
        <h2 style="margin-top:0"><?= $isEditing ? 'Edit news #'.$editId : 'Write a news post' ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="news-save">
            <input type="hidden" name="return" value="news">
            <input type="hidden" name="id" value="<?= (int)($editPost['id'] ?? 0) ?>">
            <label class="mucho-news-field">
                <b>Headline</b>
                <input name="title" maxlength="140" required placeholder="New MuchoGDPS update" value="<?=h($editPost['title'] ?? '')?>">
            </label>
            <label class="mucho-news-field">
                <b>News text (optional with an image or video, up to 5000 characters)</b>
                <textarea name="body" maxlength="5000" placeholder="What's new? Tell players here."><?=h($editPost['body'] ?? '')?></textarea>
            </label>
            <label class="mucho-news-field">
                <b>Add images</b>
                <input type="file" name="images[]" accept="image/jpeg,image/png" multiple>
                <small>Up to 6 images per post, 5 MiB each. Images are resized to fit 1600 × 1600 and stored as JPEG.</small>
            </label>
            <label class="mucho-news-field">
                <b>YouTube video</b>
                <input type="url" name="youtube_url" maxlength="512" placeholder="https://www.youtube.com/watch?v=…" value="<?=h($videoUrl)?>">
                <small>Watch links, youtu.be, Shorts and live videos are supported. The game shows a clickable preview with its native Play button.</small>
            </label>
            <label class="mucho-news-field">
                <b>Video preview image (optional)</b>
                <input type="file" name="video_thumbnail" accept="image/jpeg,image/png">
                <small>Leave empty to download the YouTube preview automatically. Upload your own if the preview is unavailable.</small>
            </label>
            <?php if ($editMedia): ?>
            <div class="mucho-news-media">
                <?php foreach ($editMedia as $media): ?>
                <figure>
                    <img src="/admin/?news_image=<?=h($media['filename'])?>" width="280" height="<?=max(1,(int)round(280*$media['height']/$media['width']))?>" alt="<?= $media['kind']==='youtube'?'YouTube preview':'News image' ?>" loading="lazy">
                    <figcaption><?php if ($media['kind']==='image'): ?><label><input type="checkbox" name="remove_media[]" value="<?=(int)$media['id']?>"> Remove image on save</label><?php else: ?>YouTube preview. Clear the video link to remove it.<?php endif; ?></figcaption>
                </figure>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <label class="mucho-news-field">
                <b>Other HTTPS link (optional)</b>
                <input name="external_url" maxlength="512" type="url" placeholder="https://muchogdps.space/" value="<?=h($editPost['external_url'] ?? '')?>">
            </label>
            <div class="mucho-news-flags">
                <label><input type="checkbox" name="published" value="1" <?= (int)($editPost['is_published'] ?? 0) === 1 ? 'checked' : '' ?>> Publish</label>
                <label><input type="checkbox" name="pinned" value="1" <?= (int)($editPost['is_pinned'] ?? 0) === 1 ? 'checked' : '' ?>> Pin to top</label>
            </div>
            <button type="submit"><?= $isEditing ? 'Save changes' : 'Create post' ?></button>
            <?php if ($isEditing): ?><a class="btn gray" href="/admin/?page=news" style="margin-left:8px">Cancel editing</a><?php endif; ?>
        </form>
    </section>

    <section class="card">
        <h2 style="margin-top:0">Your news <small class="muted">(<?=count($posts)?>)</small></h2>
        <?php if (!$posts): ?>
            <p class="muted">No news yet. Create the first post and tick Publish.</p>
        <?php endif; ?>
        <?php foreach ($posts as $post): ?>
            <article class="mucho-news-item">
                <h3><?=h($post['title'])?></h3>
                <div class="mucho-news-meta">
                    #<?= (int)$post['id'] ?> ·
                    <?= (int)$post['is_published'] === 1 ? '🟢 Published' : '◯ Draft' ?>
                    <?= (int)$post['is_pinned'] === 1 ? ' · 📌 Pinned' : '' ?>
                    · <?=h($post['updated_at'])?>
                </div>
                <p><?=h(mb_strimwidth((string)$post['body'], 0, 380, '…', 'UTF-8'))?></p>
                <?php foreach ($postMedia[(int)$post['id']] ?? [] as $media): ?>
                <figure class="mucho-news-media">
                    <img src="/admin/?news_image=<?=h($media['filename'])?>" width="280" height="<?=max(1,(int)round(280*$media['height']/$media['width']))?>" loading="lazy" alt="<?= $media['kind']==='youtube'?'YouTube preview':'News image' ?>">
                    <?php if ($media['kind']==='youtube'): ?><figcaption><a href="<?=h($media['target_url'])?>" target="_blank" rel="noopener noreferrer">▶ Watch on YouTube</a></figcaption><?php endif; ?>
                </figure>
                <?php endforeach; ?>
                <?php if (!empty($post['external_url'])): ?>
                    <a href="<?=h($post['external_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($post['external_url'])?></a>
                <?php endif; ?>
                <div class="mucho-news-actions">
                    <a class="btn gray" href="/admin/?page=news&amp;edit=<?=(int)$post['id']?>">Edit</a>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                        <input type="hidden" name="action" value="news-toggle">
                        <input type="hidden" name="return" value="news">
                        <input type="hidden" name="id" value="<?=(int)$post['id']?>">
                        <button class="<?= (int)$post['is_published'] === 1 ? 'gray' : 'green' ?>">
                            <?= (int)$post['is_published'] === 1 ? 'Unpublish' : 'Publish' ?>
                        </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this news post permanently?')">
                        <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                        <input type="hidden" name="action" value="news-delete">
                        <input type="hidden" name="return" value="news">
                        <input type="hidden" name="id" value="<?=(int)$post['id']?>">
                        <button class="red">Delete</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>
