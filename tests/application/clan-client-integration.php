<?php

declare(strict_types=1);

// This test creates and drops only its own randomly named database.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'MuchoCore\\')) {
        require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';
    }
});

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Clan\ClanController;
use MuchoCore\Clan\ClanRepository;
use MuchoCore\Clan\ClanService;
use MuchoCore\Http\Request;

function clanCheck(bool $condition,string $name): void {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}

$host=getenv('TEST_DB_HOST');
$port=getenv('TEST_DB_PORT') ?: '3306';
$password=getenv('TEST_DB_ROOT_PASSWORD');
if ($host!=='127.0.0.1' || !is_string($password) || $password==='') {
    throw new RuntimeException('Requires the isolated local/CI MariaDB, never a production database.');
}
$name='mc_clan_test_'.bin2hex(random_bytes(6));
$connect=static fn(?string $db): PDO => new PDO(
    'mysql:host='.$host.';port='.$port.($db ? ';dbname='.$db : '').';charset=utf8mb4',
    'root',$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$server=$connect(null);
try {
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $pdo=$connect($name);
    $pdo->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, username VARCHAR(24) NOT NULL,
        password_hash VARCHAR(255) NOT NULL DEFAULT \'\', gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1, is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    foreach (require dirname(__DIR__,2).'/database/migrations/002_profiles.php' as $sql) $pdo->exec($sql);
    foreach (['020_clans.php','20260926_002_clans_v2.php'] as $migration) {
        foreach (require dirname(__DIR__,2).'/database/migrations/'.$migration as $sql) $pdo->exec($sql);
    }
    $pdo->exec('CREATE TABLE audit_logs (id INT AUTO_INCREMENT PRIMARY KEY,account_id BIGINT,action VARCHAR(64),target_type VARCHAR(64),target_id BIGINT,metadata TEXT)');
    $token=static fn(int $id): string => sha1('isolated-test-'.$id);
    $insert=$pdo->prepare('INSERT INTO accounts(account_id,username,gjp2_hash) VALUES (?,?,?)');
    $profile=$pdo->prepare('INSERT INTO profiles(user_id,account_id) VALUES (?,?)');
    for ($id=1;$id<=40;$id++) {
        $insert->execute([$id,'Player'.$id,password_hash($token($id),PASSWORD_BCRYPT,['cost'=>4])]);
        $profile->execute([$id,$id]);
    }
    $repository=new ClanRepository($pdo);
    $service=new ClanService($pdo,new AccountAuthenticator($pdo),$repository);
    $controller=new ClanController($service);
    $call=static function(string $method,int $id,array $fields=[]) use ($controller,$token): array {
        $response=$controller->$method(new Request('POST','/api/clans/test',[],
            array_merge(['accountID'=>(string)$id,'gameVersion'=>'22','gjp2'=>$token($id)],$fields),[]));
        $root=json_decode($response->body,true,32,JSON_THROW_ON_ERROR);
        $root['http_status']=$response->status;
        return $root;
    };
    $ok=static function(array $response,string $name): array {
        clanCheck(($response['ok'] ?? false)===true && $response['http_status']===200,$name);
        return $response['data'] ?? [];
    };
    $denied=static function(array $response,string $name): void {
        clanCheck(($response['ok'] ?? true)===false && $response['http_status']===400,$name);
    };
    $create=static fn(int $id,string $name,string $tag,bool $open=true,int $limit=50): array => $call('create',$id,[
        'clanName'=>$name,'clanTag'=>$tag,'clanDescription'=>'Created from an integration test','clanOpen'=>$open ? '1' : '0','clanMaxMembers'=>(string)$limit
    ]);
    clanCheck($call('myClan',1)['data']===null,'unaffiliated account returns null');
    $denied($call('myClan',1,['gjp2'=>str_repeat('a',40)]),'wrong GJP2 rejected');
    $clan=$ok($create(1,'Mucho Players','mcp'),'create clan with game account GJP2');
    $clanId=(string)$clan['clan_id'];
    clanCheck($clan['tag']==='MCP','tag normalized');
    $denied($create(1,'Second Clan','SC'),'one clan per account');
    $denied($create(7,'Mucho Players','OTHER'),'duplicate clan name rejected');
    $denied($create(7,'Invalid Clan','TOOLONG'),'invalid tag rejected');
    $ok($call('join',2,['clanID'=>$clanId]),'join open clan');
    $ok($call('join',3,['clanID'=>$clanId]),'join second member');
    $denied($call('setRole',2,['targetAccountID'=>'3','role'=>'officer']),'member cannot assign roles');
    $ok($call('setRole',1,['targetAccountID'=>'2','role'=>'officer']),'owner promotes officer');
    $denied($call('setRole',1,['targetAccountID'=>'3','role'=>'owner']),'owner role requires ownership transfer');
    $denied($call('kick',2,['targetAccountID'=>'1']),'officer cannot kick owner');
    $denied($call('ban',2,['targetAccountID'=>'1']),'officer cannot ban owner');
    $denied($call('invite',3,['targetAccountID'=>'4']),'member cannot invite');
    $denied($call('sentInvites',3),'member cannot inspect sent invites');
    $denied($call('bans',3),'member cannot inspect ban reasons');
    $denied($call('invite',1,['targetAccountID'=>'999999']),'unknown invite recipient gets a validation error');
    $ok($call('invite',2,['targetAccountID'=>'4']),'officer sends invitation');
    $sent=$ok($call('sentInvites',1),'owner lists sent invitations');
    clanCheck(count($sent['invites'])===1 && $sent['invites'][0]['username']==='Player4','sent list identifies target player');
    $inviteId=(string)$sent['invites'][0]['invite_id'];
    $denied($call('acceptInvite',5,['inviteID'=>$inviteId]),'another account cannot accept invitation');
    $ok($call('acceptInvite',4,['inviteID'=>$inviteId]),'recipient accepts invitation');
    clanCheck(count($call('sentInvites',1)['data']['invites'])===0,'accepted invitation disappears');
    $ok($call('invite',1,['targetAccountID'=>'5']),'owner sends revocable invite');
    $inviteId=(string)$call('invites',5)['data']['invites'][0]['invite_id'];
    $denied($call('revokeInvite',3,['inviteID'=>$inviteId]),'member cannot revoke invite');
    $ok($call('revokeInvite',2,['inviteID'=>$inviteId]),'officer revokes invite');
    $denied($call('acceptInvite',5,['inviteID'=>$inviteId]),'revoked invite cannot be accepted');
    $ok($call('invite',1,['targetAccountID'=>'5']),'send expiring invite');
    $inviteId=(string)$call('invites',5)['data']['invites'][0]['invite_id'];
    $pdo->exec('UPDATE mucho_clan_invites SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)');
    clanCheck(count($call('invites',5)['data']['invites'])===0,'expired invitation omitted');
    $denied($call('acceptInvite',5,['inviteID'=>$inviteId]),'expired invite cannot be accepted');
    $ok($call('ban',2,['targetAccountID'=>'3','reason'=>'Test moderation']),'officer bans member');
    clanCheck($call('myClan',3)['data']===null,'ban removes member');
    $denied($call('join',3,['clanID'=>$clanId]),'banned member cannot rejoin');
    $denied($call('invite',1,['targetAccountID'=>'3']),'banned member cannot be invited');
    clanCheck(count($call('bans',1)['data']['bans'])===1,'owner lists active bans');
    $ok($call('unban',2,['targetAccountID'=>'3']),'officer unbans member');
    $ok($call('join',3,['clanID'=>$clanId]),'unbanned member can rejoin');
    $ok($call('setRole',1,['targetAccountID'=>'3','role'=>'officer']),'promote second officer');
    $denied($call('kick',2,['targetAccountID'=>'3']),'officer cannot kick peer officer');
    $denied($call('ban',2,['targetAccountID'=>'3']),'officer cannot ban peer officer');
    $denied($call('leave',1),'owner cannot leave');
    $blocked=false;
    try { $repository->removeMember((int)$clanId,1); } catch (RuntimeException) { $blocked=true; }
    clanCheck($blocked && $repository->member((int)$clanId,1)['role']==='owner','repository rechecks owner before leaving');
    $settings=['clanID'=>$clanId,'clanName'=>'Mucho Renamed','clanTag'=>'NEW','clanDescription'=>'New description','clanOpen'=>'0','clanMaxMembers'=>'4'];
    $denied($call('updateSettings',2,$settings),'officer cannot change clan settings');
    $ok($call('updateSettings',1,$settings),'owner updates settings to invite only');
    $unicode=$ok($call('updateSettings',1,array_merge($settings,['clanDescription'=>str_repeat('Б',170)])),'Unicode description is safely limited');
    clanCheck(preg_match('/^.{160}$/us',$unicode['description'])===1,'description remains valid UTF-8 at the limit');
    $denied($call('join',5,['clanID'=>$clanId]),'invite-only clan rejects public join');
    $denied($call('updateSettings',1,array_merge($settings,['clanMaxMembers'=>'2'])),'cannot lower capacity below membership');
    $ok($call('leave',4),'member leaves');
    $ok($call('invite',1,['targetAccountID'=>'5']),'invite-only clan still supports invitations');
    $inviteId=(string)$call('invites',5)['data']['invites'][0]['invite_id'];
    $ok($call('acceptInvite',5,['inviteID'=>$inviteId]),'invitation joins closed clan');
    $denied($call('invite',1,['targetAccountID'=>'6']),'full clan rejects more invitations');
    $ok($call('kick',1,['targetAccountID'=>'5']),'owner kicks member');
    $denied($call('transferOwnership',2,['targetAccountID'=>'3']),'officer cannot transfer ownership');
    $ok($call('transferOwnership',1,['targetAccountID'=>'2']),'ownership transfers atomically');
    clanCheck($call('myClan',1)['data']['role']==='officer' && $call('myClan',2)['data']['role']==='owner','old owner demoted and new owner promoted');
    $denied($call('disband',1),'old owner cannot disband');
    for ($id=10;$id<=34;$id++) $ok($create($id,'Search Clan '.$id,'T'.$id),'create search fixture '.$id);
    $first=$ok($call('search',1,['query'=>'Search Clan','offset'=>'0','limit'=>'4']),'first search page');
    $second=$ok($call('search',1,['query'=>'Search Clan','offset'=>'4','limit'=>'4']),'second search page');
    clanCheck(count($first['clans'])===4 && $first['has_more']===true && count($second['clans'])===4,'paged search returns bounded pages');
    clanCheck(!array_intersect(array_column($first['clans'],'clan_id'),array_column($second['clans'],'clan_id')),'deterministic pages have no overlap for equal timestamps');
    $last=$call('search',1,['query'=>'Search Clan','offset'=>'24','limit'=>'4'])['data'];
    clanCheck(count($last['clans'])===1 && $last['has_more']===false,'search covers clans beyond first twenty');
    $pdo->exec('UPDATE profiles SET stars=100,demons=1,diamonds=3000000000 WHERE account_id=1');
    $pdo->exec('UPDATE profiles SET stars=200,demons=2,diamonds=3000000000 WHERE account_id=2');
    $pdo->exec('UPDATE profiles SET stars=50,demons=3 WHERE account_id=3');
    $pdo->exec('UPDATE profiles SET stars=1000 WHERE account_id=10');
    $pdo->exec('UPDATE profiles SET stars=600,demons=200 WHERE account_id=11');
    $pdo->exec('UPDATE profiles SET moons=300 WHERE account_id=12');
    $pdo->exec('UPDATE profiles SET user_coins=100 WHERE account_id=14');
    $pdo->exec('UPDATE profiles SET secret_coins=50 WHERE account_id=15');
    $pdo->exec('UPDATE profiles SET creator_points=100 WHERE account_id=16');
    $pdo->exec('UPDATE profiles SET stars=9000000 WHERE account_id IN (17,18)');
    $pdo->exec('UPDATE accounts SET is_banned=1 WHERE account_id=17');
    $pdo->exec('UPDATE accounts SET is_active=0 WHERE account_id=18');
    $pdo->exec('DELETE FROM profiles WHERE account_id=19');
    $ranking=$ok($call('leaderboard',1,['metric'=>'stars','limit'=>'2']),'clan leaderboard page');
    clanCheck($ranking['total_clans']===26 && $ranking['has_more']===true && count($ranking['clans'])===2,'leaderboard pagination with lookahead');
    clanCheck($ranking['clans'][0]['name']==='Search Clan 10' && $ranking['clans'][1]['name']==='Search Clan 11','clans ordered by summed stars');
    clanCheck($ranking['own_clan']['rank']===3 && $ranking['own_clan']['stars']===350 && $ranking['own_clan']['member_count']===3,'own clan rank beyond current page');
    clanCheck($ranking['own_clan']['diamonds']===6000000000,'clan totals exceed 32-bit without overflow');
    $anonymous=$ok($call('leaderboard',0,['limit'=>'50']),'public anonymous clan leaderboard');
    clanCheck($anonymous['metric']==='stars' && $anonymous['own_clan']===null,'default metric and no fabricated own clan');
    $zeros=[];
    foreach ($anonymous['clans'] as $row) {
        if (in_array($row['name'],['Search Clan 17','Search Clan 18','Search Clan 19'],true))
            clanCheck($row['score']===0 && $row['member_count']===1,'banned inactive or missing profile contributes no score '.$row['name']);
        if ($row['score']===0) $zeros[]=$row['clan_id'];
    }
    $sorted=$zeros; sort($sorted);
    clanCheck($zeros===$sorted,'equal clan totals have deterministic ranks');
    foreach (['demons'=>'Search Clan 11','moons'=>'Search Clan 12','diamonds'=>'Mucho Renamed','user_coins'=>'Search Clan 14','secret_coins'=>'Search Clan 15','creator_points'=>'Search Clan 16'] as $metric=>$winner) {
        $result=$ok($call('leaderboard',0,['metric'=>$metric,'limit'=>'1']),'leaderboard metric '.$metric);
        clanCheck($result['clans'][0]['name']===$winner,'correct metric winner '.$metric);
    }
    $next=$call('leaderboard',1,['metric'=>'stars','offset'=>'2','limit'=>'2'])['data'];
    clanCheck($next['clans'][0]['rank']===3 && !array_intersect(array_column($ranking['clans'],'clan_id'),array_column($next['clans'],'clan_id')),'global ranks continue across pages');
    $outside=$call('leaderboard',0,['offset'=>'1000'])['data'];
    clanCheck($outside['clans']===[] && $outside['total_clans']===26 && !$outside['has_more'],'past-end page retains total clan count');
    $bounded=$call('leaderboard',0,['offset'=>'-1','limit'=>'500'])['data'];
    clanCheck($bounded['offset']===0 && $bounded['limit']===50,'leaderboard bounds enforced');
    $denied($call('leaderboard',0,['metric'=>'stars DESC; DROP TABLE profiles']),'leaderboard rejects unknown metric and SQL input');
    $pdo->exec('UPDATE profiles SET stars=150 WHERE account_id=3');
    clanCheck($call('leaderboard',1)['data']['own_clan']['score']===450,'profile updates appear in clan totals');
    $pdo->exec('UPDATE profiles SET stars=50 WHERE account_id=3');
    $pdo->exec('UPDATE profiles SET stars=123 WHERE account_id=35');
    $ok($call('invite',2,['targetAccountID'=>'35']),'invite ranked contributor');
    $contributorInvite=$call('invites',35)['data']['invites'][0]['invite_id'];
    $ok($call('acceptInvite',35,['inviteID'=>(string)$contributorInvite]),'add ranked contributor');
    clanCheck($call('leaderboard',35)['data']['own_clan']['score']===473,'joining adds current member stats');
    $ok($call('leave',35),'ranked contributor leaves');
    clanCheck($call('leaderboard',1)['data']['own_clan']['score']===350 && $call('leaderboard',35)['data']['own_clan']===null,'leaving removes contribution and own rank');
    $ok($call('disband',2),'new owner disbands');
    clanCheck($call('leaderboard',1)['data']['total_clans']===25 && $call('leaderboard',1)['data']['own_clan']===null,'disband removes clan from leaderboard');
    clanCheck($call('myClan',1)['data']===null && $call('myClan',3)['data']===null,'disband removes all memberships');
    clanCheck((int)$pdo->query('SELECT COUNT(*) FROM mucho_clan_invites WHERE clan_id='.(int)$clanId)->fetchColumn()===0,'disband cascades invitations');
    $pdo->exec('DROP TABLE audit_logs');
    $new=$ok($create(7,'Audit Failure Clan','AFC'),'create clan without audit table');
    $ok($call('updateSettings',7,['clanID'=>(string)$new['clan_id'],'clanName'=>'Audit Safe Clan','clanTag'=>'ASC','clanMaxMembers'=>'50','clanOpen'=>'1']),'audit failure does not turn successful mutation into error');
    $pdo->exec('DELETE FROM mucho_clans');
    $empty=$ok($call('leaderboard',0),'empty clan leaderboard');
    clanCheck($empty['clans']===[] && $empty['total_clans']===0 && !$empty['has_more'] && $empty['own_clan']===null,'empty leaderboard is successful');
    echo "MUCHOCLIENT_CLANS_INTEGRATION_OK\n";
} finally {
    $pdo=null;
    $server->exec('DROP DATABASE IF EXISTS '.$name);
}
