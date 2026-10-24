<?php
/** Known-answer and cross-runtime checks for the original GCM save format. */
declare(strict_types=1);
require dirname(__DIR__,2).'/compat/php7/domain.php';
function aeadCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
foreach(array(
    array(128,'0388dace60b6a392f328c2b971b2fe78','ab6e47d42cec13bdf53a67b21257bddf'),
    array(256,'cea7403d4d606b6e074ec5d3baf39d18','d0d1c8a799996bf0265b98b5d48ab919')
) as $vector){
    $key=str_repeat("\0",$vector[0]/8);$nonce=str_repeat("\0",12);$plain=str_repeat("\0",16);$tag='';
    $encrypted=mucho7_openssl_encrypt($plain,'aes-'.$vector[0].'-gcm',$key,1,$nonce,$tag,'',16,true);
    aeadCheck(bin2hex($encrypted)===$vector[1] && bin2hex($tag)===$vector[2],'NIST known-answer encryption');
    aeadCheck(mucho7_openssl_decrypt($encrypted,'aes-'.$vector[0].'-gcm',$key,1,$nonce,$tag,'',true)===$plain,'NIST known-answer decryption');
}
foreach(array(0,1,15,16,17,1024,65536) as $size){
    $key=random_bytes(32);$nonce=random_bytes(12);$plain=$size?random_bytes($size):'';$aad='MuchoCore fixture';$tag='';
    $encrypted=mucho7_openssl_encrypt($plain,'aes-256-gcm',$key,1,$nonce,$tag,$aad,16,true);
    aeadCheck(mucho7_openssl_decrypt($encrypted,'aes-256-gcm',$key,1,$nonce,$tag,$aad,true)===$plain,'GCM round-trip byte preservation');
    aeadCheck(mucho7_openssl_decrypt($encrypted,'aes-256-gcm',$key,1,$nonce,$tag,'wrong',true)===false,'Wrong AAD accepted');
    $badTag=$tag;$badTag[0]=$badTag[0]^"\1";
    aeadCheck(mucho7_openssl_decrypt($encrypted,'aes-256-gcm',$key,1,$nonce,$badTag,$aad,true)===false,'Modified tag accepted');
    if($size){$bad=$encrypted;$bad[0]=$bad[0]^"\1";aeadCheck(mucho7_openssl_decrypt($bad,'aes-256-gcm',$key,1,$nonce,$tag,$aad,true)===false,'Modified ciphertext accepted');}
    if(PHP_VERSION_ID>=70100 && function_exists('openssl_encrypt')){
        $nativeTag='';$native=openssl_encrypt($plain,'aes-256-gcm',$key,1,$nonce,$nativeTag,$aad,16);
        aeadCheck($native===$encrypted && hash_equals($nativeTag,$tag),'PHP7 fallback differs from native OpenSSL GCM');
        aeadCheck(openssl_decrypt($encrypted,'aes-256-gcm',$key,1,$nonce,$tag,$aad)===$plain,'Native OpenSSL cannot read fallback');
    }
}
$root=dirname(__DIR__,2).'/compat/php7/vendor';$vendor=json_decode(file_get_contents($root.'/sources.json'),true);
foreach($vendor['files'] as $file=>$hash)aeadCheck(hash_equals($hash,hash_file('sha256',$root.'/'.$file)),'Vendored AES dependency changed');
echo "PHP7_AEAD_NIST_CROSS_RUNTIME_TAMPER_OK\n";
