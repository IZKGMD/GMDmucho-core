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

// X-Forwarded-For may contain a spoofed prefix supplied by the client.
// Trust the nearest untrusted hop, not the leftmost claimed address.
if (ClientIp::resolve([
    'REMOTE_ADDR' => '10.10.2.3',
    'HTTP_X_FORWARDED_FOR' => '192.0.2.250, 203.0.113.77',
]) !== '203.0.113.77') {
    throw new RuntimeException('spoofed XFF prefix bypassed trusted proxy boundary');
}
if (ClientIp::resolve([
    'REMOTE_ADDR' => '10.10.2.3',
    'HTTP_X_FORWARDED_FOR' => '192.0.2.250, 203.0.113.77, 10.10.2.4',
]) !== '203.0.113.77') {
    throw new RuntimeException('multi-hop trusted proxy chain incorrectly resolved');
}
if (ClientIp::resolve([
    'REMOTE_ADDR' => '10.10.2.3',
    'HTTP_X_FORWARDED_FOR' => '192.0.2.250, invalid-ip',
]) !== '10.10.2.3') {
    throw new RuntimeException('malformed nearest XFF hop must not be trusted');
}

// Player dashboard login and upload quotas must share the canonical proxy
// trust boundary with the main GD API, never raw client-supplied headers.
$publicPeer = [
    'REMOTE_ADDR' => '198.51.100.20',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.88',
];
if (ClientIp::resolve($publicPeer) !== '198.51.100.20') {
    throw new RuntimeException('spoofed Cloudflare header bypasses rate limits for direct peers');
}
$cloudflarePeer = [
    'REMOTE_ADDR' => '173.245.48.20',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.88',
];
if (ClientIp::resolve($cloudflarePeer) !== '203.0.113.88') {
    throw new RuntimeException('trusted Cloudflare edge lost the real client IP');
}
foreach ([
    'public/dashboard/index.php',
    'compat/php7/core/public/dashboard/index.php',
] as $path) {
    $portal = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    $start = strpos($portal, 'function pdIp(): string');
    $next = $start === false ? false : strpos($portal, 'function pdRedirect(', $start);
    $function = $start === false || $next === false ? '' : substr($portal, $start, $next - $start);
    if (!str_contains($function, '\\MuchoCore\\Http\\ClientIp::resolve($_SERVER)')
        || str_contains($function, 'HTTP_CF_CONNECTING_IP')) {
        throw new RuntimeException('player portal trusts unvalidated forwarded IP headers: ' . $path);
    }
}

putenv('MUCHO_TRUSTED_PROXY_CIDRS');
unset($_ENV['MUCHO_TRUSTED_PROXY_CIDRS']);
echo "client-ip-trusted-proxy: OK\n";
