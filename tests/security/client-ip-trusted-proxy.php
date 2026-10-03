<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__,2).'/src/Http/ClientIp.php';
use MuchoCore\Http\ClientIp;

putenv('MUCHO_TRUSTED_PROXY_CIDRS=10.10.0.0/16');
$_ENV['MUCHO_TRUSTED_PROXY_CIDRS']='10.10.0.0/16';

if(ClientIp::resolve(['REMOTE_ADDR'=>'10.10.2.3','HTTP_X_FORWARDED_FOR'=>'203.0.113.77'])!=='203.0.113.77'){
    throw new RuntimeException('trusted proxy CIDR failed');
}
if(ClientIp::resolve(['REMOTE_ADDR'=>'10.11.2.3','HTTP_X_FORWARDED_FOR'=>'203.0.113.78'])!=='10.11.2.3'){
    throw new RuntimeException('untrusted proxy supplied XFF');
}
if(ClientIp::resolve(['REMOTE_ADDR'=>'198.51.100.20','HTTP_X_FORWARDED_FOR'=>'203.0.113.79'])!=='198.51.100.20'){
    throw new RuntimeException('public peer supplied XFF');
}
putenv('MUCHO_TRUSTED_PROXY_CIDRS');
unset($_ENV['MUCHO_TRUSTED_PROXY_CIDRS']);
echo "client-ip-trusted-proxy: OK\n";
