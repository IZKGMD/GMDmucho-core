<?php
declare(strict_types=1);
namespace MuchoCore\Media;

/** Sampled structural/color guards, with extra checks on sharp text edges. */
final class ImageQuality {
    private array $blocks=[];
    private array $edges=[];
    public function __construct(\GdImage $reference) {
        $w=imagesx($reference);$h=imagesy($reference);
        for($row=0;$row<16;$row++)for($col=0;$col<16;$col++){
            $x=(int)round($col*max(0,$w-8)/15);$y=(int)round($row*max(0,$h-8)/15);
            $pixels=[];$sum=0.;$squares=0.;
            for($dy=0;$dy<min(8,$h);$dy++)for($dx=0;$dx<min(8,$w);$dx++){
                $rgb=imagecolorat($reference,$x+$dx,$y+$dy)&0xffffff;$l=self::luma($rgb);
                $pixels[]=[$x+$dx,$y+$dy,$rgb,$l];$sum+=$l;$squares+=$l*$l;
            }
            $n=count($pixels);$mean=$sum/$n;
            $this->blocks[]=[$pixels,$mean,max(0.,$squares/$n-$mean*$mean)];
        }
        $step=max(1,(int)ceil(max($w,$h)/256));
        for($y=0;$y<$h-1;$y+=$step)for($x=($y%$step);$x<$w-1;$x+=$step){
            $rgb=imagecolorat($reference,$x,$y)&0xffffff;
            if(self::distance($rgb,imagecolorat($reference,$x+1,$y)&0xffffff)>72||
               self::distance($rgb,imagecolorat($reference,$x,$y+1)&0xffffff)>72)$this->edges[]=[$x,$y,$rgb];
        }
    }
    private static function luma(int $rgb):float {return .299*(($rgb>>16)&255)+.587*(($rgb>>8)&255)+.114*($rgb&255);}
    private static function distance(int $a,int $b):int {return abs((($a>>16)&255)-(($b>>16)&255))+abs((($a>>8)&255)-(($b>>8)&255))+abs(($a&255)-($b&255));}
    public function readable(\GdImage $candidate):bool {
        if(!imageistruecolor($candidate))imagepalettetotruecolor($candidate);
        $ssim=0.;$color=0.;$samples=0;
        foreach($this->blocks as [$pixels,$mean,$variance]){
            $sum=0.;$squares=0.;$cross=0.;$n=count($pixels);
            foreach($pixels as [$x,$y,$rgb,$l]){
                $got=imagecolorat($candidate,$x,$y)&0xffffff;$v=self::luma($got);
                $sum+=$v;$squares+=$v*$v;$cross+=$l*$v;$color+=self::distance($rgb,$got);$samples++;
            }
            $other=$sum/$n;$otherVariance=max(0.,$squares/$n-$other*$other);$covariance=$cross/$n-$mean*$other;
            $score=((2*$mean*$other+6.5025)*(2*$covariance+58.5225))/
                (($mean*$mean+$other*$other+6.5025)*($variance+$otherVariance+58.5225));
            if($score<.80)return false;$ssim+=$score;
        }
        if($ssim/count($this->blocks)<.975||$color/(3*$samples)>8.)return false;
        if($this->edges){$error=0.;foreach($this->edges as [$x,$y,$rgb])$error+=self::distance($rgb,imagecolorat($candidate,$x,$y)&0xffffff);
            if($error/(3*count($this->edges))>10.)return false;
        }
        return true;
    }
}
