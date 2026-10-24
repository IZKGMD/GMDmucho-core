<?php
/** Android JAR/v1 and APK-v2 signing using PHP OpenSSL only; no processes. */
declare(strict_types=1);
final class MuchoSharedApkSigner
{
    public static function der($pem)
    {
        $der=base64_decode(preg_replace('~-----[^-]+-----|\s~','',$pem),true);
        if(!is_string($der)||$der==='')throw new RuntimeException('Invalid signing certificate.');return $der;
    }
    public static function identity($root)
    {
        foreach(array('openssl_pkey_new','openssl_csr_new','openssl_csr_sign','openssl_pkcs7_sign','openssl_sign') as $fn)if(!function_exists($fn))throw new RuntimeException('PHP OpenSSL is required for APK signing.');
        $path=$root.'/config/php7-apk-signer.json';$lock=fopen($root.'/config/php7-apk-signer.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock the APK signing identity.');
        try{
            if(is_link($path))throw new RuntimeException('Invalid APK signing identity path.');
            if(is_file($path)){$id=json_decode((string)file_get_contents($path),true);if(!is_array($id)||!isset($id['key'],$id['certificate']))throw new RuntimeException('Stored APK identity is damaged. Restore its backup; do not generate a new key.');}
            else{
                $options=array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'digest_alg'=>'sha256');
                $key=openssl_pkey_new($options);$csr=$key?openssl_csr_new(array('commonName'=>'MuchoCore Android','organizationName'=>'MuchoCore'),$key,$options):false;
                $cert=$csr?openssl_csr_sign($csr,null,$key,10000,$options):false;$keyPem='';$certPem='';
                if(!$cert||!openssl_pkey_export($key,$keyPem)||!openssl_x509_export($cert,$certPem))throw new RuntimeException('PHP OpenSSL cannot create an APK identity. Check the provider OpenSSL configuration.');
                $id=array('key'=>$keyPem,'certificate'=>$certPem);mucho7_private_write($path,json_encode($id));
            }
            $key=openssl_pkey_get_private($id['key']);$certKey=openssl_pkey_get_public($id['certificate']);
            $details=$key?openssl_pkey_get_details($key):false;$certDetails=$certKey?openssl_pkey_get_details($certKey):false;
            if(!$details||!$certDetails||$details['key']!==$certDetails['key']||$details['type']!==OPENSSL_KEYTYPE_RSA)throw new RuntimeException('APK signing key and certificate do not match.');
            $id['public']=self::der($details['key']);$id['der']=self::der($id['certificate']);return $id;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function line($name,$value)
    {
        $line=$name.': '.$value;$out='';while(strlen($line)>70){$out.=substr($line,0,70)."\r\n";$line=' '.substr($line,70);}return $out.$line."\r\n";
    }
    public static function manifestSection($name,$data)
    {
        return self::line('Name',$name).self::line('SHA-256-Digest',base64_encode(hash('sha256',$data,true)))."\r\n";
    }
    public static function jar($identity,$manifest,$dir)
    {
        $sf="Signature-Version: 1.0\r\nCreated-By: MuchoCore Shared\r\nX-Android-APK-Signed: 2\r\n".self::line('SHA-256-Digest-Manifest',base64_encode(hash('sha256',$manifest,true)))."\r\n";
        $at=strpos($manifest,"\r\n\r\n")+4;
        while($at<strlen($manifest)){
            $end=strpos($manifest,"\r\n\r\n",$at);if($end===false)throw new RuntimeException('Invalid APK manifest section.');
            $section=substr($manifest,$at,$end+4-$at);$unfolded=str_replace("\r\n ",'',$section);
            if(!preg_match('/^Name: ([^\r\n]+)\r\n/',$unfolded,$name))throw new RuntimeException('APK manifest entry has no name.');
            $sf.=self::line('Name',$name[1]).self::line('SHA-256-Digest',base64_encode(hash('sha256',$section,true)))."\r\n";$at=$end+4;
        }
        $input=$dir.'/signature.sf';$output=$dir.'/signature.smime';
        if(file_put_contents($input,$sf)===false||!openssl_pkcs7_sign($input,$output,$identity['certificate'],$identity['key'],array(),PKCS7_BINARY|PKCS7_DETACHED|PKCS7_NOATTR))throw new RuntimeException('PHP OpenSSL could not sign the APK manifest.');
        $smime=(string)file_get_contents($output);
        if(!preg_match('~Content-Type: application/(?:x-)?pkcs7-signature[^\r\n]*\r?\n(?:[^\r\n]+\r?\n)*\r?\n([A-Za-z0-9+/=\r\n]+)~i',$smime,$m))throw new RuntimeException('Cannot decode the generated APK certificate signature.');
        $der=base64_decode(preg_replace('/\s/','',$m[1]),true);if(!$der)throw new RuntimeException('Invalid APK certificate signature.');return array($sf,$der);
    }
    private static function lp($data){return pack('V',strlen($data)).$data;}
    private static function u64($n){return pack('VV',$n,0);}
    public static function block($identity,$digests)
    {
        $digest=hash('sha256',"\x5a".pack('V',count($digests)).implode('',$digests),true);
        $signed=self::lp(self::lp(pack('V',0x0103).self::lp($digest))).self::lp(self::lp($identity['der'])).self::lp('');
        $signature='';
        if(!openssl_sign($signed,$signature,$identity['key'],OPENSSL_ALGO_SHA256)||openssl_verify($signed,$signature,$identity['certificate'],OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('APK v2 signature verification failed.');
        $signer=self::lp($signed).self::lp(self::lp(pack('V',0x0103).self::lp($signature))).self::lp($identity['public']);
        $v2=self::lp(self::lp($signer));$pair=self::u64(strlen($v2)+4).pack('V',0x7109871a).$v2;$size=strlen($pair)+24;
        return self::u64($size).$pair.self::u64($size).'APK Sig Block 42';
    }
}
