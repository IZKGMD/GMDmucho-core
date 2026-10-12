<?php

declare(strict_types=1);

// Isolated MariaDB only. Never connects to or migrates a production database.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'MuchoCore\\')) {
        require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';
    }
});

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Http\Request;
use MuchoCore\Quest\DailyQuestController;
use MuchoCore\Quest\DailyQuestPolicy;
use MuchoCore\Quest\DailyQuestRepository;
use MuchoCore\User\UserRepository;
use MuchoCore\User\UserService;
use MuchoCore\Protocol\GdUserEncoder;

$host=getenv('TEST_DB_HOST');
$port=getenv('TEST_DB_PORT') ?: '3306';
$password=getenv('TEST_DB_ROOT_PASSWORD');
if ($host!=='127.0.0.1' || !is_string($password) || $password==='') {
    throw new RuntimeException('Requires isolated local/CI MariaDB.');
}
$connect=static fn(?string $db): PDO => new PDO(
    'mysql:host='.$host.';port='.$port.($db ? ';dbname='.$db : '').';charset=utf8mb4','root',$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$token=static fn(int $id): string => sha1('daily-test-'.$id);
$makeScore=static fn(PDO $pdo,DailyQuestRepository $repo): UserService => new UserService(
    $pdo,new AccountAuthenticator($pdo),new AccountRepository($pdo),new UserRepository($pdo),new GdUserEncoder(),null,null,$repo
);
if (in_array($argv[1] ?? '',['--claim','--score'],true)) {
    $database=$argv[2] ?? '';
    if (preg_match('/^mc_daily_test_[a-f0-9]{12}$/D',$database)!==1) throw new RuntimeException('Invalid isolated worker database.');
    $pdo=$connect($database); $account=(int)($argv[3] ?? 0); $epoch=(int)($argv[4] ?? 0);
    $repo=new DailyQuestRepository($pdo,static fn(): int => $epoch);
    if ($argv[1]==='--claim') {
        $controller=new DailyQuestController($pdo,new AccountAuthenticator($pdo),$repo);
        $response=$controller->claim(new Request('POST','/extensions/daily-quests/claim',[],[
            'accountID'=>(string)$account,'gameVersion'=>'22','gjp2'=>$token($account),
            'day'=>DailyQuestPolicy::calendar($epoch)['day'],'questID'=>'stars',
        ],[]));
        $body=json_decode($response->body,true,32,JSON_THROW_ON_ERROR);
        if (!($body['ok'] ?? false)) throw new RuntimeException('Concurrent claim failed.');
    } else {
        $makeScore($pdo,$repo)->updateScore($account,$token($account),['gameVersion'=>'22','stars'=>'2','moons'=>'0','demons'=>'0']);
    }
    echo 'DAILY_WORKER_OK';exit(0);
}

$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL Daily Quests: '.$label);
    ++$checks;echo 'PASS Daily Quests: '.$label.PHP_EOL;
};
$database='mc_daily_test_'.bin2hex(random_bytes(6));
$server=$connect(null);
try {
    $server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');
    $pdo=$connect($database);
    $pdo->exec("CREATE TABLE accounts(account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(24) NOT NULL,password_hash VARCHAR(255) NOT NULL DEFAULT '',gjp2_hash VARCHAR(255) NOT NULL,is_active TINYINT NOT NULL DEFAULT 1,is_banned TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    foreach (['002_profiles.php','20261012_001_daily_quests.php'] as $migration) {
        foreach (require dirname(__DIR__,2).'/database/migrations/'.$migration as $sql) $pdo->exec($sql);
    }
    $account=$pdo->prepare('INSERT INTO accounts(account_id,username,gjp2_hash) VALUES (?,?,?)');
    $profile=$pdo->prepare('INSERT INTO profiles(user_id,account_id,stars,moons,demons) VALUES (?,?,?,?,?)');
    for ($id=1;$id<=6;++$id) {
        $account->execute([$id,'DailyPlayer'.$id,password_hash($token($id),PASSWORD_BCRYPT,['cost'=>4])]);
        $profile->execute([$id,$id,$id===1 ? 100 : 0,0,0]);
    }
    $epoch=(new DateTimeImmutable(gmdate('Y-m-d').' 12:00:00',new DateTimeZone('UTC')))->getTimestamp();
    $repo=new DailyQuestRepository($pdo,static function() use (&$epoch): int { return $epoch; });
    $controller=new DailyQuestController($pdo,new AccountAuthenticator($pdo),$repo);
    $score=$makeScore($pdo,$repo);
    $call=static function(string $method,int $id,array $extra=[],string $verb='POST') use ($controller,$token): array {
        $response=$controller->$method(new Request($verb,'/extensions/daily-quests',[],array_replace([
            'accountID'=>(string)$id,'gameVersion'=>'22','gjp2'=>$token($id),
        ],$extra),[]));
        $root=json_decode($response->body,true,32,JSON_THROW_ON_ERROR); $root['http_status']=$response->status; return $root;
    };
    $ok=static function(array $response,string $label) use ($check): array {
        $check(($response['ok'] ?? false)===true && $response['http_status']===200,$label); return $response['data'];
    };
    $quests=static function(array $data): array { return array_column($data['quests'],null,'id'); };
    $sync=static function(int $id,int $stars,int $moons=0,int $demons=0) use ($score,$token,$check): void {
        $check($score->updateScore($id,$token($id),['gameVersion'=>'22','stars'=>(string)$stars,'moons'=>(string)$moons,'demons'=>(string)$demons])===(string)$id,'accepted score keeps legacy user-id response');
    };
    $initial=$ok($call('daily',1),'authenticated daily snapshot'); $day=$initial['day']; $goals=$quests($initial);
    $check(count($goals)===3 && $initial['xp']===0 && array_sum(array_column($initial['quests'],'progress'))===0,'historical scores are baselines, not new progress');
    $check($initial['account_id']===1 && $initial['schema_version']===1,'versioned account-specific response');
    $check($call('daily',1,[],'GET')['http_status']===405,'GET is rejected');
    $check($call('daily',1,['gjp2'=>'bad'])['http_status']===401,'invalid credential is rejected');
    $check($call('daily',2,['gjp2'=>$token(1)])['http_status']===401,'other account cannot use first account credential');
    $early=$call('claim',1,['day'=>$day,'questID'=>'stars','completed'=>'1','progress'=>'999999']);
    $check(!($early['ok'] ?? false),'client completion flags do not award rewards');
    $fake=$ok($call('daily',1,['stars'=>'999999','progress'=>'999999','xp'=>'999999']),'extra untrusted fields ignored');
    $check($fake['xp']===0 && $quests($fake)['stars']['progress']===0,'daily endpoint cannot mutate score or XP from client fields');

    $target=$goals['stars']['target'];$sync(1,100+$target);
    $ready=$ok($call('daily',1),'quest ready after accepted score sync');
    $check($quests($ready)['stars']['claimable']===true,'only complete quest becomes claimable');
    $first=$ok($call('claim',1,['day'=>$day,'questID'=>'stars']),'claim completed daily quest');
    $check($first['xp']===25 && $first['claimed_now']===true && $first['claims_count']===1,'XP and claimed state committed together');
    $repeat=$ok($call('claim',1,['day'=>$day,'questID'=>'stars']),'claim retry succeeds idempotently');
    $check($repeat['xp']===25 && $repeat['claimed_now']===false && $repeat['claims_count']===1,'retry cannot double-award');
    $sync(1,100+$target);$sync(1,0);$sync(1,100+$target);
    $replay=$ok($call('daily',1),'repeated and reset score syncs');
    $check($replay['xp']===25 && $quests($replay)['stars']['progress']===$target,'score replay cannot multiply rewards');
    $sync(1,100+$target,$goals['moons']['target'],1);
    $ok($call('claim',1,['day'=>$day,'questID'=>'moons']),'claim moon quest');
    $all=$ok($call('claim',1,['day'=>$day,'questID'=>'demons']),'claim demon quest');
    $check($all['xp']===140 && $all['claims_count']===3,'all daily rewards total exactly 140 Quest XP');
    $other=$ok($call('daily',2),'separate account snapshot');
    $check($other['xp']===0,'wallets are isolated by authenticated account');
    $check(!($call('claim',2,['day'=>$day,'questID'=>'stars'])['ok'] ?? false),'another account cannot claim completed first-account quest');

    $sync(1,0,0,0);$epoch+=86400;
    $next=$ok($call('daily',1),'new UTC day generates fresh tasks');
    $check($next['day']!==$day && $next['xp']===140 && array_sum(array_column($next['quests'],'progress'))===0,'day rollover keeps XP and resets only daily progress');
    $check(!($call('claim',1,['day'=>$day,'questID'=>'stars'])['ok'] ?? false),'expired day cannot be claimed');
    $sync(1,100+$target,$goals['moons']['target'],1);
    $next=$ok($call('daily',1),'previous high score replay on next day');
    $check(array_sum(array_column($next['quests'],'progress'))===0,'lifetime high-water marks stop reset replay across days');

    $race=$ok($call('daily',3),'prepare concurrent claim account');
    $sync(3,$quests($race)['stars']['target']);
    $runWorkers=static function(string $mode,int $id) use ($database,&$epoch,$check): void {
        $workers=[];
        for ($i=0;$i<2;$i++) {
            $process=proc_open([PHP_BINARY,__FILE__,$mode,$database,(string)$id,(string)$epoch],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not launch isolated race worker.');
            $workers[]=[$process,$pipes];
        }
        foreach ($workers as [$process,$pipes]) {
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            $check(proc_close($process)===0 && str_contains($out,'DAILY_WORKER_OK'),'independent concurrent worker succeeds: '.$err);
        }
    };
    $runWorkers('--claim',3);
    $race=$ok($call('daily',3),'concurrent claim result');
    $check($race['xp']===25 && $race['claims_count']===1,'overlapping claims award once');
    $ok($call('daily',4),'prepare duplicate score account');$runWorkers('--score',4);
    $race=$ok($call('daily',4),'duplicate overlapping score result');
    $check($quests($race)['stars']['progress']===2,'duplicate score syncs count their shared gain once');

    $rollback=$ok($call('daily',5),'prepare reward rollback account');$sync(5,$quests($rollback)['stars']['target']);
    $pdo->exec("CREATE TRIGGER fail_daily_wallet BEFORE UPDATE ON mucho_quest_accounts FOR EACH ROW BEGIN IF NEW.xp>OLD.xp THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test reward failure'; END IF; END");
    $response=$call('claim',5,['day'=>$rollback['day'],'questID'=>'stars']);
    $check($response['http_status']===503,'reward storage error is reported without leaking SQL');
    $pdo->exec('DROP TRIGGER fail_daily_wallet');
    $rollback=$ok($call('daily',5),'snapshot after failed reward');
    $check($rollback['xp']===0 && $quests($rollback)['stars']['claimed']===false,'failed reward rolls back claimed marker and wallet');
    $retry=$ok($call('claim',5,['day'=>$rollback['day'],'questID'=>'stars']),'retry after rolled-back reward');
    $check($retry['xp']===25 && $retry['claims_count']===1,'reward remains collectible after rollback');

    $pdo->exec("SET time_zone='+07:00'");$real=new DailyQuestRepository($pdo);
    $pdo->beginTransaction();$utc=$real->prepare(6);$pdo->rollBack();
    $check(abs($utc['server_time']-time())<10 && $utc['day']===gmdate('Y-m-d'),'UTC calendar ignores SQL session time zone');
    $pdo->exec('UPDATE accounts SET is_banned=1 WHERE account_id=6');
    $check($call('daily',6)['http_status']===401,'banned account denied');
    $check(DailyQuestPolicy::validDay('2024-02-29') && !DailyQuestPolicy::validDay('2026-02-29'),'policy validates leap days');

    $router=new \MuchoCore\Routing\Router();
    $manager=new \MuchoCore\Plugin\PluginManager($pdo,$router,$database . '-no-custom','1.1.0',dirname(__DIR__,2).'/plugins/builtin');
    $manager->load();
    $check(isset($manager->loaded()['mucho-daily-quests']),'bundled Daily Quests loads through the plugin SDK');
    $metadata=array_column($manager->clientFeatures()->all(),null,'id');
    $check(($metadata['daily-quests']['min_client_version'] ?? null)==='0.9.0','plugin discovery declares required client UI version');
    $response=$router->dispatch(new Request('POST','/extensions/daily-quests',[],['accountID'=>'2','gjp2'=>$token(2),'gameVersion'=>'22'],[]));
    $check((json_decode($response->body,true,32,JSON_THROW_ON_ERROR)['ok'] ?? false)===true,'bundled plugin route reaches authenticated Daily Quest API');
    echo 'MUCHO_DAILY_QUEST_INTEGRATION_OK checks='.$checks.PHP_EOL;
} finally {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $server->exec('DROP DATABASE IF EXISTS '.$database);
}
