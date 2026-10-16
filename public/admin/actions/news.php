<?php

declare(strict_types=1);

use MuchoCore\News\NewsService;

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
    $id = $news->save(
        $id,
        (string)($_POST['title'] ?? ''),
        (string)($_POST['body'] ?? ''),
        (string)($_POST['external_url'] ?? ''),
        isset($_POST['published']) && $_POST['published'] === '1',
        isset($_POST['pinned']) && $_POST['pinned'] === '1',
        (int)(admin()['id'] ?? 0)
    );
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
