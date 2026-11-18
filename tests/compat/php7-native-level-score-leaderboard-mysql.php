<?php
/**
 * The real compiled PHP7 score leaderboard on disposable MariaDB.
 * Exercises GD 2.209 wire, friends, bans, absent profiles and quarantine.
 * No PHP8-only constructs so it can run on 7.0 and 8.3 in CI.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/compat/php7/domain.php';

$host=getenv('TEST_DB_HOST');
$password=getenv('TEST_DB_ROOT_PASSWORD');
$port=getenv('TEST_DB_PORT')?:'3306';
if($host!=='127.0.0.1'||!is_string($password)||$password===''||!ctype_digit((string)$port)){
    throw new RuntimeException('Score tests require isolated CI MariaDB at 127.0.0.1');
}
function scoreCheck7($ok,$message){
    if(!$ok)throw new RuntimeException('Compiled PHP7 GD score: '.$message);
}
$connect=function($name)use($host,$password,$port){
    $dsn='mysql:host='.$host.';port='.$port.($name?';dbname='.$name:'').';charset=utf8mb4';
    return new PDO($dsn,'root',$password,array(
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ));
};
$dbName='mc_native_scores_'.bin2hex(random_bytes(5));
$admin=$connect(null);
try{
    $admin->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4');
    $db=$connect($dbName);
    $db->exec('CREATE TABLE accounts(
        account_id INT PRIMARY KEY,username VARCHAR(40) NOT NULL,
        is_active TINYINT NOT NULL,is_banned TINYINT NOT NULL
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles(
        user_id INT PRIMARY KEY, account_id INT NOT NULL UNIQUE,
        cube INT,color1 INT,color2 INT,color3 INT,
        icon_type INT,special INT
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_level_scores(
        score_id INT PRIMARY KEY,account_id INT NOT NULL,
        level_id INT NOT NULL,is_daily TINYINT NOT NULL,
        percent INT NOT NULL,coins INT NOT NULL,updated_at INT NOT NULL
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_score_integrity_events(
        score_type VARCHAR(32),score_id INT,status VARCHAR(32),risk_score INT
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE friends(account_id INT,friend_account_id INT) ENGINE=InnoDB');
    $db->exec('INSERT INTO friends VALUES(1,2)');
    $now=time();
    $accounts=$db->prepare('INSERT INTO accounts VALUES(?,?,?,?)');
    $profiles=$db->prepare('INSERT INTO profiles VALUES(?,?,?,?,?,?,?,?)');
    $scores=$db->prepare('INSERT INTO mucho_level_scores VALUES(?,?,?,?,?,?,?)');
    foreach(array(
        1=>array('Alice',1,0,75),
        2=>array('Bob',1,0,90),
        3=>array('Cara',1,0,80),
        4=>array('Banned',1,1,100),
        5=>array('Disabled',0,0,100),
        6=>array('NoProfile',1,0,70),
    ) as $id=>$entry){
        $accounts->execute(array($id,$entry[0],$entry[1],$entry[2]));
        if($id!==6)$profiles->execute(array(1000+$id,$id,5,12,13,14,2,1));
        $scores->execute(array($id,$id,77,0,$entry[3],1,$now-$id));
    }
    $_ENV['MUCHO_ANTICHEAT_QUARANTINE']='0';
    $controller=new \MuchoCore\Score\LevelScoreController(
        $db,new \MuchoCore\Account\AccountAuthenticator($db)
    );
    $method=new ReflectionMethod('MuchoCore\\Score\\LevelScoreController','leaderboard');
    $method->setAccessible(true);
    $global=$method->invoke($controller,1,77,0,1);
    scoreCheck7(strpos($global,'1:Bob:2:1002:9:5:10:12:11:13:51:14:14:2:15:1:16:2:3:90:6:2:13:1:42:')===0,
        'native wire and public icon fields');
    scoreCheck7(strpos($global,':16:4:')===false&&strpos($global,':16:5:')===false,
        'blocked accounts excluded before ranking');
    scoreCheck7(strpos($global,'1:NoProfile:2:6:9:1:10:0:11:3:51:0:14:0:15:0:16:6:')!==false,
        'missing profile uses safe defaults');
    scoreCheck7((int)$db->query('SELECT COUNT(*) FROM profiles WHERE account_id=6')->fetchColumn()===0,
        'leaderboard should not create a profile on read');

    $friends=$method->invoke($controller,1,77,0,0);
    scoreCheck7(strpos($friends,':16:1:')!==false&&strpos($friends,':16:2:')!==false,
        'friend leaderboard contains player and actual friend');
    scoreCheck7(strpos($friends,':16:3:')===false&&strpos($friends,':16:6:')===false,
        'friends list never leaks unrelated players');

    $db->exec("INSERT INTO mucho_score_integrity_events VALUES('regular',2,'suspicious',95)");
    $_ENV['MUCHO_ANTICHEAT_QUARANTINE']='1';
    $filtered=$method->invoke($controller,1,77,0,1);
    scoreCheck7(strpos($filtered,':16:2:')===false,
        'score quarantine excludes flagged player');
    $_ENV['MUCHO_ANTICHEAT_QUARANTINE']='0';
    $db->exec('UPDATE mucho_level_scores SET updated_at='.($now-700000).' WHERE score_id=3');
    $recent=$method->invoke($controller,1,77,0,2);
    scoreCheck7(strpos($recent,':16:3:')===false,
        'weekly recent filter excludes older records');

    echo "MUCHOCORE_PHP7_LEVEL_SCORE_LEADERBOARD_MYSQL_OK\n";
}finally{
    $admin->exec('DROP DATABASE IF EXISTS '.$dbName);
}
