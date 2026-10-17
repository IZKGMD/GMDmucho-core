<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use MuchoCore\News\NewsImage;
use MuchoCore\News\NativeNewsFormat;
use MuchoCore\News\YouTubeVideo;

function mediaCheck(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
function mediaReject(callable $call,string $label): void {
    try { $call(); } catch (RuntimeException) { mediaCheck(true,$label);return; }
    throw new RuntimeException('Accepted invalid input: '.$label);
}
$id='NgHLQeYr-qk';$canonical='https://www.youtube.com/watch?v='.$id;
foreach (['https://youtu.be/'.$id.'?si=tracking','https://youtube.com/watch?v='.$id.'&t=10',
    'https://m.youtube.com/shorts/'.$id,'https://www.youtube.com/live/'.$id,
    'https://www.youtube.com/embed/'.$id] as $url) {
    mediaCheck(YouTubeVideo::canonicalUrl($url)===$canonical,'YouTube URL normalized: '.$url);
}
foreach (['http://youtu.be/'.$id,'https://youtube.com.evil.test/watch?v='.$id,
    'https://user:pass@youtube.com/watch?v='.$id,'https://youtube.com:444/watch?v='.$id,
    'https://youtube.com/watch?v[]=x','https://youtu.be/../'.$id,'https://youtube.com/@channel',
    'https://127.0.0.1/watch?v='.$id,'javascript:alert(1)'] as $url) {
    mediaReject(static fn()=>YouTubeVideo::canonicalUrl($url),'unsafe/non-video URL rejected');
}

// The six image fields below were decoded from the user-provided original
// GD 2.209 news.dat: 1,filename,width,height,video-play-overlay,base64url-link.
$file='mcnews_'.str_repeat('a',64).'.jpg';
$post=['id'=>8,'title'=>'Test','body'=>'','published_at'=>'2026-09-13 00:00:00','url'=>null,
    'media'=>[['kind'=>'youtube','filename'=>$file,'width'=>800,'height'=>454,'target_url'=>$canonical]]];
$expected='4,1|1:8;2:U2VwdGVtYmVyIDEzLCAyMDI2;3:VGVzdA==;4:1,'.$file.',800,454,1,aHR0cHM6Ly93d3cueW91dHViZS5jb20vd2F0Y2g_dj1OZ0hMUWVZci1xaw==';
mediaCheck(NativeNewsFormat::textPayload([$post])===$expected,'video card matches original native field order, overlay and URL encoding');
$post['media'][0]['kind']='image';$post['media'][0]['target_url']=null;
mediaCheck(str_ends_with(NativeNewsFormat::textPayload([$post]),',800,454,0,'),'image-only post has no video overlay');
foreach ([['filename'=>'../escape.jpg'],['width'=>0],['height'=>999999],['kind'=>'script']] as $change) {
    $bad=$post;$bad['media'][0]=array_replace($bad['media'][0],$change);
    mediaReject(static fn()=>NativeNewsFormat::textPayload([$bad]),'invalid native media metadata rejected');
}

if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('This test requires PHP GD.');
$image=imagecreatetruecolor(3200,1800);
imagefilledrectangle($image,0,0,3199,1799,imagecolorallocate($image,50,100,200));
ob_start();imagepng($image);$png=(string)ob_get_clean();imagedestroy($image);
$encoded=NewsImage::fromBytes($png.'<?php echo "not executable";');
mediaCheck($encoded->width===1600 && $encoded->height===900,'large image fits native preview bounds without changing aspect ratio');
mediaCheck(getimagesizefromstring($encoded->bytes())[2]===IMAGETYPE_JPEG,'uploads are freshly encoded JPEG bytes');
mediaCheck(!str_contains($encoded->bytes(),'<?php'),'appended source payload is stripped');
mediaCheck($encoded->filename==='mcnews_'.hash('sha256',$encoded->bytes()).'.jpg','resource identity changes with image bytes');
mediaReject(static fn()=>NewsImage::fromBytes('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),'SVG is not a news upload');
mediaReject(static fn()=>NewsImage::fromBytes(str_repeat('x',NewsImage::MAX_INPUT_BYTES+1)),'oversized upload rejected');
$sources=[];
$thumbnail=YouTubeVideo::thumbnail($canonical,static function(string $source) use (&$sources,$png): string {
    $sources[]=$source;
    if (count($sources)===1) throw new RuntimeException('No max-resolution preview.');
    return $png;
});
mediaCheck($sources===['https://i.ytimg.com/vi/'.$id.'/maxresdefault.jpg','https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg']
    && $thumbnail->width===1600,'automatic preview uses only the fixed YouTube image origin and falls back safely');
mediaReject(static fn()=>YouTubeVideo::thumbnail($canonical,static fn()=>throw new RuntimeException('offline')),
    'unavailable previews ask for a manual upload');
echo "MUCHOCORE_NEWS_MEDIA_OK\n";
