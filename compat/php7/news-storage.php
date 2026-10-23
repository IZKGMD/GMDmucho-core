<?php
/** Additive News storage; the old private JSON is retained as a backup. */
declare(strict_types=1);

function mucho7_news_install(PDO $db,$root)
{
    $marker=$root.'/config/php7-news-sql.on';
    if (is_file($marker)) return;
    $lock=fopen($root.'/config/php7-news.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('News storage is busy.');
    @chmod($root.'/config/php7-news.lock',0600);
    try {
        if (is_file($marker)) return;
        foreach (require __DIR__.'/news/schema.php' as $statement) $db->exec($statement);
        $legacy=mucho7_news_source($root.'/config/php7-news.json');
        $db->beginTransaction();
        try {
            $version=$db->query('SELECT version FROM mucho_news_state WHERE id=1 FOR UPDATE')->fetchColumn();
            $count=(int)$db->query('SELECT COUNT(*) FROM mucho_news_posts')->fetchColumn();
            // An existing modern board is authoritative, including an emptied one.
            if ($count===0 && (string)$version==='1000000') {
                foreach ($legacy['posts'] as $post) {
                    mucho7_news_legacy_payload(array($post));
                    $db->prepare('INSERT INTO mucho_news_posts(id,title,body,external_url,is_published,is_pinned,published_at) VALUES(?,?,?,?,1,0,?)')
                        ->execute(array($post['id'],$post['title'],$post['body'],isset($post['url'])?$post['url']:null,gmdate('Y-m-d H:i:s',strtotime($post['published_at']))));
                }
                $db->prepare('UPDATE mucho_news_state SET version=? WHERE id=1')->execute(array(max(1000000,(int)$legacy['version'])+1));
            }
            $db->commit();
        } catch (Exception $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        mucho7_private_write($marker,"1\n");
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
