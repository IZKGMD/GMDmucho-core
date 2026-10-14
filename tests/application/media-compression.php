<?php
declare(strict_types=1);
use MuchoCore\Media\MessageImage;
use MuchoCore\Media\ImageQuality;
spl_autoload_register(static function(string $class):void {if(str_starts_with($class,'MuchoCore\\'))require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';});
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function encode(GdImage $im):string {ob_start();imagepng($im);return (string)ob_get_clean();}
$im=imagecreatetruecolor(2048,1024);$white=imagecolorallocate($im,255,255,255);$black=imagecolorallocate($im,0,0,0);imagefill($im,0,0,$white);
for($y=12;$y<1000;$y+=24)imagestring($im,5,12,$y,'MuchoCore 1234567890 / readable screenshot text / status: approved',$black);
$original=encode($im);$output=MessageImage::render($original);
check($output['format']==='png','Text screenshot did not choose compact PNG');
check($output['width']===2048&&$output['height']===1024,'Small text screenshot unnecessarily resized');
check(strlen($output['bytes'])<60000,'Text screenshot insufficiently compressed');
$decoded=imagecreatefromstring($output['bytes']);check((new ImageQuality($im))->readable($decoded),'Screenshot lost text edge quality');
$poor=imagecreatetruecolor(2048,1024);imagefill($poor,0,0,$white);check(!(new ImageQuality($im))->readable($poor),'Deleted text passed quality guard');
if($dir=getenv('MEDIA_BENCH_OUT')){if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($dir.'/screenshot-original.png',$original);file_put_contents($dir.'/screenshot-compressed.png',$output['bytes']);}
imagedestroy($decoded);imagedestroy($poor);imagedestroy($im);
$im=imagecreatetruecolor(1000,700);
for($y=0;$y<700;$y++)for($x=0;$x<1000;$x++){
 $r=(int)(120+90*sin($x/91)*cos($y/83));$g=(int)(128+80*sin(($x+$y)/77));$b=(int)(120+75*cos($x/58)*sin($y/98));imagesetpixel($im,$x,$y,($r<<16)|($g<<8)|$b);
}
$photo=MessageImage::render(encode($im));$decoded=imagecreatefromstring($photo['bytes']);check((new ImageQuality($im))->readable($decoded),'Color photo lost perceptual quality');check(strlen($photo['bytes'])<=MessageImage::MAX_OUTPUT,'Photo exceeds byte cap');
if($dir=getenv('MEDIA_BENCH_OUT')){file_put_contents($dir.'/photo-original.png',encode($im));file_put_contents($dir.'/photo-compressed.'.$photo['format'],$photo['bytes']);}
$stats=['screenshot_original'=>strlen($original),'screenshot_compressed'=>strlen($output['bytes']),'photo_compressed'=>strlen($photo['bytes']),'photo_format'=>$photo['format']];
imagedestroy($decoded);imagedestroy($im);
$im=imagecreatetruecolor(3000,1000);imagefill($im,0,0,imagecolorallocate($im,27,41,65));$wide=MessageImage::render(encode($im));check($wide['width']===2048&&$wide['height']===683,'Compression distorted aspect ratio');check(MessageImage::mime($wide['bytes'])==='image/png','Wrong PNG MIME');imagedestroy($im);
echo 'Adaptive image compression and text/color quality guards: OK '.json_encode($stats)."\n";
