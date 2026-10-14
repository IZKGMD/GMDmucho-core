<?php
declare(strict_types=1);
namespace MuchoCore\Media;
use RuntimeException;

final class MessageImage {
    public const MAX_INPUT=4*1024*1024;
    public const MAX_OUTPUT=256*1024;
    public const MAX_EDGE=2048;
    public static function decode(string $encoded):string {
        if($encoded===''||strlen($encoded)>5592410)throw new RuntimeException('Choose a JPG or PNG up to 4 MB.');
        $bytes=base64_decode($encoded,true);
        if($bytes===false||strlen($bytes)>self::MAX_INPUT||base64_encode($bytes)!==$encoded)throw new RuntimeException('Invalid image data.');
        return $bytes;
    }
    public static function mime(string $bytes):string {
        if(str_starts_with($bytes,"\x89PNG\r\n\x1a\n"))return 'image/png';
        if(str_starts_with($bytes,"\xff\xd8"))return 'image/jpeg';
        throw new RuntimeException('Image data is unavailable.');
    }
    private static function encode(\GdImage $image,string $format,int $quality):string {
        ob_start();try {
            $ok=$format==='png'?imagepng($image,null,9):imagejpeg($image,null,$quality);
            if(!$ok)throw new RuntimeException('Image encoding failed.');
            return (string)ob_get_contents();
        }finally {ob_end_clean();}
    }
    private static function compress(\GdImage $target):?array {
        $best=null;$guard=null;
        $consider=static function(string $bytes,string $format,bool $lossless=false)use(&$best,&$guard,$target):bool {
            if(strlen($bytes)>self::MAX_OUTPUT||($best&&strlen($bytes)>=strlen($best['bytes'])))return true;
            if(!$lossless){
                $decoded=@imagecreatefromstring($bytes);if(!$decoded)return false;
                try {$guard??=new ImageQuality($target);if(!$guard->readable($decoded))return false;}
                finally {imagedestroy($decoded);}
            }
            $best=['bytes'=>$bytes,'format'=>$format];return true;
        };
        // Text/flat artwork often compresses smaller as PNG and keeps crisp edges.
        $consider(self::encode($target,'png',9),'png',true);
        foreach([256,128] as $colors){
            $palette=imagecreatetruecolor(imagesx($target),imagesy($target));
            if(!$palette)continue;
            try {imagecopy($palette,$target,0,0,0,0,imagesx($target),imagesy($target));
                if(imagetruecolortopalette($palette,false,$colors))$consider(self::encode($palette,'png',9),'png');
            }finally {imagedestroy($palette);}
        }
        imageinterlace($target,true);
        foreach([84,78,72,66,60,54] as $quality){
            // Stop lowering JPEG quality as soon as the readability guard fails.
            if(!$consider(self::encode($target,'jpeg',$quality),'jpeg'))break;
        }
        return $best;
    }
    public static function render(string $bytes):array {
        $size=@getimagesizefromstring($bytes);
        if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||$size[0]<16||$size[1]<16||
           $size[0]>8192||$size[1]>8192||$size[0]*$size[1]>12000000)throw new RuntimeException('Choose a valid JPG/PNG, 16 pixels to 12 megapixels.');
        if(!function_exists('imagecreatefromstring'))throw new RuntimeException('Enable the PHP GD extension.');
        if(max($size[0],$size[1])/min($size[0],$size[1])>80)throw new RuntimeException('Image is too narrow. Crop it first.');
        $source=@imagecreatefromstring($bytes);if(!$source)throw new RuntimeException('Image is damaged.');
        try {
            foreach([self::MAX_EDGE,1600,1280] as $edge){
                $scale=min(1,$edge/max($size[0],$size[1]));$w=max(16,(int)round($size[0]*$scale));$h=max(16,(int)round($size[1]*$scale));
                $target=imagecreatetruecolor($w,$h);if(!$target)throw new RuntimeException('Image processing failed.');
                try {
                    imagefill($target,0,0,imagecolorallocate($target,27,41,65));
                    if(!imagecopyresampled($target,$source,0,0,0,0,$w,$h,$size[0],$size[1]))throw new RuntimeException('Image processing failed.');
                    $best=self::compress($target);
                    if($best)return $best+['width'=>$w,'height'=>$h,'hash'=>hash('sha256',$best['bytes'])];
                }finally {imagedestroy($target);}
                // Never repeatedly recompress a small image below the quality floor.
                if($scale===1)break;
            }
            throw new RuntimeException('Image is too detailed to compress clearly. Crop it or send smaller parts.');
        }finally {imagedestroy($source);}
    }
}
