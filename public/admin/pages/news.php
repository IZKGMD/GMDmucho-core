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
<style>
.mucho-news-layout{display:grid;grid-template-columns:minmax(300px,1.1fr) minmax(290px,1fr);gap:16px;align-items:start}
.mucho-news-layout .card{margin-bottom:14px}
.mucho-news-field{display:block;margin-top:14px}
.mucho-news-field b{display:block;margin-bottom:6px;font-size:12px;color:#a9b6d0}
.mucho-news-field input,.mucho-news-field textarea{display:block;width:100%;box-sizing:border-box}
.mucho-news-field textarea{min-height:210px;resize:vertical;line-height:1.6}
.mucho-news-flags{display:flex;flex-wrap:wrap;gap:16px;margin:15px 0}
.mucho-news-flags label{display:flex;align-items:center;gap:8px;font-size:13px}
.mucho-news-item{border:1px solid #283348;border-radius:11px;padding:14px;margin-top:10px;background:#0d131d}
.mucho-news-meta{font-size:11px;color:#9daac0;margin:7px 0 10px}
.mucho-news-item h3{margin:0;font-size:15px;overflow-wrap:anywhere}
.mucho-news-item p{white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0;color:#c2ccde;font-size:12px;line-height:1.55}
.mucho-news-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
.mucho-news-actions form{margin:0}
.mucho-news-media{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}
.mucho-news-media figure{margin:0;max-width:280px}
.mucho-news-media img{display:block;width:100%;height:auto;border-radius:7px}
.mucho-news-media figcaption{font-size:12px;margin-top:6px;overflow-wrap:anywhere}
@media(max-width:960px){.mucho-news-layout{grid-template-columns:minmax(0,1fr)}}
</style>
<div class="section-actions" style="margin-bottom:14px">
    <span class="muted">Owner-only editor. Drafts are private. Published posts appear in the public feed.</span>
    <?php if ($nativeNewsFlag): ?>
    <span class="badge green">Native GD News: enabled</span>
    <?php else: ?>
    <span class="badge">Native GD News: off (set MUCHO_NATIVE_NEWS=1 on server)</span>
    <?php endif; ?>
    <a class="btn gray" href="/api/muchocore/news" target="_blank" rel="noopener">View public JSON ↗</a>
</div>
<div class="mucho-news-layout">
    <section class="card">
        <h2 style="margin-top:0"><?= $isEditing ? 'Edit news #'.$editId : 'Write a news post' ?></h2>
        <form method="post" enctype="multipart/form-data" id="newsEditorForm">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="news-save">
            <input type="hidden" name="return" value="news">
            <input type="hidden" name="id" value="<?= (int)($editPost['id'] ?? 0) ?>">
            <label class="mucho-news-field">
                <b>Headline</b>
                <input name="title" maxlength="140" required aria-describedby="newsPreviewHint" placeholder="New MuchoGDPS update" value="<?=h($editPost['title'] ?? '')?>">
            </label>
            <label class="mucho-news-field">
                <b>News text (optional with an image or video, up to 5000 characters)</b>
                <textarea name="body" maxlength="5000" data-counter-target="newsBodyCounter" aria-describedby="newsBodyCounter" placeholder="What's new? Tell players here."><?=h($editPost['body'] ?? '')?></textarea><small id="newsBodyCounter" role="status"><?=mb_strlen((string)($editPost['body'] ?? ''),'UTF-8')?> / 5000 characters</small>
            </label>
            <label class="mucho-news-field">
                <b>Add images</b>
                <input type="file" name="images[]" accept="image/jpeg,image/png" multiple data-max-file-mib="5" data-max-files="6">
                <small>Up to 6 images per post, 5 MiB each. Images are resized to fit 1600 × 1600 and stored as JPEG.</small>
            </label>
            <label class="mucho-news-field">
                <b>YouTube video</b>
                <input type="url" name="youtube_url" maxlength="512" placeholder="https://www.youtube.com/watch?v=…" value="<?=h($videoUrl)?>">
                <small>Watch links, youtu.be, Shorts and live videos are supported. The game shows a clickable preview with its native Play button.</small>
            </label>
            <label class="mucho-news-field">
                <b>Video preview image (optional)</b>
                <input type="file" name="video_thumbnail" accept="image/jpeg,image/png" data-max-file-mib="5">
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
            <p class="muted" id="newsPreviewHint">Check the preview before publishing. Images and video previews are processed after upload.</p>
            <details class="admin-news-preview" id="newsPreviewPanel">
                <summary>Preview headline and text</summary>
                <div class="admin-news-preview-content" aria-live="polite">
                    <h3 id="newsPreviewTitle"><?=h((string)($editPost['title'] ?? 'Your headline'))?></h3>
                    <p id="newsPreviewBody"><?=h((string)($editPost['body'] ?? 'Your message will appear here.'))?></p>
                </div>
            </details>
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
                    <form method="post" data-confirm="Permanently delete this news post and its uploaded media?">
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
