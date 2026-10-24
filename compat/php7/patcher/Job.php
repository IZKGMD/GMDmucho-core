<?php
/** Resumable client work. Every call performs a bounded amount of local work. */
declare(strict_types=1);
require_once __DIR__.'/Zip.php';
require_once __DIR__.'/Signer.php';

final class MuchoSharedPatcherJob
{
    public static function storage($root)
    {
        $dir=$root.'/storage/shared-client-patcher';
        if(is_link($root.'/storage')||is_link($dir))throw new RuntimeException('Invalid patcher storage.');
        if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Patcher storage is not writable.');
        if(!is_file($dir.'/.htaccess'))mucho7_private_write($dir.'/.htaccess',"Options -Indexes\nRequire all denied\n");
        return $dir;
    }
    public static function directory($root,$id)
    {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid patcher job.');
        $dir=self::storage($root).'/'.$id;if(is_link($dir))throw new RuntimeException('Invalid patcher job directory.');return $dir;
    }
    public static function load($dir,$allowExpired=false)
    {
        $s=json_decode((string)@file_get_contents($dir.'/state.json'),true);
        if(!is_array($s)||!isset($s['phase'],$s['expires'])||(!$allowExpired&&$s['expires']<time()))throw new RuntimeException('Patcher job expired. Start a new upload.');return $s;
    }
    private static function save($dir,$s){mucho7_private_write($dir.'/state.json',json_encode($s,JSON_UNESCAPED_SLASHES));}
    public static function cleanup($root)
    {
        $dir=self::storage($root);$lock=fopen($dir.'/.cleanup','c+');if(!$lock)return;
        try{if(!flock($lock,LOCK_EX|LOCK_NB))return;$last=(int)stream_get_contents($lock);if(time()-$last<300)return;rewind($lock);ftruncate($lock,0);fwrite($lock,(string)time());
            $count=0;foreach(new DirectoryIterator($dir) as $f){if($count>=32)break;if(!$f->isDir()||$f->isLink()||!preg_match('/^[a-f0-9]{32}$/D',$f->getFilename()))continue;$p=$f->getPathname();$stamp=@filemtime($p.'/state.json');if($stamp!==false&&time()-$stamp>18000){self::remove($p);$count++;}}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function remove($dir)
    {
        $l=@fopen($dir.'/lock','c');if(!$l)return;
        try{if(!flock($l,LOCK_EX|LOCK_NB))return;foreach(new DirectoryIterator($dir) as $f)if($f->isFile()&&!$f->isLink()&&$f->getFilename()!=='lock')@unlink($f->getPathname());}
        finally{flock($l,LOCK_UN);fclose($l);}@unlink($dir.'/lock');@rmdir($dir);
    }
    public static function start($root,$owner,$filename,$size,$server,$alongside,$fingerprint)
    {
        $server=\MuchoCore\Client\WindowsClientPatcher::validateServerUrl($server);
        $kind=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
        if(!in_array($kind,array('apk','exe'),true)||strlen($filename)>180||$size<1024||$size>($kind==='apk'?536870912:67108864)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))throw new RuntimeException('Choose an APK up to 512 MiB or EXE up to 64 MiB.');
        if($kind==='apk'&&(!function_exists('openssl_sign')||!function_exists('openssl_pkcs7_sign')))throw new RuntimeException('The hosting account needs PHP OpenSSL to sign APKs.');
        self::cleanup($root);$base=self::storage($root);$active=0;
        foreach(new DirectoryIterator($base) as $f)if($f->isDir()&&!$f->isDot()&&preg_match('/^[a-f0-9]{32}$/D',$f->getFilename()))$active++;
        if($active>=8)throw new RuntimeException('Patcher storage has eight active jobs. Remove a completed job or wait for cleanup.');
        $free=@disk_free_space($base);if($free!==false&&$free<$size*3+67108864)throw new RuntimeException('Not enough free hosting space for a safe patch copy.');
        $id=bin2hex(random_bytes(16));$dir=self::directory($root,$id);if(!mkdir($dir,0700))throw new RuntimeException('Cannot create patcher job.');
        $s=array('id'=>$id,'owner'=>$owner,'kind'=>$kind,'size'=>$size,'offset'=>0,'server'=>$server,'alongside'=>(bool)$alongside,'fingerprint'=>$fingerprint,'phase'=>'upload','expires'=>time()+14400,'created'=>time());
        mucho7_private_write($dir.'/input','');self::save($dir,$s);return self::status($s);
    }
    public static function status($s)
    {
        $p=array('upload'=>0,'inspect'=>36,'rebuild'=>40,'jar'=>64,'directory'=>66,'digest'=>68,'assemble'=>84,'finish'=>99,'done'=>100);
        $progress=$p[$s['phase']];if($s['phase']==='upload')$progress=(int)(35*$s['offset']/$s['size']);
        if($s['phase']==='rebuild')$progress=40+(int)(23*$s['index']/max(1,count($s['entries'])));
        if($s['phase']==='digest')$progress=68+(int)(15*$s['hashed']/max(1,$s['unsigned_size']));
        if($s['phase']==='assemble')$progress=84+(int)(14*$s['copied']/max(1,$s['cd_offset']));
        $r=array('ok'=>true,'id'=>$s['id'],'phase'=>$s['phase'],'progress'=>$progress,'offset'=>$s['offset'],'size'=>$s['size'],'fingerprint'=>$s['fingerprint'],'server_url'=>$s['server'],'kind'=>$s['kind'],'alongside'=>$s['alongside'],'chunk_size'=>2097152);
        if(isset($s['report']))$r['report']=$s['report'];return $r;
    }
    public static function owned($s,$owner){if($s['owner']!==$owner)throw new RuntimeException('Patcher job belongs to another administrator.');}
    public static function chunk($root,$owner,$id,$offset,$bytes,$digest)
    {
        $dir=self::directory($root,$id);$lock=fopen($dir.'/lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Patcher is busy. Retry shortly.');
        try{$s=self::load($dir);self::owned($s,$owner);$n=strlen($bytes);
            if($s['phase']!=='upload'||$n<1||$n>2097152||$offset<0||$offset+$n>$s['size']||!preg_match('/^[a-f0-9]{64}$/D',$digest)||!hash_equals($digest,hash('sha256',$bytes)))throw new RuntimeException('Invalid upload chunk or SHA-256.');
            if($offset<$s['offset']){if($offset+$n<=$s['offset']&&hash_equals($digest,hash('sha256',MuchoSharedZip::bytes($dir.'/input',$offset,$n))))return self::status($s);throw new RuntimeException('Upload retry differs from committed bytes.');}
            if($offset!==$s['offset'])throw new RuntimeException('Upload offset changed. Resume from server status.');
            $f=fopen($dir.'/input','c+b');if(!$f)throw new RuntimeException('Upload file is unavailable.');try{ftruncate($f,$offset);fseek($f,$offset);MuchoSharedZip::write($f,$bytes);}finally{fclose($f);}
            $s['offset']+=$n;self::save($dir,$s);return self::status($s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function step($root,$owner,$id)
    {
        $dir=self::directory($root,$id);$lock=fopen($dir.'/lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Patcher is busy. Retry shortly.');
        try{$s=self::load($dir);self::owned($s,$owner);
            if($s['phase']==='done')return self::status($s);
            if($s['phase']==='upload'){
                if($s['offset']!==$s['size'])throw new RuntimeException('Upload has not finished.');
                // Bind browser resume fingerprint to the actual uploaded file.
                $f=fopen($dir.'/input','rb');$first=fread($f,min(65536,$s['size']));fseek($f,max(0,$s['size']-65536));$last=fread($f,min(65536,$s['size']));fclose($f);
                if(!hash_equals($s['fingerprint'],hash('sha256',$first.$last)))throw new RuntimeException('Uploaded file does not match the selected client.');
                $s['phase']='inspect';
            }elseif($s['phase']==='inspect'){
                if($s['kind']==='exe'){
                    $s['report']=\MuchoCore\Client\WindowsClientPatcher::patchFile($dir.'/input',$dir.'/result.exe',$s['server']);$s['phase']='done';
                }else{
                    $s['entries']=MuchoSharedZip::entries($dir.'/input');$s['strict2209']=false;
                    foreach($s['entries'] as $entry)if($entry['name']==='lib/arm64-v8a/libcocos2dcpp.so'){
                        list($raw,$native)=MuchoSharedZip::read($dir.'/input',$entry);$s['strict2209']=\MuchoCore\Client\AndroidGd2209Binary::supports($native);
                        if(!$s['strict2209']&&\MuchoCore\Client\AndroidGd2209Binary::resembles2209($native))throw new RuntimeException('Unknown or already modified Android GD 2.209 build. Choose the original APK.');unset($raw,$native);break;
                    }
                    $s['index']=0;$s['write_offset']=0;$s['manifest_offset']=strlen("Manifest-Version: 1.0\r\nCreated-By: MuchoCore Shared\r\n\r\n");$s['replacements']=0;$s['verified_native']=false;$s['patched_entries']=array();$s['output_entries']=array();$s['package']='';
                    mucho7_private_write($dir.'/unsigned','');mucho7_private_write($dir.'/manifest',"Manifest-Version: 1.0\r\nCreated-By: MuchoCore Shared\r\n\r\n");$s['phase']='rebuild';
                }
            }elseif($s['phase']==='rebuild'){
                self::rebuild($dir,$s);
            }elseif($s['phase']==='jar'){
                if($s['replacements']===0)throw new RuntimeException('No supported Geometry Dash API URLs were found.');
                if($s['alongside']&&!$s['verified_native'])throw new RuntimeException('Install alongside requires the verified original GD 2.209 ARM64 APK.');
                $identity=MuchoSharedApkSigner::identity($root);$manifest=(string)file_get_contents($dir.'/manifest');list($sf,$rsa)=MuchoSharedApkSigner::jar($identity,$manifest,$dir);
                $f=fopen($dir.'/unsigned','c+b');try{ftruncate($f,$s['write_offset']);fseek($f,$s['write_offset']);foreach(array('META-INF/MANIFEST.MF'=>$manifest,'META-INF/MUCHO.SF'=>$sf,'META-INF/MUCHO.RSA'=>$rsa) as $name=>$data)$s['output_entries'][]=MuchoSharedZip::append($f,$name,$data);$s['write_offset']=ftell($f);}finally{fclose($f);}
                $s['certificate_sha256']=hash('sha256',$identity['der']);$s['phase']='directory';
            }elseif($s['phase']==='directory'){
                $cd='';foreach($s['output_entries'] as $e)$cd.=MuchoSharedZip::central($e);$s['cd_offset']=$s['write_offset'];$s['cd_size']=strlen($cd);$end=MuchoSharedZip::end(count($s['output_entries']),strlen($cd),$s['cd_offset']);
                $f=fopen($dir.'/unsigned','c+b');try{ftruncate($f,$s['write_offset']);fseek($f,$s['write_offset']);MuchoSharedZip::write($f,$cd.$end);$s['unsigned_size']=ftell($f);}finally{fclose($f);}
                $s['digest_section']=0;$s['digest_offset']=0;$s['hashes']=array();$s['hashed']=0;$s['phase']='digest';unset($s['entries'],$s['output_entries']);
            }elseif($s['phase']==='digest'){
                $sections=array(array(0,$s['cd_offset']),array($s['cd_offset'],$s['cd_size']),array($s['cd_offset']+$s['cd_size'],22));$started=microtime(true);$chunks=0;
                while($s['digest_section']<3&&$chunks<16&&microtime(true)-$started<5){$section=$sections[$s['digest_section']];$n=min(1048576,$section[1]-$s['digest_offset']);
                    if($n>0){$data=MuchoSharedZip::bytes($dir.'/unsigned',$section[0]+$s['digest_offset'],$n);$s['hashes'][]=base64_encode(hash('sha256',"\xa5".pack('V',$n).$data,true));$s['digest_offset']+=$n;$s['hashed']+=$n;$chunks++;}
                    if($s['digest_offset']===$section[1]){$s['digest_section']++;$s['digest_offset']=0;}
                }
                if($s['digest_section']===3){$digests=array_map('base64_decode',$s['hashes']);$block=MuchoSharedApkSigner::block(MuchoSharedApkSigner::identity($root),$digests);mucho7_private_write($dir.'/block',$block);mucho7_private_write($dir.'/result.apk','');$s['copied']=0;$s['phase']='assemble';unset($s['hashes']);}
            }elseif($s['phase']==='assemble'){
                $f=fopen($dir.'/result.apk','c+b');try{ftruncate($f,$s['copied']);fseek($f,$s['copied']);$end=min($s['cd_offset'],$s['copied']+16777216);$started=microtime(true);
                    while($s['copied']<$end&&microtime(true)-$started<5){$n=min(1048576,$end-$s['copied']);MuchoSharedZip::write($f,MuchoSharedZip::bytes($dir.'/unsigned',$s['copied'],$n));$s['copied']+=$n;}
                    if($s['copied']===$s['cd_offset']){$block=(string)file_get_contents($dir.'/block');MuchoSharedZip::write($f,$block);MuchoSharedZip::write($f,MuchoSharedZip::bytes($dir.'/unsigned',$s['cd_offset'],$s['cd_size']));$endRecord=MuchoSharedZip::bytes($dir.'/unsigned',$s['cd_offset']+$s['cd_size'],22);$endRecord=substr_replace($endRecord,pack('V',$s['cd_offset']+strlen($block)),16,4);MuchoSharedZip::write($f,$endRecord);$s['phase']='finish';}
                }finally{fclose($f);}
            }elseif($s['phase']==='finish'){
                // Re-open the finished ZIP independently of the construction state.
                MuchoSharedZip::entries($dir.'/result.apk');
                $s['report']=array('server_url'=>$s['server'],'replacement_count'=>$s['replacements'],'patched_entries'=>$s['patched_entries'],'input_size'=>$s['size'],'output_size'=>filesize($dir.'/result.apk'),'input_sha256'=>hash_file('sha256',$dir.'/input'),'output_sha256'=>hash_file('sha256',$dir.'/result.apk'),'package_id'=>$s['package'],'certificate_sha256'=>$s['certificate_sha256'],'signed'=>true,'signature_schemes'=>array('v1','v2'),'source_build'=>$s['verified_native']?'Verified GD 2.209 ARM64':'Legacy GD URL layout');$s['phase']='done';
            }else throw new RuntimeException('Unknown patcher stage.');
            self::save($dir,$s);return self::status($s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function rebuild($dir,&$s)
    {
        $out=fopen($dir.'/unsigned','c+b');$mf=fopen($dir.'/manifest','c+b');if(!$out||!$mf)throw new RuntimeException('Cannot write APK working copy.');
        try{ftruncate($out,$s['write_offset']);fseek($out,$s['write_offset']);ftruncate($mf,$s['manifest_offset']);fseek($mf,$s['manifest_offset']);$started=microtime(true);$worked=0;$count=0;
            while($s['index']<count($s['entries'])&&$worked<16777216&&$count<256&&microtime(true)-$started<5){
                $e=$s['entries'][$s['index']];$s['index']++;$count++;if(MuchoSharedZip::signature($e['name']))continue;
                list($raw,$data)=MuchoSharedZip::read($dir.'/input',$e);$modified=false;
                if($e['name']==='AndroidManifest.xml'){$s['package']=\MuchoCore\Client\AndroidPackageRelocator::manifestPackage($data);if($s['alongside']){$data=\MuchoCore\Client\AndroidPackageRelocator::relocateManifest($data);$s['package']=\MuchoCore\Client\AndroidPackageRelocator::SIDE_BY_SIDE_ID;$modified=true;}}
                elseif($e['name']==='resources.arsc'){if($s['alongside'])$data=\MuchoCore\Client\AndroidPackageRelocator::relocateResources($data);$modified=true;}
                elseif(!$s['strict2209']||$e['name']==='lib/arm64-v8a/libcocos2dcpp.so'){list($data,$changes,$verified)=\MuchoCore\Client\AndroidClientPatcher::patchEntryBytes($e['name'],$data,$s['server']);if($changes){$modified=true;$s['replacements']+=$changes;$s['patched_entries'][]=array('name'=>$e['name'],'replacements'=>$changes);}$s['verified_native']=$s['verified_native']||$verified;}
                if($modified){$raw=$data;$e['method']=0;$e['compressed']=strlen($raw);$e['size']=strlen($data);$e['crc']=(int)sprintf('%u',crc32($data));}
                $e['new_offset']=ftell($out);MuchoSharedZip::write($out,MuchoSharedZip::local($e,$e['new_offset']));MuchoSharedZip::write($out,$raw);$s['output_entries'][]=$e;
                if(substr($e['name'],-1)!=='/')MuchoSharedZip::write($mf,MuchoSharedApkSigner::manifestSection($e['name'],$data));
                $worked+=$e['size'];unset($raw,$data);
            }
            $s['write_offset']=ftell($out);$s['manifest_offset']=ftell($mf);if($s['index']===count($s['entries']))$s['phase']='jar';
        }finally{fclose($out);fclose($mf);}
    }
}
