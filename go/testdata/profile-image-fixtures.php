<?php
// Development-only GD reference. The Go executable never loads this file.
declare(strict_types=1);
require __DIR__.'/../../src/Appearance/ProfileImage.php';
use MuchoCore\Appearance\ProfileImage;
$image=imagecreatetruecolor(128,64);
foreach([[0,0,63,31,220,30,40],[64,0,127,31,30,210,60],[0,32,63,63,30,60,220],[64,32,127,63,240,240,230]] as [$x1,$y1,$x2,$y2,$r,$g,$b]) {
    imagefilledrectangle($image,$x1,$y1,$x2,$y2,imagecolorallocate($image,$r,$g,$b));
}
ob_start();imagepng($image);$png=(string)ob_get_clean();imagedestroy($image);
$text='Comment'."\0".'private-source-metadata';
$chunk=pack('N',strlen($text)).'tEXt'.$text.pack('N',crc32('tEXt'.$text));
$png=substr($png,0,-12).$chunk.substr($png,-12);
$input=base64_encode($png);$cases=[];
foreach(['avatar','banner'] as $kind) {
    foreach([0,90,180,270] as $rotation) {
        foreach([[500,500,100],[0,0,100],[1000,1000,100],[300,700,250]] as [$x,$y,$zoom]) {
            $cases[]=[$kind,$input,$x,$y,$zoom,$rotation];
        }
    }
}
$cases[]= ['other',$input,500,500,100,0];
$cases[]= ['avatar',$input,-1,500,100,0];
$cases[]= ['avatar',$input,500,500,99,0];
$cases[]= ['avatar',$input,500,500,100,45];
$cases[]= ['avatar','',500,500,100,0];
$cases[]= ['avatar','not-base64',500,500,100,0];
$cases[]= ['avatar',base64_encode('not an image'),500,500,100,0];
$result=[];
foreach($cases as [$kind,$encoded,$x,$y,$zoom,$rotation]) {
    $row=compact('kind','encoded','x','y','zoom','rotation');
    try { $row['jpeg']=base64_encode(ProfileImage::render($kind,$encoded,$x,$y,$zoom,$rotation)); }
    catch(Throwable $e) { $row['error']=$e->getMessage(); }
    $result[]=$row;
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
