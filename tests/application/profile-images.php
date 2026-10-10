<?php

declare(strict_types=1);

require __DIR__.'/../../src/Appearance/ProfileImage.php';

use MuchoCore\Appearance\ProfileImage;

function imageCheck(bool $ok,string $name): void
{
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}

function rejected(callable $action,string $name): void
{
    try { $action(); }
    catch (RuntimeException) { imageCheck(true,$name); return; }
    throw new RuntimeException('FAIL '.$name);
}

imageCheck(extension_loaded('gd'),'GD available for real image processing');
$source=imagecreatetruecolor(400,200);
$red=imagecolorallocate($source,240,20,20);
$blue=imagecolorallocate($source,20,20,240);
imagefilledrectangle($source,0,0,199,199,$red);
imagefilledrectangle($source,200,0,399,199,$blue);
ob_start(); imagepng($source); $png=(string)ob_get_clean();
$encoded=base64_encode($png);
$avatar=ProfileImage::render('avatar',$encoded,0,500);
$size=getimagesizefromstring($avatar);
imageCheck($size[0]===256 && $size[1]===256 && $size[2]===IMAGETYPE_JPEG,'avatar is a real 256 square JPEG');
$left=imagecreatefromstring($avatar);
imageCheck(((imagecolorat($left,128,128)>>16)&255)>220,'left crop selects the red half');
$right=imagecreatefromstring(ProfileImage::render('avatar',$encoded,1000,500));
imageCheck((imagecolorat($right,128,128)&255)>220,'right crop selects the blue half');
$rotated=imagecreatefromstring(ProfileImage::render('avatar',$encoded,500,0,100,90));
imageCheck(((imagecolorat($rotated,128,128)>>16)&255)>220,'clockwise rotation maps source left to top');
$zoom=imagecreatefromstring(ProfileImage::render('avatar',$encoded,1000,500,300));
imageCheck((imagecolorat($zoom,128,128)&255)>220,'zoom and edge crop use the selected region');
$banner=ProfileImage::render('banner',$encoded);
$size=getimagesizefromstring($banner);
imageCheck($size[0]===960 && $size[1]===320 && strlen($banner)<=ProfileImage::MAX_OUTPUT,'banner is a bounded 960 by 320 JPEG');
imageCheck(!str_contains(ProfileImage::render('avatar',base64_encode($png.'<?php secret-marker ?>')),'secret-marker'),'trailing source data is removed by re-encoding');

foreach (['','!!!',base64_encode('<svg></svg>'),base64_encode('<?php echo 1; ?>'),$encoded.' '] as $bad) {
    rejected(fn()=>ProfileImage::render('avatar',$bad),'reject invalid encoding or non-raster content');
}
rejected(fn()=>ProfileImage::render('anything',$encoded),'reject unknown media slot');
foreach ([[1001,500,100,0],[500,-1,100,0],[500,500,99,0],[500,500,301,0],[500,500,100,45]] as [$x,$y,$z,$r]) {
    rejected(fn()=>ProfileImage::render('avatar',$encoded,$x,$y,$z,$r),'reject crop outside supported bounds');
}
rejected(fn()=>ProfileImage::render('avatar',str_repeat('A',6*1024*1024)),'reject oversized encoded upload before decoding');
$tiny=imagecreatetruecolor(8,8); ob_start(); imagepng($tiny); $tinyBytes=(string)ob_get_clean();
rejected(fn()=>ProfileImage::render('avatar',base64_encode($tinyBytes)),'reject too-small images');
// A real PNG IHDR with a huge dimension must be rejected before pixel decoding.
$huge=$png;
$huge=substr_replace($huge,pack('N',8192),16,4);
$huge=substr_replace($huge,pack('N',8192),20,4);
$huge=substr_replace($huge,pack('N',crc32(substr($huge,12,17))),29,4);
rejected(fn()=>ProfileImage::render('avatar',base64_encode($huge)),'reject decompression-bomb dimensions before GD decoding');
echo "MUCHO_PROFILE_IMAGES_OK\n";
