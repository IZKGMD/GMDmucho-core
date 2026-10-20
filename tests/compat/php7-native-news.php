<?php
/** Can run on PHP 7.0 with zlib; no composer, network or database. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../compat/php7/news.php';
function newsCheck($condition,$reason) {
    if (!$condition) { throw new RuntimeException($reason); }
}
$posts = array(array(
    'id'=>7,'title'=>'Test news','body'=>'Hello GDPS!',
    'url'=>'https://muchogdps.space/',
    'published_at'=>'2026-09-13 12:00:00'
));
$expected = '4,1|1:7;2:U2VwdGVtYmVyIDEzLCAyMDI2;3:VGVzdCBuZXdz;4:0,SGVsbG8gR0RQUyE='
    .'~2,UmVhZCBtb3Jl,aHR0cHM6Ly9tdWNob2dkcHMuc3BhY2Uv';
newsCheck(mucho7_news_payload($posts)===$expected,'GD native 2.209 wire parity');
newsCheck(mucho7_news_payload(array())==='4,0','native news empty feed');
$settings = array('enable_native_news'=>true,
    'site_url'=>'https://linasgdpsprivatese.5v.pl',
    'news_file'=>__DIR__.'/../../compat/php7/news.example.json');
$origin = mucho7_dispatch(null,'/database/getCustomContentURL.php','POST',array(),$settings);
newsCheck($origin==='https://linasgdpsprivatese.5v.pl/gd-content',
    'custom content host must match owner-configured hostname');
$version = mucho7_dispatch(null,'/gd-content/news/news_version.txt','GET',array(),$settings);
newsCheck($version==='1000001','private JSON revision');
$encoded = mucho7_dispatch(null,'/gd-content/news/news.dat','GET',array(),$settings);
$decoded = base64_decode(strtr($encoded,'-_','+/'),true);
newsCheck(is_string($decoded) && substr($decoded,0,2)==="\x1f\x8b", 'news gzip');
$raw = gzdecode($decoded);
newsCheck(is_string($raw) && strpos($raw,'Welcome to MuchoCore Shared')===false,
    'news text must remain encoded within compressed wire payload');
newsCheck(strpos($raw,'4,1|')===0,'news native version 4');
newsCheck(mucho7_dispatch(null,'/news/news.dat','POST',array(),$settings)==='-1',
    'read-only news endpoint');
$settings['enable_native_news']=false;
newsCheck(mucho7_dispatch(null,'/getCustomContentURL.php','POST',array(),$settings)==='-1',
    'news is disabled by default');
newsCheck(mucho7_dispatch(null,'/news/news.dat','GET',array(),$settings)==='-1',
    'news data disabled by default');
echo "PHP7_NATIVE_NEWS_WIRE_AND_DOMAIN_OK\n";
