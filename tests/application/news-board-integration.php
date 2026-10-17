<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use MuchoCore\News\NewsService;
use MuchoCore\News\NativeNewsFormat;

function checkNews(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
$host=getenv('TEST_DB_HOST');
$port=getenv('TEST_DB_PORT') ?: '3306';
$password=getenv('TEST_DB_ROOT_PASSWORD');
if ($host!=='127.0.0.1' || !ctype_digit($port) || !is_string($password) || $password==='') {
    throw new RuntimeException('Requires an isolated local/CI database.');
}
$name='mc_news_test_'.bin2hex(random_bytes(6));
$server=new PDO('mysql:host='.$host.';port='.$port,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $db=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4','root',$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    foreach (['20261016_001_news_board.php','20261016_002_native_news_state.php'] as $file) {
        foreach (require dirname(__DIR__,2).'/database/migrations/'.$file as $sql) $db->exec($sql);
    }
    $news=new NewsService($db);
    $version=(int)$news->nativeVersion();
    $id=$news->save(0,'Черновик','Текст новости','',false,false,1);
    checkNews($news->published()===[],'drafts remain private');
    $news->save($id,'Публикация','Привет, GDPS!','https://example.com/news',true,true,1);
    $first=$news->published()[0];
    checkNews($first['id']===$id && $first['title']==='Публикация' && $first['published_at']!==null,
        'editing and publishing work with native PDO prepares');
    $publishedAt=$first['published_at'];
    $news->save($id,'Правка','Новый текст','',true,true,1);
    checkNews($news->published()[0]['published_at']===$publishedAt,'editing preserves the publication timestamp');
    $payload=NativeNewsFormat::encode($news->published());
    $wire=gzdecode(base64_decode(strtr($payload,'-_','+/'),true));
    checkNews(str_starts_with($wire,'4,1|1:'.$id.';'),'edited post serializes into the native GD news feed');
    $news->save($id,'Скрыто','Новый текст','',false,false,1);
    checkNews($news->published()===[],'editing can unpublish a post');
    $row=$news->allForOwner()[0];
    checkNews($row['published_at']===null,'unpublishing clears the public date');
    $news->togglePublished($id);
    checkNews(count($news->published())===1,'publication toggle restores the feed');
    checkNews((int)$news->nativeVersion()===$version+5,'every successful mutation advances the native revision');
    $before=$news->allForOwner()[0];
    $db->exec('DELETE FROM mucho_news_state WHERE id=1');
    try {
        $news->save($id,'Must roll back','Changed','',true,false,1);
        throw new RuntimeException('Expected version update to fail.');
    } catch (RuntimeException $e) {
        checkNews($e->getMessage()==='Unable to increment native news version.','missing revision state rejects the update');
    }
    checkNews(!$db->inTransaction() && $news->allForOwner()[0]['title']===$before['title'],
        'failed revision increment rolls back the news edit');
    $db->exec('INSERT INTO mucho_news_state VALUES (1,'.($version+5).')');
    checkNews($news->delete($id) && $news->published()===[],'deleting the final post clears the native feed');
    checkNews((int)$news->nativeVersion()===$version+6,'deleting the final post still invalidates client caches');
    checkNews(!$news->delete($id) && (int)$news->nativeVersion()===$version+6,'missing post deletion does not invent a new revision');
} finally {
    $server->exec('DROP DATABASE IF EXISTS '.$name);
}
echo "MUCHOCORE_NEWS_BOARD_INTEGRATION_OK\n";
