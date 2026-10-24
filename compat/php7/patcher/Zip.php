<?php
/** Bounded ZIP32 reader/writer. Does not extract paths or require ext-zip. */
declare(strict_types=1);

final class MuchoSharedZip
{
    const MAX_ENTRY = 100663296; // 96 MiB, below the 512 MiB shared memory budget.

    public static function bytes($path, $offset, $length)
    {
        $f=fopen($path,'rb');if(!$f)throw new RuntimeException('Cannot read client file.');
        try{if(fseek($f,$offset)!==0)throw new RuntimeException('Invalid archive offset.');
            $data='';while(strlen($data)<$length){$part=fread($f,min(1048576,$length-strlen($data)));if($part===false||$part==='')throw new RuntimeException('Truncated client file.');$data.=$part;}return $data;
        }finally{fclose($f);}
    }
    public static function write($f,$bytes)
    {
        $at=0;$size=strlen($bytes);while($at<$size){$n=fwrite($f,substr($bytes,$at,min(1048576,$size-$at)));if(!$n)throw new RuntimeException('Client storage is full.');$at+=$n;}
    }
    public static function entries($path)
    {
        $size=filesize($path);if($size<22||$size>1073741824||PHP_INT_SIZE<8)throw new RuntimeException('Expected a bounded ZIP32 APK on 64-bit PHP.');
        $tail=self::bytes($path,max(0,$size-65557),min($size,65557));$pos=strlen($tail);$found=false;
        do{$pos=strrpos(substr($tail,0,$pos),"PK\5\6");if($pos===false)throw new RuntimeException('APK ZIP directory is missing.');
            if($pos+22>strlen($tail))continue;
            $e=unpack('vdisk/vstart/vdiskcount/vcount/Vlength/Voffset/vcomment',substr($tail,$pos+4,18));
            if($pos+22+$e['comment']===strlen($tail)){$found=true;break;}
        }while($pos>0);
        if(!$found)throw new RuntimeException('APK has data after its ZIP directory.');
        $end=$size-strlen($tail)+$pos;
        if($e['disk']||$e['start']||$e['count']!==$e['diskcount']||$e['count']>20000||$e['count']===0||$e['length']>8388608||$e['offset']+$e['length']!==$end)throw new RuntimeException('Split, ZIP64 or malformed APK is not supported.');
        $cd=self::bytes($path,$e['offset'],$e['length']);$at=0;$entries=array();$names=array();$ranges=array();$total=0;
        for($i=0;$i<$e['count'];$i++){
            if(substr($cd,$at,4)!=="PK\1\2"||$at+46>strlen($cd))throw new RuntimeException('Malformed APK central directory.');
            $x=unpack('vmade/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset',substr($cd,$at+4,42));
            $name=substr($cd,$at+46,$x['name']);$next=$at+46+$x['name']+$x['extra']+$x['comment'];
            if($next>strlen($cd)||$name===''||strlen($name)>4096||isset($names[$name])||preg_match('~(?:^/|\\x00|\\\\|(?:^|/)\.\.(?:/|$))~',$name)||$x['flags']&1||$x['disk']||!in_array($x['method'],array(0,8),true)||$x['size']>self::MAX_ENTRY||$x['compressed']>self::MAX_ENTRY)throw new RuntimeException('Unsupported or unsafe APK entry.');
            $total+=$x['size'];if($total>2147483648)throw new RuntimeException('APK expands beyond the processing limit.');
            $local=self::bytes($path,$x['offset'],30);if(substr($local,0,4)!=="PK\3\4")throw new RuntimeException('Invalid APK local header.');
            $l=unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra',substr($local,4));
            $dataAt=$x['offset']+30+$l['name']+$l['extra'];
            if($l['flags']!==$x['flags']||$l['method']!==$x['method']||$l['name']!==strlen($name)||self::bytes($path,$x['offset']+30,$l['name'])!==$name||$dataAt+$x['compressed']>$e['offset'])throw new RuntimeException('APK local/central headers disagree.');
            if(!($l['flags']&8)&&($l['crc']!==$x['crc']||$l['size']!==$x['size']||$l['compressed']!==$x['compressed']))throw new RuntimeException('APK entry sizes disagree.');
            $x['name']=$name;$x['data']=$dataAt;$entries[]=$x;$names[$name]=true;$ranges[]=array($x['offset'],$dataAt+$x['compressed']);$at=$next;
        }
        if($at!==strlen($cd)||!isset($names['AndroidManifest.xml']))throw new RuntimeException('APK manifest/directory is invalid.');
        sort($ranges);$last=0;foreach($ranges as $r){if($r[0]<$last)throw new RuntimeException('Overlapping APK entries.');$last=$r[1];}
        return $entries;
    }
    public static function read($path,$e)
    {
        $raw=self::bytes($path,$e['data'],$e['compressed']);
        $data=$e['method']===0?$raw:@gzinflate($raw,self::MAX_ENTRY);
        if(!is_string($data)||strlen($data)!==$e['size']||(int)sprintf('%u',crc32($data))!==$e['crc'])throw new RuntimeException('APK entry failed CRC/size verification: '.$e['name']);
        return array($raw,$data);
    }
    public static function signature($name)
    {
        return preg_match('~^META-INF/(?:MANIFEST\.MF|[^/]+\.(?:SF|RSA|DSA|EC)|SIG-[^/]+)$~iD',$name)===1;
    }
    public static function local($e,$offset)
    {
        $extra='';$alignment=substr($e['name'],-3)==='.so'?16384:4;
        if($e['method']===0){$pad=($alignment-(($offset+30+strlen($e['name']))%$alignment))%$alignment;if($pad>0){if($pad<4)$pad+=$alignment;$extra=pack('vv',0xd935,$pad-4).str_repeat("\0",$pad-4);}}
        return pack('VvvvvvVVVvv',0x04034b50,20,$e['flags']&0x0806,$e['method'],$e['time'],$e['date'],$e['crc'],$e['compressed'],$e['size'],strlen($e['name']),strlen($extra)).$e['name'].$extra;
    }
    public static function central($e)
    {
        return pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,$e['flags']&0x0806,$e['method'],$e['time'],$e['date'],$e['crc'],$e['compressed'],$e['size'],strlen($e['name']),0,0,0,0,$e['external'],$e['new_offset']).$e['name'];
    }
    public static function end($count,$length,$offset)
    {
        return pack('VvvvvVVv',0x06054b50,0,0,$count,$count,$length,$offset,0);
    }
    public static function append($f,$name,$data)
    {
        $e=array('name'=>$name,'method'=>0,'flags'=>0,'time'=>0,'date'=>33,'crc'=>(int)sprintf('%u',crc32($data)),'compressed'=>strlen($data),'size'=>strlen($data),'external'=>0,'new_offset'=>ftell($f));
        self::write($f,self::local($e,$e['new_offset']));self::write($f,$data);return $e;
    }
}
