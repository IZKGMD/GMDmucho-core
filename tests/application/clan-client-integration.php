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
use MuchoCore\Appearance\AppearanceController;
use MuchoCore\Appearance\AppearanceService;
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
// Worker processes use independent connections to test overlapping score syncs.
if (($argv[1] ?? '')==='--quest-score') {
    $db=$argv[2] ?? '';
    if (!preg_match('/^mc_clan_test_[a-f0-9]{12}$/D',$db)) throw new RuntimeException('Invalid isolated worker database.');
    $workerPdo=$connect($db);
    $id=(int)($argv[3] ?? 0);
    $worker=new \MuchoCore\User\UserService($workerPdo,new AccountAuthenticator($workerPdo),
        new \MuchoCore\Account\AccountRepository($workerPdo),new \MuchoCore\User\UserRepository($workerPdo),
        new \MuchoCore\Protocol\GdUserEncoder(),null,new \MuchoCore\Clan\ClanQuestRepository($workerPdo));
    $worker->updateScore($id,sha1('isolated-test-'.$id),['gameVersion'=>'22','stars'=>$argv[4] ?? '0','demons'=>$argv[5] ?? '0']);
    echo 'QUEST_SCORE_WORKER_OK';
    exit(0);
}
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
    foreach (['020_clans.php','20260926_002_clans_v2.php','20261010_001_appearance.php','20261010_002_clan_quests.php'] as $migration) {
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
    $appearance=new AppearanceController(new AppearanceService($pdo,new AccountAuthenticator($pdo)));
    $questRepository=new \MuchoCore\Clan\ClanQuestRepository($pdo);
    $questController=new \MuchoCore\Clan\ClanQuestController($pdo,new AccountAuthenticator($pdo),$questRepository);
    $quests=static function(string $method,int $id,array $fields=[],string $verb='POST') use ($questController,$token): array {
        $response=$questController->$method(new Request($verb,'/api/clans/quests',[],
            array_merge(['accountID'=>(string)$id,'gameVersion'=>'22','gjp2'=>$token($id)],$fields),[]));
        $root=json_decode($response->body,true,32,JSON_THROW_ON_ERROR);
        $root['http_status']=$response->status;
        return $root;
    };
    $scoreService=new \MuchoCore\User\UserService($pdo,new AccountAuthenticator($pdo),
        new \MuchoCore\Account\AccountRepository($pdo),new \MuchoCore\User\UserRepository($pdo),
        new \MuchoCore\Protocol\GdUserEncoder(),null,$questRepository);
    $sync=static function(int $id,int $stars,int $demons) use ($scoreService,$token): void {
        clanCheck($scoreService->updateScore($id,$token($id),['gameVersion'=>'22','stars'=>(string)$stars,'demons'=>(string)$demons])===(string)$id,
            'authenticated score sync returns legacy user ID');
    };
    $colorCall=static function(string $method,int $id,array $fields=[],string $verb='POST') use ($appearance,$token): array {
        $response=$appearance->$method(new Request($verb,'/api/appearance/test',[],
            array_merge(['accountID'=>(string)$id,'gameVersion'=>'22','gjp2'=>$token($id)],$fields),[]));
        $root=json_decode($response->body,true,32,JSON_THROW_ON_ERROR);
        $root['http_status']=$response->status;
        return $root;
    };
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
    $default=$ok($colorCall('mine',1),'load default player appearance');
    clanCheck($default['nickname_color']==='' && $default['prefix_color']==='FFD166' && $default['can_edit_prefix'],'default colors and owner permission');
    $saved=$ok($colorCall('save',1,['nicknameColor'=>'#66ccff','prefixColor'=>'#bd93f9']),'owner saves personal and clan colors');
    clanCheck($saved['nickname_color']==='66CCFF' && $saved['prefix_color']==='BD93F9','HEX normalized to uppercase');
    $memberColor=$ok($colorCall('save',2,['nicknameColor'=>'FF6B6B']),'officer changes only their nickname');
    clanCheck($memberColor['prefix_color']==='BD93F9' && !$memberColor['can_edit_prefix'],'clan prefix shared with every member');
    $denied($colorCall('save',2,['nicknameColor'=>'000000','prefixColor'=>'FF0000']),'officer cannot edit clan prefix');
    clanCheck($colorCall('mine',2)['data']['nickname_color']==='FF6B6B','unauthorized combined save does not partly change nickname');
    $denied($colorCall('save',1,['nicknameColor'=>'<cR>']),'nickname rejects markup');
    $denied($colorCall('save',1,['nicknameColor'=>'FFFFFF','prefixColor'=>'red']),'prefix rejects non-HEX');
    $denied($colorCall('save',1,['nicknameColor'=>'FFFFFF','gjp2'=>str_repeat('a',40)]),'color writes authenticate the game account');
    $denied($colorCall('save',7,['nicknameColor'=>'FFFFFF','prefixColor'=>'FF0000']),'unaffiliated player cannot set clan color');
    $public=$ok($colorCall('batch',0,['accountIDs'=>'1,2,1,999']),'public bounded batch requires no account credential');
    clanCheck(count($public['players'])===2 && $public['players'][0]['username']==='Player1' && $public['players'][0]['clan_tag']==='MCP','batch deduplicates IDs and omits nonexistent accounts');
    clanCheck(!str_contains(json_encode($public),'gjp2') && !str_contains(json_encode($public),'password'),'public appearance exposes no credentials');
    foreach (['1 OR 1=1','0','-1','2147483648','1,',''] as $bad) $denied($colorCall('batch',0,['accountIDs'=>$bad]),'batch rejects invalid ID '.$bad);
    $denied($colorCall('batch',0,['accountIDs'=>implode(',',range(1,51))]),'batch rejects more than 50 accounts');
    clanCheck($colorCall('batch',0,['accountIDs'=>'1'],'GET')['http_status']===405,'color API rejects GET');
    $ok($colorCall('save',2,['nicknameColor'=>'DEFAULT']),'nickname reset to normal GD color');
    clanCheck($colorCall('mine',2)['data']['nickname_color']==='','default reset persists on server');
    clanCheck($call('myClan',1)['data']['prefix_color']==='BD93F9' && $call('leaderboard',1)['data']['own_clan']['prefix_color']==='BD93F9','clan pages and ranking expose prefix color');
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
    $denied($colorCall('save',1,['nicknameColor'=>'66CCFF','prefixColor'=>'FFFFFF']),'former owner loses color permission');
    $ok($colorCall('save',2,['nicknameColor'=>'64E572','prefixColor'=>'56E5DE']),'new owner can change clan prefix');
    clanCheck(!$colorCall('mine',1)['data']['can_edit_prefix'] && $colorCall('mine',2)['data']['can_edit_prefix'],'color permission follows ownership');
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
    clanCheck($colorCall('mine',35)['data']['clan_tag']==='','leaving removes public clan prefix');
    clanCheck($call('leaderboard',1)['data']['own_clan']['score']===350 && $call('leaderboard',35)['data']['own_clan']===null,'leaving removes contribution and own rank');
    $denied($quests('quests',35),'unaffiliated player cannot read quests');
    $denied($quests('activity',10,['clanID'=>$clanId,'gjp2'=>str_repeat('a',40)]),'activity requires valid authentication');
    clanCheck($quests('quests',1,[],'GET')['http_status']===405,'quest API rejects GET');
    $denied($quests('create',3,['metric'=>'stars','target'=>'100']),'ordinary member cannot start a quest');
    foreach ([['stars','99'],['demons','4'],['stars','100001'],['demons','1001'],['stars DESC','100']] as [$metric,$target]) {
        $denied($quests('create',2,['metric'=>$metric,'target'=>$target]),'quest validates metric and goal '.$metric.' '.$target);
    }
    $starQuest=$ok($quests('create',1,['metric'=>'stars','target'=>'100']),'officer starts stars quest')['quest_id'];
    $demonQuest=$ok($quests('create',2,['metric'=>'demons','target'=>'5']),'owner starts simultaneous demons quest')['quest_id'];
    $denied($quests('create',2,['metric'=>'stars','target'=>'1000']),'one active quest per metric');
    $state=$ok($quests('quests',3),'member reads shared quest progress');
    clanCheck(count($state['quests'])===2 && array_sum(array_column($state['quests'],'progress'))===0 && !$state['can_manage'],'quest baseline excludes old statistics');
    $sync(3,70,4);
    $sync(3,70,4);
    $sync(3,10,0);
    $sync(3,70,4);
    $state=$quests('quests',2)['data']['quests'];
    $byId=array_column($state,null,'quest_id');
    clanCheck($byId[$starQuest]['progress']===20 && $byId[$demonQuest]['progress']===1,'duplicate score and reset/restore cannot replay contributions');
    $ok($call('invite',2,['targetAccountID'=>'35']),'invite quest participant');
    $invite=(string)$call('invites',35)['data']['invites'][0]['invite_id'];
    $sync(35,9000,100);
    $ok($call('acceptInvite',35,['inviteID'=>$invite]),'invited member enrolls with current score baseline');
    $sync(35,9010,101);
    $ok($call('leave',35),'quest participant leaves');
    $sync(35,9500,110);
    $ok($call('invite',2,['targetAccountID'=>'35']),'invite returning quest participant');
    $invite=(string)$call('invites',35)['data']['invites'][0]['invite_id'];
    $ok($call('acceptInvite',35,['inviteID'=>$invite]),'returning participant keeps earned contribution');
    $sync(35,9505,111);
    $ok($call('kick',2,['targetAccountID'=>'35']),'kick quest participant');
    $members=$ok($quests('contributions',2,['questID'=>(string)$starQuest,'limit'=>'2']),'paged quest contributions');
    clanCheck(count($members['members'])===2 && $members['has_more'] && $members['members'][0]['account_id']===3,'contributions sorted by score with lookahead');
    clanCheck($members['members'][1]['account_id']===35 && $members['members'][1]['contribution']===15 && !$members['members'][1]['is_member'],'past member contribution retained; outside-clan gains excluded');
    $denied($quests('contributions',10,['questID'=>(string)$starQuest]),'other clan cannot inspect quest participants');
    $denied($quests('cancel',3,['questID'=>(string)$starQuest]),'member cannot cancel quest');
    $sync(1,200,4);
    $state=array_column($quests('quests',2)['data']['quests'],null,'quest_id');
    clanCheck($state[$starQuest]['state']==='completed' && $state[$starQuest]['progress']===135 && $state[$demonQuest]['state']==='completed','both metric quests complete through score sync');
    $eventsBefore=(int)$pdo->query('SELECT COUNT(*) FROM mucho_clan_events WHERE clan_id='.(int)$clanId.' AND message LIKE \'Quest completed:%\'')->fetchColumn();
    $sync(1,200,4);
    $quests('quests',2);
    clanCheck($eventsBefore===2 && (int)$pdo->query('SELECT COUNT(*) FROM mucho_clan_events WHERE clan_id='.(int)$clanId.' AND message LIKE \'Quest completed:%\'')->fetchColumn()===2,'completion event recorded exactly once');
    $denied($quests('cancel',2,['questID'=>(string)$starQuest]),'completed quest cannot be cancelled');
    $cancelled=$ok($quests('create',2,['metric'=>'stars','target'=>'1000']),'start next stars quest')['quest_id'];
    $ok($quests('cancel',1,['questID'=>(string)$cancelled]),'officer cancels active quest');
    $denied($quests('cancel',1,['questID'=>(string)$cancelled]),'repeated cancellation rejected');
    $expired=$ok($quests('create',2,['metric'=>'stars','target'=>'1000']),'start expiry fixture')['quest_id'];
    $pdo->exec('UPDATE mucho_clan_quests SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE quest_id='.(int)$expired);
    $sync(1,300,4);
    $state=array_column($quests('quests',2)['data']['quests'],null,'quest_id');
    clanCheck($state[$expired]['state']==='expired' && $state[$expired]['progress']===0 && $state[$cancelled]['progress']===0,'expired and cancelled quests stop accepting gains');
    $race=$ok($quests('create',2,['metric'=>'stars','target'=>'100']),'expired quest frees its metric slot')['quest_id'];
    $processes=[];
    foreach ([[1,350,4],[3,120,4],[1,350,4]] as [$id,$stars,$demons]) {
        $process=proc_open([PHP_BINARY,__FILE__,'--quest-score',$name,(string)$id,(string)$stars,(string)$demons],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start score concurrency test worker.');
        fclose($pipes[0]); $processes[]=[$process,$pipes];
    }
    foreach ($processes as [$process,$pipes]) {
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        clanCheck(proc_close($process)===0 && $stdout==='QUEST_SCORE_WORKER_OK','concurrent score worker succeeds '.$stderr);
    }
    $state=array_column($quests('quests',2)['data']['quests'],null,'quest_id');
    clanCheck($state[$race]['state']==='completed' && $state[$race]['progress']===100,'overlapping syncs neither lose nor duplicate contributions');
    $atomic=$ok($quests('create',2,['metric'=>'stars','target'=>'100']),'start atomic rollback fixture')['quest_id'];
    $pdo->exec("CREATE TRIGGER quest_event_failure BEFORE INSERT ON mucho_clan_events FOR EACH ROW
        BEGIN IF NEW.message LIKE 'Quest completed:%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated quest event fault'; END IF; END");
    $failed=false;
    try { $scoreService->updateScore(1,$token(1),['gameVersion'=>'22','stars'=>'450','demons'=>'4']); } catch (PDOException) { $failed=true; }
    $pdo->exec('DROP TRIGGER quest_event_failure');
    clanCheck($failed && !$pdo->inTransaction() && (int)$pdo->query('SELECT stars FROM profiles WHERE account_id=1')->fetchColumn()===350 &&
        (int)$pdo->query('SELECT progress FROM mucho_clan_quests WHERE quest_id='.(int)$atomic)->fetchColumn()===0,'quest fault rolls back both score and progress');
    $sync(1,450,4);
    clanCheck(array_column($quests('quests',2)['data']['quests'],null,'quest_id')[$atomic]['progress']===100,'retry after a storage fault awards progress once');
    $activity=$ok($quests('activity',3,['limit'=>'3']),'member reads private clan activity');
    $older=$quests('activity',3,['limit'=>'3','offset'=>'3'])['data'];
    clanCheck(count($activity['events'])===3 && $activity['has_more'] && !array_intersect(array_column($activity['events'],'event_id'),array_column($older['events'],'event_id')),'activity pages bounded with stable ordering');
    clanCheck(str_contains(json_encode($quests('activity',3,['limit'=>'50'])['data']),'joined the clan') && !str_contains(json_encode($activity),'gjp2'),'activity contains membership events without credentials');
    $ok($call('disband',2),'new owner disbands');
    foreach (['mucho_clan_quests','mucho_clan_events'] as $table) clanCheck((int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE clan_id='.(int)$clanId)->fetchColumn()===0,'disband cascades '.$table);
    clanCheck((int)$pdo->query('SELECT COUNT(*) FROM mucho_clan_quest_members')->fetchColumn()===0,'disband cascades quest contributions');
    clanCheck((int)$pdo->query('SELECT COUNT(*) FROM mucho_clan_appearance WHERE clan_id='.(int)$clanId)->fetchColumn()===0,'disband cascades stored clan color');
    clanCheck($colorCall('mine',1)['data']['clan_tag']==='' && $colorCall('mine',1)['data']['nickname_color']==='66CCFF','disband removes prefix but preserves personal nickname color');
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
