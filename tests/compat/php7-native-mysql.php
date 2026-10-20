<?php
/** Disposable real MariaDB native PHP7 schema/auth/search/download test. */
declare(strict_types=1);
require dirname(__DIR__,2) . '/compat/php7/runtime.php';
require dirname(__DIR__,2) . '/compat/php7/schema.php';
require dirname(__DIR__,2) . '/compat/php7/auth.php';
require dirname(__DIR__,2) . '/compat/php7/download.php';

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$rootPassword = getenv('TEST_DB_ROOT_PASSWORD');
if ($rootPassword === false) {
    throw new RuntimeException('TEST_DB_ROOT_PASSWORD required');
}
$db = 'mucho_php7_native_ci';
$pdo = new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',
    'root',$rootPassword, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
$pdo->exec('DROP DATABASE IF EXISTS mucho_php7_native_ci');
$pdo->exec('CREATE DATABASE mucho_php7_native_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $test = new PDO('mysql:host='.$host.';port='.$port.';dbname='.$db.';charset=utf8mb4',
        'root',$rootPassword, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
    mucho7_create_schema($test);
    try {
        mucho7_create_schema($test);
        throw new RuntimeException('Second install must refuse existing database tables.');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(),'Expected an empty test database') === false) {
            throw $expected;
        }
    }
    $pepper = str_repeat('x',64);
    $opts = [
        'enable_accounts'=>true, 'enable_registration'=>true,
        'ip'=>'127.0.0.1','auth_pepper'=>$pepper
    ];
    $registration = mucho7_dispatch($test, '/accounts/registerGJAccount20.php',
        'POST',[
            'userName'=>'NativeP7','password'=>'LongSecret_1234',
            'email'=>'nativep7@example.invalid'
        ],$opts);
    if ($registration !== '1') {
        throw new RuntimeException('Registration failed: '.$registration);
    }
    $login = mucho7_dispatch($test,'/accounts/loginGJAccount20.php','POST',[
        'userName'=>'NativeP7','password'=>'LongSecret_1234'
    ], $opts);
    if ($login !== '1,1') {
        throw new RuntimeException('Login failed: '.$login);
    }
    $insert = $test->prepare(
        "INSERT INTO levels
         (account_id,name,description,level_data,game_version,level_version,
          difficulty,likes,stars,song_id)
         VALUES (1,'Native Level','Hello','kS1,1,2;',22,1,3,12,4,0)"
    );
    $insert->execute();
    $list = mucho7_dispatch($test,'/database/getGJLevels21.php','POST',[
        'gameVersion'=>'22','binaryVersion'=>'51',
        'type'=>'0','page'=>'0','str'=>'Native'
    ], $opts);
    if (strpos($list,'Native Level') === false || strpos($list,':62:') === false) {
        throw new RuntimeException('Search did not return 2.209-native level wire.');
    }
    $download = mucho7_dispatch($test,'/database/downloadGJLevel22.php','POST',[
        'gameVersion'=>'22','binaryVersion'=>'51','levelID'=>'1'
    ], $opts);
    if (strpos($download,':62:') === false || substr_count($download,'#') !== 2) {
        throw new RuntimeException('Download did not return 2.209-native level wire.');
    }
    echo "PHP7_NATIVE_REAL_MARIADB_SCHEMA_AUTH_LEVELS_OK\n";
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS mucho_php7_native_ci');
}
