<?php
declare(strict_types=1);
namespace MuchoCore\Media;
use RuntimeException;
final class MessageImage {
    public const MAX_INPUT=4*1024*1024;
    public const MAX_OUTPUT=512*1024;
    public static function decode(string $encoded): string {
        if($encoded===''||strlen($encoded)>5592410)throw new RuntimeException('Choose a JPG or PNG up to 4 MB.');
        $bytes=base64_decode($encoded,true);
        if($bytes===false||strlen($bytes)>self::MAX_INPUT||base64_encode($bytes)!==$encoded)
            throw new RuntimeException('Invalid image data.');
        return $bytes;
    }
    public static function render(string $bytes): array {
        $size=@getimagesizefromstring($bytes);
        if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||
           $size[0]<16||$size[1]<16||$size[0]>8192||$size[1]>8192||$size[0]*$size[1]>12000000)
            throw new RuntimeException('Choose a valid JPG/PNG, 16 pixels to 12 megapixels.');
        if(!function_exists('imagecreatefromstring'))throw new RuntimeException('Enable the PHP GD extension.');
        $source=@imagecreatefromstring($bytes);
        if(!$source)throw new RuntimeException('Image is damaged.');
        $target=null;
        try {
            if(max($size[0],$size[1])/min($size[0],$size[1])>80)throw new RuntimeException('Image is too narrow. Crop it first.');
            $scale=min(1,1280/max($size[0],$size[1]));
            $w=max(1,(int)round($size[0]*$scale));$h=max(1,(int)round($size[1]*$scale));
            $target=imagecreatetruecolor($w,$h);
            if(!$target)throw new RuntimeException('Image processing failed.');
            imagefill($target,0,0,imagecolorallocate($target,27,41,65));
            if(!imagecopyresampled($target,$source,0,0,0,0,$w,$h,$size[0],$size[1]))throw new RuntimeException('Image processing failed.');
            $jpeg='';
            foreach([85,72,58] as $quality){
                ob_start();try {if(!imagejpeg($target,null,$quality))throw new RuntimeException('Image encoding failed.');$jpeg=(string)ob_get_contents();}finally{ob_end_clean();}
                if(strlen($jpeg)<=self::MAX_OUTPUT)break;
            }
            if($jpeg===''||strlen($jpeg)>self::MAX_OUTPUT)throw new RuntimeException('Image could not be compressed.');
            return ['bytes'=>$jpeg,'width'=>$w,'height'=>$h,'hash'=>hash('sha256',$jpeg)];
        }finally{imagedestroy($source);if($target instanceof \GdImage)imagedestroy($target);}
    }
}
