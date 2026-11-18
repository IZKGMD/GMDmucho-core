<?php
/**
 * Native 2.209 level scores: read-only requests omit percent.
 * Run on modern PHP8 or the compiled PHP7 runtime, with a disposable
 * 127.0.0.1 MariaDB. This file uses PHP7-compatible syntax throughout.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
if(getenv('MUCHO_SCORE_TEST_COMPILED')==='1'){
    require_once $root.'/compat/php7/domain.php';
}else{
    require_once $root.'/vendor/autoload.php';
}
$host=getenv('TEST_DB_HOST');
$password=getenv('TEST_DB_ROOT_PASSWORD');
$port=getenv('TEST_DB_PORT')?:'3306';
if($host!=='127.0.0.1'||!is_string($password)||$password===''||!ctype_digit((string)$port)){
    throw new RuntimeException('Score read-only test requires isolated MariaDB');
}
function scoreReadCheck($ok,$reason){
    if(!$ok)throw new RuntimeException('GD 2.209 score read-only: '.$reason);
}
$connect=function($name)use($host,$password,$port){
    return new PDO('mysql:host='.$host.';port='.$port.
        ($name?';dbname='.$name:'').';charset=utf8mb4','root',$password,
        array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
              PDO::ATTR_EMULATE_PREPARES=>false,
              PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
};
$dbName='mc_score_read_'.bin2hex(random_bytes(5));
$admin=$connect(null);
try{
    $admin->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4');
    $db=$connect($dbName);
    $db->exec('CREATE TABLE accounts(
        account_id INT PRIMARY KEY,username VARCHAR(40),
        password_hash VARCHAR(255),gjp2_hash VARCHAR(255),
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles(
        user_id INT PRIMARY KEY,account_id INT UNIQUE,
        cube INT,color1 INT,color2 INT,color3 INT,
        icon_type INT,special INT
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE levels(level_id INT PRIMARY KEY,is_deleted TINYINT,length INT NOT NULL DEFAULT 5)');
    $db->exec('INSERT INTO levels VALUES(77,0,5)');
    $db->exec('CREATE TABLE friends(account_id INT,friend_account_id INT)');
    $db->exec('INSERT INTO friends VALUES(1,2)');
    $db->exec('CREATE TABLE mucho_level_scores(
        score_id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT,level_id INT,is_daily TINYINT,daily_id INT,
        percent INT,coins INT,attempts INT,clicks INT,play_time INT,
        progresses TEXT,created_at INT,updated_at INT,
        UNIQUE KEY uq_score(account_id,level_id,is_daily)
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_score_integrity_events(
        score_type VARCHAR(32),score_id INT,
        account_id INT,level_id INT,risk_score INT,
        status VARCHAR(32),reasons TEXT,metrics TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(score_type,score_id)
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_platformer_scores (
        score_id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        level_id INT NOT NULL,
        time_ms INT NOT NULL,
        points INT NOT NULL,
        created_at INT NOT NULL,
        updated_at INT NOT NULL,
        UNIQUE KEY uq_platformer_account_level(account_id,level_id)
    ) ENGINE=InnoDB');
    foreach(array(1=>'Alice',2=>'Bob',3=>'Cara',4=>'Zero') as $id=>$name){
        $s=$db->prepare('INSERT INTO accounts(
            account_id,username,password_hash,gjp2_hash
        ) VALUES(:id,:name,:password,:gjp)');
        $s->execute(array('id'=>$id,'name'=>$name,
            'password'=>password_hash('pw'.$id,PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp'.$id,PASSWORD_DEFAULT)));
        $db->prepare('INSERT INTO profiles VALUES(?,?,?,?,?,?,?,?)')->execute(
            array(1000+$id,$id,5,12,13,14,2,1)
        );
    }
    $now=time();
    $insert=$db->prepare('INSERT INTO mucho_level_scores (
        account_id,level_id,is_daily,daily_id,percent,coins,attempts,clicks,
        play_time,progresses,created_at,updated_at
    ) VALUES (?,?,0,0,?,?,2,4000,90,?, ?, ?)');
    foreach(array(1=>75,2=>90,3=>85) as $id=>$percent){
        $insert->execute(array($id,77,$percent,1,'', $now-20,$now-$id));
    }

    $_ENV['MUCHO_ANTICHEAT_QUARANTINE']='0';
    $controller=new \MuchoCore\Score\LevelScoreController(
        $db,new \MuchoCore\Account\AccountAuthenticator($db)
    );
    $req=function($actor,$extra=array()) {
        return new \MuchoCore\Http\Request('POST',
            '/database/getGJLevelScores211.php',array(),
            array_merge(array(
                'accountID'=>(string)$actor,
                'gjp2'=>'pw'.$actor,
                'levelID'=>'77',
                'gameVersion'=>'22',
                'binaryVersion'=>'51',
            ),$extra),array());
    };
    $before=(int)$db->query('SELECT COUNT(*) FROM mucho_level_scores')->fetchColumn();
    $last=(int)$db->query('SELECT updated_at FROM mucho_level_scores WHERE account_id=1')->fetchColumn();
    $read=$controller->regular($req(1))->body;
    scoreReadCheck(strpos($read,'1:Bob:')!==false&&strpos($read,'1:Alice:')!==false,
        'no-percent query returns existing friends leaderboard');
    scoreReadCheck(strpos($read,'1:Cara:')===false,
        'omitted type selects friends, not global leaderboard');
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_level_scores')->fetchColumn()===$before,
        'reading scores never inserts a fake 0 percent');
    scoreReadCheck((int)$db->query('SELECT updated_at FROM mucho_level_scores WHERE account_id=1')->fetchColumn()===$last,
        'read-only access leaves timestamps untouched');
    $empty=$controller->regular($req(4))->body;
    scoreReadCheck($empty==='',
        'player without stored score receives empty friends leaderboard');
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_level_scores WHERE account_id=4')->fetchColumn()===0,
        'no auto-submission for new account');

    foreach(array('', 'x', '-1', '101', '5.5') as $invalid){
        scoreReadCheck($controller->regular($req(4,array('percent'=>$invalid)))->body==='-1',
            'malformed explicit percent must fail closed: '.$invalid);
    }
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_level_scores WHERE account_id=4')->fetchColumn()===0,
        'invalid data never writes a score');
    scoreReadCheck($controller->regular($req(4,array('gjp2'=>'wrong')))->body==='-1',
        'read-only scoreboard still requires valid authentication');

    // Distinguish omitted optional parameters from malformed explicit values.
    foreach(array(
        array('type'=>'not-a-number'),
        array('type'=>'-1'),
        array('s10'=>'not-a-daily'),
        array('s10'=>'-10'),
    ) as $malformed){
        scoreReadCheck($controller->regular($req(4,$malformed))->body==='-1',
            'malformed leaderboard selector/timely ID rejected');
    }
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_level_scores WHERE account_id=4')->fetchColumn()===0,
        'malformed selector/timely ID never creates a score');

    $posted=$controller->regular($req(4,array('type'=>'1','percent'=>'0')))->body;
    scoreReadCheck($posted!=='' && $posted!=='-1',
        'explicit percent zero still submits and returns leaderboard');
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_level_scores WHERE account_id=4 AND percent=0')->fetchColumn()===1,
        'explicit zero persisted exactly once');
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_level_scores')->fetchColumn()===$before+1,
        'exactly one new score created');
    // GD 2.209 platformer leaderboard uses the same client credentials.
    // Bad numeric identities and selectors MUST NOT be cast to valid values.
    $plat = new \\MuchoCore\\Score\\PlatformerScoreController(
        $db,new \\MuchoCore\\Account\\AccountAuthenticator($db)
    );
    $platReq=function($extra=array()) {
        return new \\MuchoCore\\Http\\Request('POST',
            '/database/getGJLevelScoresPlat.php',array(),
            array_merge(array(
                'accountID'=>'1',
                'gjp2'=>'pw1',
                'levelID'=>'77',
                'gameVersion'=>'22',
                'binaryVersion'=>'51',
            ),$extra),array());
    };
    $initial=$plat->handle($platReq(array('type'=>'1')))->body;
    scoreReadCheck($initial==='', 'platformer read-only fetch with no scores');
    foreach(array(
        array('accountID'=>'1junk','time'=>'300','points'=>'5'),
        array('levelID'=>'77junk','time'=>'300','points'=>'5'),
        array('mode'=>'not-a-number','time'=>'300','points'=>'5'),
        array('type'=>'not-a-number','time'=>'300','points'=>'5'),
        array('time'=>'not-a-number','points'=>'5'),
        array('time'=>'-5','points'=>'5'),
        array('time'=>'300','points'=>'not-a-number'),
        array('time'=>'300','points'=>'-5'),
    ) as $malformed){
        scoreReadCheck($plat->handle($platReq($malformed))->body==='-1',
            'malformed platformer numeric request rejected');
    }
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_platformer_scores')->fetchColumn()===0,
        'invalid platformer data never writes a score');

    $created=$plat->handle($platReq(array('type'=>'1','time'=>'300','points'=>'5')))->body;
    scoreReadCheck(strpos($created,'1:Alice:')!==false && strpos($created,':3:300:')!==false,
        'valid platformer score is stored and serialized');
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_platformer_scores')->fetchColumn()===1,
        'valid platformer score creates exactly one entry');
    $improved=$plat->handle($platReq(array('type'=>'1','time'=>'200','points'=>'8')))->body;
    scoreReadCheck(strpos($improved,':3:200:')!==false,
        'faster platformer completion atomically updates best time');

    // Compare the actual native date field under a deliberately non-UTC TZ.
    $db->exec('UPDATE mucho_platformer_scores SET updated_at=1700000000 WHERE account_id=1');
    $oldTZ=date_default_timezone_get();
    date_default_timezone_set('Pacific/Honolulu');
    try{
        $unchanged=$plat->handle($platReq(array('type'=>'1')))->body;
        scoreReadCheck(strpos($unchanged,':42:14/11/2023 22.13')!==false,
            'platformer score timestamps always use UTC');
    }finally{
        date_default_timezone_set($oldTZ);
    }
    scoreReadCheck((int)$db->query('SELECT COUNT(*) FROM mucho_platformer_scores')->fetchColumn()===1,
        'platformer fetch never creates a second score');

    echo "MUCHOCORE_GD2209_SCORE_READONLY_MYSQL_OK\\n";
}finally{
    $admin->exec('DROP DATABASE IF EXISTS '.$dbName);
}
