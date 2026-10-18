<?php
declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Admin\AdminRbac;

require dirname(__DIR__,2).'/vendor/autoload.php';

$__muchoIsHttps =
    (($_SERVER['HTTPS'] ?? '') === 'on') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_secure', $__muchoIsHttps ? '1' : '0');
ini_set('session.cookie_samesite','Strict');

session_name('MUCHO_ADMIN');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if(empty($_SESSION['admin'])){
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$db=(new Database())->connection();
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

/*
 * Never trust a stale role stored in the session. Disabled or demoted
 * administrators must lose monitoring privileges immediately.
 */
$currentAdminId=(int)($_SESSION['admin']['id'] ?? 0);

if($currentAdminId<=0){
    $_SESSION=[];
    session_destroy();
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$adminQuery=$db->prepare(
    'SELECT id,username,role,is_active
     FROM admin_users
     WHERE id=:id
     LIMIT 1'
);
$adminQuery->execute(['id'=>$currentAdminId]);
$currentAdmin=$adminQuery->fetch(PDO::FETCH_ASSOC);

if(!$currentAdmin || (int)$currentAdmin['is_active']!==1){
    $_SESSION=[];
    session_destroy();
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$_SESSION['admin']['username']=(string)$currentAdmin['username'];
$_SESSION['admin']['role']=(string)$currentAdmin['role'];

// Re-check permissions on each request: session roles may have been revoked.
if (!AdminRbac::can($db, $currentAdmin, 'monitoring.view')) {
    http_response_code(403);
    echo json_encode(['error'=>'Monitoring access denied']);
    exit;
}

function output(array $x): never {
    echo json_encode(
        $x,
        JSON_UNESCAPED_UNICODE|
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function localEndpoint(string $path): array
{
    $shared =
        in_array(
            strtolower((string)getenv('MUCHO_SHARED_HOSTING')),
            ['1','true','yes','on'],
            true
        );

    $baseUrl=$shared
        ? rtrim(
            (string)(
                getenv('MUCHO_ACCOUNT_URL') ?: 'https://invalid.invalid'
            ),
            '/'
        )
        : 'http://caddy';

    $host=parse_url(
        $baseUrl,
        PHP_URL_HOST
    ) ?: 'localhost';

    $ctx=stream_context_create([
        'http'=>[
            'method'=>$path==='/health' ? 'GET' : 'POST',
            'header'=>
                "Host: ".$host."\r\n".
                "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'=>'',
            'timeout'=>4,
            'ignore_errors'=>true
        ]
    ]);

    $body=@file_get_contents(
        $baseUrl.$path,
        false,
        $ctx
    );

    $status=0;

    foreach($http_response_header ?? [] as $line){
        if(preg_match('~HTTP/\S+\s+(\d+)~',$line,$m)){
            $status=(int)$m[1];
            break;
        }
    }

    return [
        'path'=>$path,
        'status'=>$status,
        // GD endpoints normally reject empty authentication/form payloads
        // with HTTP 400/401/403. Report connectivity rather than claiming
        // the endpoint's full protocol behavior was successfully tested.
        'ok'=>$status>=200 && $status<500 && $status!==404,
        'body'=>''
    ];
}

$kind=(string)($_GET['kind'] ?? 'health');

if($kind==='health'){
    // A successful DB connection already happened above. Do not call
    // obsolete Nginx/PHP-FPM/Cloudflare root helpers from Docker installs.
    // Unknown services are explicitly unknown rather than falsely offline.
    $services=[
        ['name'=>'Database', 'state'=>'online', 'detail'=>'Authenticated SQL connection'],
        ['name'=>'Application', 'state'=>'unknown', 'detail'=>'See /health and API probes below'],
        ['name'=>'Worker / queue', 'state'=>'unknown', 'detail'=>'Not directly observable from PHP'],
    ];
    $paths=[
        '/health',
        '/getGJLevels21.php',
        '/loginGJAccount.php',
        '/registerGJAccount.php',
        '/getGJComments21.php',
        '/getGJMessages20.php',
        '/getGJUserList20.php',
        '/likeGJItem211.php',
        '/uploadFriendRequest20.php'
    ];

    $endpoints=[];
    foreach ($paths as $path) $endpoints[]=localEndpoint($path);

    // /health is a meaningful application probe, unlike empty GD requests.
    $services[1]['state']=$endpoints[0]['status']===200?'online':
        ($endpoints[0]['status']===0?'unknown':'warning');
    $services[1]['detail']=$endpoints[0]['status']
        ? 'Health endpoint returned HTTP '.$endpoints[0]['status']
        : 'Internal HTTP probe unavailable';

    // Optional database-backed diagnostics. A missing table or inaccessible
    // query is "not measured", never an invented zero/healthy reading.
    $checks=[];
    foreach ([
        ['Failed jobs', "SELECT COUNT(*) FROM mucho_jobs WHERE status='failed'", 'Investigate worker failures'],
        ['Unresolved alerts', 'SELECT COUNT(*) FROM mucho_system_alerts WHERE resolved=0', 'Review Security Center'],
    ] as [$name,$sql,$hint]) {
        $count=null;
        try {
            $count=(int)$db->query($sql)->fetchColumn();
        } catch (Throwable) {
            // Some installations do not have optional monitoring tables.
        }
        $checks[]=['name'=>$name,'count'=>$count,'hint'=>$hint];
    }

    output([
        'services'=>$services,
        'endpoints'=>$endpoints,
        'checks'=>$checks,
        'time'=>date('Y-m-d H:i:s'),
        'explanation'=>'GD endpoints are probed with empty requests; 400/401/403 can be normal. A response is not a gameplay test.',
    ]);
}

if($kind==='logs'){
    if (!AdminRbac::can($db,$currentAdmin,'system.manage')) {
        http_response_code(403);
        echo json_encode(['error'=>'Forbidden']);
        exit;
    }

    $type=(string)($_GET['type'] ?? 'php');

    $map=[
        'php'=>'php-log',
        'nginx'=>'error-log',
        'access'=>'access-log',
        'cloudflare'=>'cloudflare-log'
    ];

    if(!isset($map[$type])){
        output(['error'=>'Invalid log type']);
    }

    $helper='/usr/local/sbin/mucho-admin-ops';
    if (!is_file($helper) || !function_exists('shell_exec')) {
        output(['type'=>$type, 'text'=>'Host log helper unavailable in this deployment. Use sudo mucho logs on the VPS.', 'available'=>false]);
    }
    $text=(string)shell_exec(
        'sudo '.$helper.' '.escapeshellarg($map[$type]).' 2>&1'
    );
    $text=mb_substr($text,0,100000,'UTF-8');

    output([
        'type'=>$type,
        'text'=>$text,
        'time'=>date('H:i:s')
    ]);
}

if($kind==='abuse'){

    $rows=$db->query("
        SELECT
          a.account_id,
          a.username,
          p.stars,
          p.demons,
          p.creator_points,
          p.diamonds
        FROM accounts a
        JOIN profiles p
          ON p.account_id=a.account_id
        WHERE
          p.stars >= 100000
          OR p.demons >= 10000
          OR p.creator_points >= 10000
          OR p.diamonds >= 10000000
        ORDER BY p.stars DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    output([
        'players'=>$rows,
        'count'=>count($rows)
    ]);
}

if($kind==='notifications'){
    // Reading monitoring must never mutate schema or create tables.
    try {
        $rows=$db->query(
            'SELECT id,severity,title,body,created_at
             FROM admin_notifications
             WHERE is_resolved=0
             ORDER BY updated_at DESC
             LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $rows=[];
    }
    output(['notifications'=>$rows]);
}

output(['error'=>'Unknown request']);
