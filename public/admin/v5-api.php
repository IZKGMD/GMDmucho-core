<?php
declare(strict_types=1);

use MuchoCore\Database\Database;

require dirname(__DIR__,2).'/vendor/autoload.php';

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_secure','1');
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

$db->exec("
CREATE TABLE IF NOT EXISTS admin_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 fingerprint VARCHAR(190) NOT NULL UNIQUE,
 severity VARCHAR(20) NOT NULL,
 title VARCHAR(190) NOT NULL,
 body TEXT NULL,
 is_resolved TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

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
    $ctx=stream_context_create([
        'http'=>[
            'method'=>'POST',
            'header'=>
                "Host: muchogdps.space\r\n".
                "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'=>'',
            'timeout'=>4,
            'ignore_errors'=>true
        ]
    ]);

    $body=@file_get_contents(
        'http://127.0.0.1'.$path,
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
        'ok'=>$status===200,
        'body'=>substr(trim((string)$body),0,100)
    ];
}

$kind=(string)($_GET['kind'] ?? 'health');

if($kind==='health'){

    $raw=(string)shell_exec(
        'sudo /usr/local/sbin/mucho-admin-ops status 2>&1'
    );

    $services=[];

    foreach([
        'NGINX'=>'Nginx',
        'PHP'=>'PHP-FPM',
        'CLOUDFLARE'=>'Cloudflare'
    ] as $key=>$name){

        $ok=str_contains(
            strtoupper($raw),
            $key.'=ACTIVE'
        );

        $services[]=[
            'name'=>$name,
            'ok'=>$ok
        ];
    }

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

    foreach($paths as $path){
        $endpoints[]=localEndpoint($path);
    }

    output([
        'services'=>$services,
        'endpoints'=>$endpoints,
        'time'=>date('Y-m-d H:i:s')
    ]);
}

if($kind==='logs'){

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

    $text=(string)shell_exec(
        'sudo /usr/local/sbin/mucho-admin-ops '.
        escapeshellarg($map[$type]).
        ' 2>&1'
    );

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
          p.diamonds,
          p.last_ip,
          p.last_played
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

    $rows=$db->query("
        SELECT *
        FROM admin_notifications
        WHERE is_resolved=0
        ORDER BY updated_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    output(['notifications'=>$rows]);
}

output(['error'=>'Unknown request']);
