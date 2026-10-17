<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use MuchoCore\News\NewsService;
use MuchoCore\News\NewsImage;
use MuchoCore\News\NewsMediaService;
use MuchoCore\News\NativeNewsFormat;

function checkMedia(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
$host=getenv('TEST_DB_HOST');$port=getenv('TEST_DB_PORT') ?: '3306';$password=getenv('TEST_DB_ROOT_PASSWORD');
if ($host!=='127.0.0.1' || !ctype_digit($port) || !is_string($password) || $password==='') throw new RuntimeException('Requires an isolated local/CI database.');
if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('Requires PHP GD.');
$name='mc_news_media_'.bin2hex(random_bytes(6));
$server=new PDO('mysql:host='.$host.';port='.$port,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $db=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach (['20261016_001_news_board.php','20261016_002_native_news_state.php','20261017_001_news_media.php'] as $file) {
        foreach (require dirname(__DIR__,2).'/database/migrations/'.$file as $sql) $db->exec($sql);
    }
    $resource=imagecreatetruecolor(800,454);ob_start();imagepng($resource);$png=(string)ob_get_clean();imagedestroy($resource);
    $image=NewsImage::fromBytes($png);$videoUrl='https://www.youtube.com/watch?v=NgHLQeYr-qk';
    $news=new NewsService($db);$media=new NewsMediaService($db);
    $id=$news->save(0,'Media draft','','',false,false,1,['images'=>[$image],'video'=>['url'=>$videoUrl,'image'=>$image]]);
    checkMedia($news->published()===[] && $media->image($image->filename)===null,'draft metadata and bytes stay private');
    checkMedia($media->image($image->filename,true)===$image->bytes(),'owner can preview a draft');
    $news->save($id,'Published media','','',true,false,1);
    $post=$news->published()[0];
    checkMedia(count($post['media'])===2 && !str_contains(json_encode($post),'image_data'),'public feed includes metadata without embedding image blobs');
    checkMedia($media->image($image->filename)===$image->bytes(),'published resource bytes match their content identity');
    $wire=gzdecode(base64_decode(strtr(NativeNewsFormat::encode([$post]),'-_','+/'),true));
    checkMedia(str_contains($wire,',800,454,0,') && str_contains($wire,',800,454,1,'),'native feed contains ordinary and clickable video cards');
    checkMedia($media->image('../'.$image->filename)===null,'resource lookup rejects path traversal');
    $old=(int)$news->nativeVersion();
    try {
        $news->save($id,'Invalid replacement','','',true,false,1,['remove'=>[$post['media'][0]['id']],'video'=>null]);
        throw new RuntimeException('Expected empty post rejection.');
    } catch (RuntimeException $error) {
        checkMedia($error->getMessage()==='Add news text, an image or a YouTube video.','removing the final content requires replacement text/media');
    }
    checkMedia(count($media->forOwner($id))===2 && (int)$news->nativeVersion()===$old,'rejected media edit rolls back post, images and native revision together');
    try {
        $news->save($id,'Too many','Text','',true,false,1,['images'=>array_fill(0,6,$image)]);
        throw new RuntimeException('Expected media limit rejection.');
    } catch (RuntimeException $error) {
        checkMedia(str_contains($error->getMessage(),'at most six'),'stored plus new images share one limit');
    }
    $news->save($id,'Replace video','Text','',true,false,1,['video'=>['url'=>'https://youtu.be/nDBhWpen-eU','image'=>$image]]);
    $items=$media->forOwner($id);
    checkMedia(count($items)===2 && $items[1]['target_url']==='https://www.youtube.com/watch?v=nDBhWpen-eU','video replacement keeps ordinary images and normalizes the target');
    $other=$news->save(0,'Other','Text','',true,false,1,['images'=>[$image]]);
    $otherId=$media->forOwner($other)[0]['id'];
    $news->save($id,'Scoped removal','Text','',true,false,1,['remove'=>[$otherId]]);
    checkMedia(count($media->forOwner($other))===1,'media removal is scoped to its own post');
    $news->delete($other);
    $news->togglePublished($id);
    checkMedia($media->image($image->filename)===null,'unpublished media becomes unavailable to new public requests');
    $news->delete($id);
    checkMedia((int)$db->query('SELECT COUNT(*) FROM mucho_news_media')->fetchColumn()===0,'post deletion cascades its images and video preview');
} finally { $server->exec('DROP DATABASE IF EXISTS '.$name); }
echo "MUCHOCORE_NEWS_MEDIA_INTEGRATION_OK\n";
