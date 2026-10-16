<?php

declare(strict_types=1);

use MuchoCore\News\NewsService;

if ((string)(admin()['role'] ?? '') !== 'owner') {
    throw new RuntimeException('Only the GDPS owner may edit the news board.');
}

$newsService = new NewsService($db);
$posts = $newsService->allForOwner();
$editId = max(0, (int)($_GET['edit'] ?? 0));
$editPost = null;
foreach ($posts as $post) {
    if ((int)$post['id'] === $editId) {
        $editPost = $post;
        break;
    }
}
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
@media(max-width:960px){.mucho-news-layout{grid-template-columns:minmax(0,1fr)}}
</style>
<div class="section-actions" style="margin-bottom:14px">
    <span class="muted">Owner-only editor. Drafts are private. Published posts are immediately available from the MuchoCore news API.</span>
    <a class="btn gray" href="/api/muchocore/news" target="_blank" rel="noopener">View public JSON ↗</a>
</div>
<div class="mucho-news-layout">
    <section class="card">
        <h2 style="margin-top:0"><?= $editPost ? 'Edit news #'.(int)$editPost['id'] : 'Write a news post' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="news-save">
            <input type="hidden" name="return" value="news">
            <input type="hidden" name="id" value="<?= (int)($editPost['id'] ?? 0) ?>">
            <label class="mucho-news-field">
                <b>Headline</b>
                <input name="title" maxlength="140" required placeholder="New MuchoGDPS update" value="<?=h($editPost['title'] ?? '')?>">
            </label>
            <label class="mucho-news-field">
                <b>News text (up to 5000 characters)</b>
                <textarea name="body" maxlength="5000" required placeholder="What's new? Tell players here."><?=h($editPost['body'] ?? '')?></textarea>
            </label>
            <label class="mucho-news-field">
                <b>Optional HTTPS link</b>
                <input name="external_url" maxlength="512" type="url" placeholder="https://muchogdps.space/" value="<?=h($editPost['external_url'] ?? '')?>">
            </label>
            <div class="mucho-news-flags">
                <label><input type="checkbox" name="published" value="1" <?= (int)($editPost['is_published'] ?? 0) === 1 ? 'checked' : '' ?>> Publish</label>
                <label><input type="checkbox" name="pinned" value="1" <?= (int)($editPost['is_pinned'] ?? 0) === 1 ? 'checked' : '' ?>> Pin to top</label>
            </div>
            <button type="submit"><?= $editPost ? 'Save changes' : 'Create post' ?></button>
            <?php if ($editPost): ?><a class="btn gray" href="/admin/?page=news" style="margin-left:8px">Cancel editing</a><?php endif; ?>
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
