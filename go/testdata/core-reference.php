<?php
// Build the same baseline in two disposable schemas, replay native PHP routes
// in one, and leave the other untouched for the Go differential test.
declare(strict_types=1);
require __DIR__.'/full-fixtures.php';
require __DIR__.'/fixed-clock.php';
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;

$keyFile=getenv('MUCHO_GO_TEST_KEY_FILE');
if(!$keyFile||!str_starts_with($keyFile,'/'))throw new RuntimeException('Absolute test key path required');
if(file_put_contents($keyFile,base64_encode(str_repeat("K",32))."\n")===false)throw new RuntimeException('Cannot write test key');chmod($keyFile,0600);
putenv('MUCHO_CLOUDSAVE_KEY_FILE='.$keyFile);
$insert=$db->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?,1)');
foreach([1=>'Alice',2=>'Bob',3=>'Banned',4=>'Moderator',5=>'Elder',6=>'Owner'] as $id=>$name){
    $insert->execute([$id,$name,strtolower($name).'@example.com',password_hash('test-password',PASSWORD_BCRYPT,['cost'=>4]),password_hash(sha1('test-password'.MuchoCore\Account\AccountService::GJP2_SALT),PASSWORD_BCRYPT,['cost'=>4])]);
    $db->exec('INSERT INTO profiles(account_id) VALUES('.$id.')');
}
$db->exec('UPDATE accounts SET is_banned=1 WHERE account_id=3');
foreach([4=>'moderator',5=>'elder_moderator',6=>'owner'] as $id=>$role){$db->exec("UPDATE accounts SET role_id=(SELECT id FROM roles WHERE code='$role'),role='$role' WHERE account_id=$id");}
$cloudRepo=new MuchoCore\CloudSave\CloudSaveRepository($db);
$cloudRepo->save(1," \ninitial-save;data; \n");
$db->exec("INSERT INTO mucho_secret_rewards(reward_key,chest_type,rewards,uses,expires_at,active) VALUES('native-test-reward',1,'100,2,1,1',3,1700000300,1)");

// Clone all migration defaults and seeded records. This copies only a newly
// generated test schema, never the developer's or operator's installation.
$db->exec('CREATE DATABASE IF NOT EXISTS muchocore_go_reference CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
if((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='muchocore_go_reference'")->fetchColumn()!==0)throw new RuntimeException('Empty PHP reference database required');
$db->exec('SET FOREIGN_KEY_CHECKS=0');
$sourceDatabase=(string)$db->query('SELECT DATABASE()')->fetchColumn();
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$db->exec('USE muchocore_go_reference');
foreach($tables as $table){
    if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Invalid table name');
    // CREATE LIKE drops foreign keys. Preserve the real schema so cascading
    // deletes and integrity failures are exercised on both runtimes.
    $create=$db->query("SHOW CREATE TABLE `$sourceDatabase`.`$table`")->fetch(PDO::FETCH_NUM)[1];
    $db->exec($create);
    $db->exec("INSERT INTO `$table` SELECT * FROM `$sourceDatabase`.`$table`");
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$db->exec('USE muchocore_go_reference');
$db->exec('SET timestamp=1700000000');

$auth=new MuchoCore\Account\AccountAuthenticator($db);
$account=new MuchoCore\Account\AccountController(new MuchoCore\Account\AccountService($db,new MuchoCore\Account\AccountRepository($db),new MuchoCore\Job\JobQueue($db)));
$relationships=new MuchoCore\Social\RelationshipRepository($db);
$friends=new MuchoCore\Social\RelationshipController(new MuchoCore\Social\RelationshipService($relationships,$auth,new MuchoCore\Protocol\GdRelationshipEncoder()));
$messages=new MuchoCore\Social\MessageController(new MuchoCore\Social\MessageService(new MuchoCore\Social\MessageRepository($db),$auth,new MuchoCore\Protocol\GdMessageEncoder(),$relationships));
$cloud=new MuchoCore\CloudSave\CloudSaveController(new MuchoCore\CloudSave\CloudSaveService($db,$auth,new MuchoCore\CloudSave\CloudSaveRepository($db)));
$following=new MuchoCore\Social\FollowingController(new MuchoCore\Social\FollowingService($db,$auth,new MuchoCore\Protocol\GdRelationshipEncoder()));
$notifications=new MuchoCore\Social\NotificationController(new MuchoCore\Social\NotificationService($db,$auth));
$cache=new MuchoCore\Cache\NullCache();
$levels=new MuchoCore\Level\LevelController(new MuchoCore\Level\LevelService(new MuchoCore\Level\LevelRepository($db),new MuchoCore\Protocol\GdLevelListEncoder(),$cache),$auth,new MuchoCore\Moderation\ModerationRepository($db));
$transfer=new MuchoCore\Level\LevelTransferController(new MuchoCore\Level\LevelTransferService($db,$auth,new MuchoCore\Compatibility\Legacy10IdentityService($db),new MuchoCore\Level\LevelTransferRepository($db),new MuchoCore\Level\LevelDownloadTracker($db),new MuchoCore\Protocol\GdLevelDownloadEncoder(),new MuchoCore\Level\LevelValidator(),new MuchoCore\Level\LevelRevisionService($db),new MuchoCore\Search\LevelSearchIndexer($db),$cache,new MuchoCore\Job\JobQueue($db)));
$users=new MuchoCore\User\UserController(new MuchoCore\User\UserService($db,$auth,new MuchoCore\Account\AccountRepository($db),new MuchoCore\User\UserRepository($db),new MuchoCore\Protocol\GdUserEncoder(),new MuchoCore\Compatibility\Legacy10IdentityService($db),new MuchoCore\Clan\ClanQuestRepository($db)));
$comments=new MuchoCore\Interaction\CommentController(new MuchoCore\Interaction\CommentService(new MuchoCore\Interaction\CommentRepository($db),$auth,new MuchoCore\Protocol\GdCommentEncoder(),$db,new MuchoCore\Compatibility\Legacy10IdentityService($db)));
$likes=new MuchoCore\Interaction\LikeController(new MuchoCore\Interaction\LikeService(new MuchoCore\Interaction\LikeRepository($db),$auth));
$regularScores=new MuchoCore\Score\LevelScoreController($db,$auth);
$platformerScores=new MuchoCore\Score\PlatformerScoreController($db,$auth);
$rewards=new MuchoCore\Interaction\RewardsController(new MuchoCore\Interaction\RewardsService(new MuchoCore\Interaction\RewardsRepository($db),$auth));
$clans=new MuchoCore\Clan\ClanController(new MuchoCore\Clan\ClanService($db,$auth,new MuchoCore\Clan\ClanRepository($db)));
$clanQuests=new MuchoCore\Clan\ClanQuestController($db,$auth,new MuchoCore\Clan\ClanQuestRepository($db));
$moderation=new MuchoCore\Moderation\ModerationController(new MuchoCore\Moderation\ModerationService($auth,new MuchoCore\Moderation\ModerationRepository($db),$cache));
$router=new Router();
foreach([
    '/registerGJAccount'=>[$account,'register'],'/loginGJAccount'=>[$account,'login'],
    '/uploadFriendRequest20'=>[$friends,'send'],'/getGJFriendRequests20'=>[$friends,'get'],
    '/readGJFriendRequest20'=>[$friends,'read'],'/acceptGJFriendRequest20'=>[$friends,'accept'],
    '/deleteGJFriendRequests20'=>[$friends,'delete'],'/removeGJFriend20'=>[$friends,'remove'],
    '/blockGJUser20'=>[$friends,'block'],'/unblockGJUser20'=>[$friends,'unblock'],
    '/getGJUserList20'=>[$friends,'userList'],
    '/getGJMessages20'=>[$messages,'getMessages'],'/downloadGJMessage20'=>[$messages,'readMessage'],
    '/uploadGJMessage20'=>[$messages,'sendMessage'],'/deleteGJMessages20'=>[$messages,'deleteMessage'],
    '/backupGJAccount'=>[$cloud,'backup'],'/syncGJAccount'=>[$cloud,'sync'],
    '/followGJUser'=>[$following,'follow'],'/unfollowGJUser'=>[$following,'unfollow'],
    '/muteGJUser'=>[$following,'mute'],'/getGJFollowing'=>[$following,'get'],'/syncGJFollowing'=>[$following,'sync'],
    '/getGJNotifications'=>[$notifications,'get'],'/claimGJNotificationReward'=>[$notifications,'claim'],
    '/getGJLevels21'=>[$levels,'list'],'/uploadGJLevel21'=>[$transfer,'upload'],
    '/downloadGJLevel21'=>[$transfer,'download'],'/updateGJLevel'=>[$transfer,'checkUpdate'],
    '/updateGJLevelDesc20'=>[$transfer,'updateDescription'],'/deleteGJLevelUser20'=>[$transfer,'delete'],
    '/getGJUserInfo20'=>[$users,'profile'],'/getGJUsers20'=>[$users,'search'],'/getGJUserSearch'=>[$users,'search209'],
    '/getGJScores20'=>[$users,'scores'],'/requestUserAccess'=>[$users,'requestAccess'],
    '/updateGJUserScore'=>[$users,'updateScore'],'/updateGJUserScore22'=>[$users,'updateScore'],'/updateGJAccSettings20'=>[$users,'updateSettings'],
    '/getGJComments21'=>[$comments,'getLevelComments'],'/getGJAccountComments20'=>[$comments,'getAccountComments'],
    '/uploadGJComment20'=>[$comments,'uploadLevelComment'],'/uploadGJComment21'=>[$comments,'uploadLevelComment'],
    '/uploadGJAccComment20'=>[$comments,'uploadAccountComment'],'/deleteGJComment20'=>[$comments,'deleteComment'],
    '/deleteGJAccComment20'=>[$comments,'deleteAccountComment'],'/likeGJItem21'=>[$likes,'like'],
    '/getGJLevelScores'=>[$regularScores,'regular'],'/getGJLevelScores211'=>[$regularScores,'regular'],
    '/getGJLevelScoresPlat'=>[$platformerScores,'handle'],
    '/getGJRewards'=>[$rewards,'getRewards'],'/getGJChallenges'=>[$rewards,'getChallenges'],'/getGJSecretReward'=>[$rewards,'getSecretReward'],
    '/clans/create'=>[$clans,'create'],
    '/clans/my'=>[$clans,'myClan'],
    '/clans/get'=>[$clans,'get'],
    '/clans/search'=>[$clans,'search'],
    '/clans/leaderboard'=>[$clans,'leaderboard'],
    '/clans/join'=>[$clans,'join'],
    '/clans/leave'=>[$clans,'leave'],
    '/clans/invite'=>[$clans,'invite'],
    '/clans/invite/accept'=>[$clans,'acceptInvite'],
    '/clans/invite/decline'=>[$clans,'declineInvite'],
    '/clans/kick'=>[$clans,'kick'],
    '/clans/role'=>[$clans,'setRole'],
    '/clans/invites'=>[$clans,'invites'],
    '/clans/invites/sent'=>[$clans,'sentInvites'],
    '/clans/settings'=>[$clans,'updateSettings'],
    '/clans/transfer'=>[$clans,'transferOwnership'],
    '/clans/disband'=>[$clans,'disband'],
    '/clans/invite/revoke'=>[$clans,'revokeInvite'],
    '/clans/ban'=>[$clans,'ban'],
    '/clans/unban'=>[$clans,'unban'],
    '/clans/bans'=>[$clans,'bans'],
    '/clans/quests'=>[$clanQuests,'quests'],
    '/clans/quests/create'=>[$clanQuests,'create'],
    '/clans/quests/cancel'=>[$clanQuests,'cancel'],
    '/clans/quests/contributions'=>[$clanQuests,'contributions'],
    '/clans/activity'=>[$clanQuests,'activity'],
    '/rateGJStars20'=>[$moderation,'rateStars'],'/rateGJStars211'=>[$moderation,'rateStars'],'/rateGJDemon21'=>[$moderation,'rateDemon'],'/suggestGJStars20'=>[$moderation,'suggest'],'/reportGJLevel'=>[$moderation,'report'],
] as $path=>$handler){$router->add('ANY',$path,$handler);}
$a=['accountID'=>1,'gjp2'=>sha1('test-password'.MuchoCore\Account\AccountService::GJP2_SALT),'gameVersion'=>22,'binaryVersion'=>51];
$b=$a;$b['accountID']=2;
$cases=[
    ['/accounts/loginGJAccount22.php',['userName'=>'Alice','password'=>'test-password']],
    ['/loginGJAccount.php',['userName'=>'Alice','gjp2'=>$a['gjp2']]],
    ['/loginGJAccount.php',['userName'=>'Alice','password'=>'wrong']],
    ['/loginGJAccount.php',['userName'=>'Banned','password'=>'test-password']],
    ['/registerGJAccount.php',['userName'=>'invalid!','password'=>'x','email'=>'test@example.com']],
    ['/registerGJAccount.php',['userName'=>'Alice','password'=>'x','email'=>'new@example.com']],
    ['/registerGJAccount.php',['userName'=>'NewUser','password'=>'x','email'=>'bob@example.com']],
    ['/registerGJAccount.php',['userName'=>'NewUser','password'=>'x','email'=>'bad']],
    ['/registerGJAccount.php',['userName'=>'NewUser','password'=>'password','email'=>'new@example.com']],
    ['/loginGJAccount.php',['userName'=>'NewUser','password'=>'password']],
    ['/getGJMessages20.php',$a],
    ['/uploadFriendRequest20.php',$a+['toAccountID'=>2,'comment'=>'SGVsbG8=']],
    ['/uploadFriendRequest20.php',$a+['toAccountID'=>2]],
    ['/getGJFriendRequests20.php',$b],
    ['/getGJFriendRequests20.php',$a+['getSent'=>1]],
    ['/readGJFriendRequest20.php',$a+['requestID'=>1]],
    ['/readGJFriendRequest20.php',$b+['requestID'=>1]],
    ['/acceptGJFriendRequest20.php',$a+['requestID'=>1]],
    ['/acceptGJFriendRequest20.php',$b+['requestID'=>1]],
    ['/getGJUserList20.php',$a+['type'=>0]],
    ['/getGJUserList20.php',$a+['type'=>0]],
    ['/uploadGJMessage20.php',$a+['toAccountID'=>2,'subject'=>'SGk=','body'=>'V29ybGQ=']],
    ['/getGJMessages20.php',$b],
    ['/getGJMessages20.php',$a+['getSent'=>1]],
    ['/downloadGJMessage20.php',$b+['messageID'=>1]],
    ['/downloadGJMessage20.php',$a+['messageID'=>1]],
    ['/downloadGJMessage20.php',['accountID'=>3,'gjp2'=>$a['gjp2'],'messageID'=>1]],
    ['/deleteGJMessages20.php',$a+['messageID'=>1,'isSender'=>0]],
    ['/deleteGJMessages20.php',$b+['messageID'=>1,'isSender'=>0]],
    ['/getGJMessages20.php',$b],
    ['/blockGJUser20.php',$a+['targetAccountID'=>2]],
    ['/getGJUserList20.php',$a+['type'=>1]],
    ['/getGJUserList20.php',$b+['type'=>0]],
    ['/uploadGJMessage20.php',$b+['toAccountID'=>1,'subject'=>'SGk=','body'=>'V29ybGQ=']],
    ['/unblockGJUser20.php',$a+['targetAccountID'=>2]],
    ['/syncGJAccount.php',$a],
    ['/backupGJAccount.php',$a+['saveData'=>"Go-compatible save \n"]],
    ['/backupGJAccount.php',$a+['saveData'=>"Go-compatible save \n"]],
    ['/syncGJAccount.php',$a],
    ['/syncGJAccount.php',['userName'=>'Alice','password'=>'test-password']],
    ['/syncGJAccount.php',['accountID'=>1,'password'=>'wrong']],
    ['/followGJUser.php',$a+['targetAccountID'=>2]],
    ['/followGJUser.php',$a+['targetAccountID'=>2]],
    ['/getGJFollowing.php',$a],
    ['/getGJFollowing.php',$b+['getFollowers'=>1]],
    ['/syncGJFollowing.php',$a],
    ['/getGJNotifications.php',$b],
    ['/claimGJNotificationReward.php',$a+['notificationID'=>1]],
    ['/claimGJNotificationReward.php',$b+['notificationID'=>1]],
    ['/claimGJNotificationReward.php',$b+['notificationID'=>1]],
    ['/muteGJUser.php',$a+['targetAccountID'=>2]],
    ['/getGJUserList20.php',$a+['type'=>4]],
    ['/muteGJUser.php',$a+['targetAccountID'=>2,'mute'=>0]],
    ['/unfollowGJUser.php',$a+['targetAccountID'=>2]],
    ['/getGJFollowing.php',$a],
];
$upload=$a+['levelName'=>'Test Level','levelString'=>strtr(base64_encode(gzcompress('kS1,0;kA13,0;')),'+/','-_'),'levelDesc'=>'SGVsbG8=','levelVersion'=>1,'objects'=>10,'levelLength'=>2,'password'=>'123456'];
$cases=array_merge($cases,[
    ['/getGJUserInfo20.php',['targetAccountID'=>1]],
    ['/getGJUserInfo20.php',$a+['targetAccountID'=>2]],
    ['/getGJUserInfo20.php',['targetUserID'=>1]],
    ['/getGJUserInfo20.php',['targetAccountID'=>1,'accountID'=>1]],
    ['/getGJUserInfo20.php',$a+['targetAccountID'=>1]],
    ['/getGJUserInfo20.php',['targetAccountID'=>1,'gameVersion'=>21]],
    ['/getGJUsers20.php',['str'=>'Ali']],
    ['/getGJUsers20.php',['str'=>'1']],
    ['/getGJUserSearch.php',['query'=>'Bob']],
    ['/getGJUsers20.php',['str'=>'no-such-player']],
    ['/requestUserAccess.php',$a],
    ['/updateGJUserScore.php',$a+['stars'=>100,'demons'=>10,'diamonds'=>42,'coins'=>3,'userCoins'=>7,'color3'=>-1,'accIcon'=>12,'accShip'=>7]],
    ['/updateGJUserScore.php',$b+['stars'=>200,'demons'=>20]],
    ['/updateGJUserScore.php',$a+['stars'=>'invalid']],
    ['/updateGJUserScore.php',$a+['stars'=>-1]],
    ['/getGJScores20.php',['type'=>'top','gameVersion'=>22]],
    ['/getGJScores20.php',['type'=>'creators','gameVersion'=>22]],
    ['/getGJScores20.php',$a+['type'=>'relative']],
    ['/getGJScores20.php',$a+['type'=>'friends']],
    ['/getGJScores20.php',['accountID'=>1,'type'=>'top']],
    ['/updateGJAccSettings20.php',$a+['mS'=>1,'frS'=>2,'cS'=>1,'yt'=>'test','twitter'=>'test','discord'=>'test']],
    ['/getGJUserInfo20.php',$a],
    ['/uploadGJLevel21.php',$upload],
    ['/getGJLevels21.php',['type'=>0,'str'=>'Test','gameVersion'=>22,'binaryVersion'=>51]],
    ['/getGJLevels21.php',['type'=>1,'gameVersion'=>22]],
    ['/getGJLevels20.php',['type'=>0,'str'=>'1','gameVersion'=>20]],
    ['/getGJLevels.php',['type'=>0]],
    ['/getGJLevels21.php',['type'=>0,'diff'=>'-1']],
    ['/getGJLevels21.php',['type'=>0,'star'=>1]],
    ['/getGJLevels21.php',['type'=>0,'len'=>'2']],
    ['/getGJLevels21.php',['type'=>0,'len'=>'1']],
    ['/downloadGJLevel21.php',$a+['levelID'=>1,'extras'=>1]],
    ['/downloadGJLevel21.php',['levelID'=>1,'gameVersion'=>19]],
    ['/downloadGJLevel.php',['levelID'=>1]],
    ['/downloadGJLevel21.php',$a+['levelID'=>1,'inc'=>1]],
    ['/downloadGJLevel21.php',$a+['levelID'=>1,'inc'=>1]],
    ['/downloadGJLevel21.php',$a+['levelID'=>1,'inc'=>1]],
    ['/downloadGJLevel21.php',$a+['levelID'=>1,'inc'=>1]],
    ['/updateGJLevel.php',['levelID'=>1,'levelVersion'=>1]],
    ['/uploadGJLevel21.php',array_merge($upload,['levelID'=>1,'levelVersion'=>2])],
    ['/updateGJLevel.php',['levelID'=>1,'levelVersion'=>1]],
    ['/uploadGJLevel21.php',array_merge($upload,['accountID'=>2,'levelID'=>1])],
    ['/updateGJLevelDesc20.php',$a+['levelID'=>1,'levelDesc'=>'VXBkYXRlZA==']],
    ['/downloadGJLevel21.php',$a+['levelID'=>1]],
    ['/uploadGJLevel21.php',array_merge($upload,['levelName'=>'Private','unlisted1'=>1,'unlisted2'=>1])],
    ['/downloadGJLevel21.php',$b+['levelID'=>2]],
    ['/downloadGJLevel21.php',$a+['levelID'=>2]],
    ['/getGJLevels21.php',['type'=>0,'gameVersion'=>22]],
    ['/deleteGJLevelUser20.php',$b+['levelID'=>1]],
    ['/deleteGJLevelUser20.php',$a+['levelID'=>1]],
    ['/downloadGJLevel21.php',$a+['levelID'=>1]],
]);
$moderator=$a;$moderator['accountID']=4;
$elder=$a;$elder['accountID']=5;
$owner=$a;$owner['accountID']=6;
$cases=array_merge($cases,[
    ['/uploadGJLevel21.php',array_merge($upload,['levelName'=>'Comments Test'])],
    ['/getGJComments21.php',$a+['levelID'=>3]],
    ['/uploadGJComment21.php',$a+['levelID'=>3,'comment'=>'SGVsbG8=','percent'=>25]],
    ['/getGJComments21.php',$a+['levelID'=>3]],
    ['/getGJComments21.php',['levelID'=>3,'gameVersion'=>19,'binaryVersion'=>0]],
    ['/getGJComments21.php',['userID'=>1,'gameVersion'=>22,'binaryVersion'=>51]],
    ['/getGJComments21.php',['userID'=>1,'gameVersion'=>19]],
    ['/uploadGJComment21.php',$b+['levelID'=>2,'comment'=>'SGVsbG8=']],
    ['/likeGJItem21.php',$b+['itemID'=>1,'type'=>2,'like'=>1]],
    ['/likeGJItem21.php',$b+['itemID'=>1,'type'=>2,'like'=>1]],
    ['/getGJComments21.php',$a+['levelID'=>3,'mode'=>1]],
    ['/likeGJItem21.php',$b+['itemID'=>1,'type'=>2,'like'=>0]],
    ['/deleteGJComment20.php',$b+['commentID'=>1]],
    ['/deleteGJComment20.php',$a+['commentID'=>1]],
    ['/getGJComments21.php',$a+['levelID'=>3]],
    ['/uploadGJAccComment20.php',$a+['comment'=>'UHJvZmlsZSBwb3N0']],
    ['/getGJAccountComments20.php',$a],
    ['/getGJAccountComments20.php',['accountID'=>1,'gameVersion'=>19]],
    ['/deleteGJAccComment20.php',$b+['commentID'=>1]],
    ['/deleteGJAccComment20.php',$a+['commentID'=>1]],
    ['/getGJAccountComments20.php',$a],
    ['/uploadGJComment21.php',$a+['levelID'=>3,'comment'=>base64_encode('!help')]],
    ['/uploadGJComment21.php',$a+['levelID'=>3,'comment'=>base64_encode('!feature')]],
    ['/uploadGJComment21.php',$moderator+['levelID'=>3,'comment'=>base64_encode('!rate hard 5')]],
    ['/uploadGJComment21.php',$moderator+['levelID'=>3,'comment'=>base64_encode('!feature')]],
    ['/uploadGJComment21.php',$elder+['levelID'=>3,'comment'=>base64_encode('!rate hard 5 1 2')]],
    ['/getGJLevels21.php',['type'=>0,'str'=>'3','gameVersion'=>22,'binaryVersion'=>51]],
    ['/uploadGJComment21.php',$moderator+['levelID'=>3,'comment'=>base64_encode('!cp 4')]],
    ['/getGJUserInfo20.php',['targetAccountID'=>1]],
    ['/deleteGJLevelUser20.php',$a+['levelID'=>3]],
    ['/uploadGJComment21.php',$owner+['levelID'=>3,'comment'=>base64_encode('!delete')]],
    ['/getGJComments21.php',$a+['levelID'=>3]],
]);
$check=static function(string $key):string{return 'abcde'.strtr(base64_encode(MuchoCore\Protocol\GdXor::cipher('test-challenge',$key)),'+/','-_');};
$rewardBase=$a+['udid'=>'native-device','chk'=>$check('59182')];
$cases=array_merge($cases,[
    ['/uploadGJLevel21.php',array_merge($upload,['levelName'=>'Scores Test'])],
    ['/getGJLevelScores.php',$a+['levelID'=>4,'percent'=>50,'s1'=>8364,'s2'=>4091,'s3'=>4115]],
    ['/getGJLevelScores.php',$b+['levelID'=>4,'percent'=>100,'s1'=>8374,'s2'=>4191,'s3'=>4145,'s9'=>5821]],
    ['/getGJLevelScores.php',$a+['levelID'=>4,'percent'=>25,'s1'=>8364,'s2'=>4091,'s3'=>4115]],
    ['/getGJLevelScores.php',$a+['levelID'=>4,'percent'=>70,'s1'=>8364,'s2'=>4091,'s3'=>4115,'type'=>0]],
    ['/getGJLevelScores.php',$a+['levelID'=>4,'percent'=>70,'s1'=>8364,'s2'=>4091,'s3'=>4115,'type'=>2]],
    ['/getGJLevelScores.php',$a+['levelID'=>4,'percent'=>101]],
    ['/getGJLevelScores.php',$a+['levelID'=>4,'percent'=>80,'s1'=>100]],
    ['/uploadGJLevel21.php',array_merge($upload,['levelName'=>'Platformer','levelLength'=>5])],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>5,'time'=>3000,'points'=>100,'mode'=>0,'type'=>1]],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>5,'time'=>4000,'points'=>200,'mode'=>0,'type'=>1]],
    ['/getGJLevelScoresPlat.php',$b+['levelID'=>5,'time'=>2000,'points'=>200,'mode'=>0,'type'=>1]],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>5,'time'=>2500,'points'=>300,'mode'=>1,'type'=>1]],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>5,'time'=>1000,'points'=>250,'mode'=>1,'type'=>1]],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>5,'mode'=>0,'type'=>0]],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>5,'mode'=>1,'type'=>2]],
    ['/getGJLevelScoresPlat.php',$a+['levelID'=>4,'time'=>1000]],
    ['/getGJRewards.php',['udid'=>'anonymous','chk'=>$check('59182'),'rewardType'=>0]],
    ['/getGJRewards.php',$rewardBase+['rewardType'=>0]],
    ['/getGJRewards.php',$rewardBase+['rewardType'=>1]],
    ['/getGJRewards.php',$rewardBase+['rewardType'=>1]],
    ['/getGJRewards.php',$rewardBase+['rewardType'=>2]],
    ['/getGJRewards.php',$rewardBase+['rewardType'=>2]],
    ['/getGJRewards.php',$rewardBase+['rewardType'=>0]],
    ['/getGJChallenges.php',$a+['udid'=>'native-device','chk'=>$check('19847')]],
    ['/getGJChallenges.php',['udid'=>'anonymous','chk'=>$check('19847')]],
    ['/getGJSecretReward.php',$rewardBase+['rewardKey'=>'native-test-reward','secret'=>'Wmfd2893gb7']],
    ['/getGJSecretReward.php',$rewardBase+['rewardKey'=>'native-test-reward','secret'=>'Wmfd2893gb7']],
    ['/getGJSecretReward.php',array_merge($rewardBase,['accountID'=>2,'rewardKey'=>'native-test-reward','secret'=>'Wmfd2893gb7'])],
    ['/getGJSecretReward.php',['udid'=>'anonymous','chk'=>$check('59182'),'rewardKey'=>'native-test-reward','secret'=>'Wmfd2893gb7']],
    ['/getGJSecretReward.php',['udid'=>'other-anonymous','chk'=>$check('59182'),'rewardKey'=>'native-test-reward','secret'=>'Wmfd2893gb7']],
    ['/registerGJAccount.php',['userName'=>'AfterGameplay','password'=>'password','email'=>'after@example.com']],
    ['/loginGJAccount.php',['userName'=>'AfterGameplay','password'=>'password']],
]);
// Stateful clan lifecycle: permissions, capacity, quest score baselines,
// invitation ownership, transfer, removal and cascaded disbanding.
$clan=['clanName'=>'Native Clan','clanTag'=>'go','clanDescription'=>"  Native   test  ",'clanMaxMembers'=>2];
$cases=array_merge($cases,[
    ['/api/clans/my',$a],
    ['/api/clans/my',['accountID'=>1,'gjp2'=>'wrong']],
    ['/api/clans/create',$a+['clanName'=>'!','clanTag'=>'A']],
    ['/api/clans/create',$a+$clan],
    ['/api/clans/create',$a+$clan],
    ['/api/clans/my',$a],
    ['/api/clans/get',$b+['clanID'=>1]],
    ['/api/clans/search',$a+['query'=>'Native','limit'=>1]],
    ['/api/clans/leaderboard',['accountID'=>1,'limit'=>1]],
    ['/api/clans/leaderboard',['metric'=>'invalid']],
    ['/api/clans/join',$b+['clanID'=>999]],
    ['/api/clans/join',$b+['clanID'=>1]],
    ['/api/clans/join',$elder+['clanID'=>1]],
    ['/api/clans/leave',$a],
    ['/api/clans/quests/create',$b+['metric'=>'stars','target'=>100]],
    ['/api/clans/quests/create',$a+['metric'=>'stars','target'=>99]],
    ['/api/clans/quests/create',$a+['metric'=>'stars','target'=>100]],
    ['/api/clans/quests/create',$a+['metric'=>'stars','target'=>100]],
    ['/api/clans/quests/create',$a+['metric'=>'demons','target'=>5]],
    ['/api/clans/quests',$b],
    ['/updateGJUserScore.php',$b+['stars'=>250,'demons'=>22]],
    ['/api/clans/quests/contributions',$a+['questID'=>1,'limit'=>1]],
    ['/api/clans/quests',$a],
    ['/api/clans/leave',$b],
    ['/updateGJUserScore.php',$b+['stars'=>1000,'demons'=>100]],
    ['/api/clans/join',$b+['clanID'=>1]],
    ['/api/clans/quests/contributions',$a+['questID'=>1]],
    ['/updateGJUserScore.php',$b+['stars'=>1050,'demons'=>103]],
    ['/api/clans/quests',$a],
    ['/api/clans/quests/contributions',$a+['questID'=>1]],
    ['/api/clans/quests/cancel',$a+['questID'=>1]],
    ['/api/clans/quests/create',$a+['metric'=>'stars','target'=>200]],
    ['/api/clans/quests/cancel',$a+['questID'=>3]],
    ['/api/clans/quests/cancel',$a+['questID'=>3]],
    ['/api/clans/settings',$b+['clanID'=>1]+$clan],
    ['/api/clans/role',$a+['targetAccountID'=>2,'role'=>'invalid']],
    ['/api/clans/role',$a+['targetAccountID'=>2,'role'=>'officer']],
    ['/api/clans/role',$a+['targetAccountID'=>2,'role'=>'officer']],
    ['/api/clans/kick',$b+['targetAccountID'=>1]],
    ['/api/clans/ban',$b+['targetAccountID'=>1]],
    ['/api/clans/settings',$a+['clanID'=>1,'clanName'=>'Native Updated','clanTag'=>'UP','clanOpen'=>0,'clanMaxMembers'=>4]],
    ['/api/clans/get',$a+['clanID'=>1]],
    ['/api/clans/join',$elder+['clanID'=>1]],
    ['/api/clans/invite',$b+['targetAccountID'=>5]],
    ['/api/clans/invites',$elder],
    ['/api/clans/invites/sent',$a],
    ['/api/clans/invite/accept',$moderator+['inviteID'=>1]],
    ['/api/clans/invite/decline',$moderator+['inviteID'=>1]],
    ['/api/clans/invite/accept',$elder+['inviteID'=>1]],
    ['/api/clans/kick',$b+['targetAccountID'=>5]],
    ['/api/clans/ban',$a+['targetAccountID'=>5,'reason'=>'  test   reason  ']],
    ['/api/clans/bans',$a],
    ['/api/clans/invite',$a+['targetAccountID'=>5]],
    ['/api/clans/unban',$a+['targetAccountID'=>5]],
    ['/api/clans/unban',$a+['targetAccountID'=>5]],
    ['/api/clans/invite',$a+['targetAccountID'=>5]],
    ['/api/clans/invite/revoke',$elder+['inviteID'=>2]],
    ['/api/clans/invite/revoke',$a+['inviteID'=>2]],
    ['/api/clans/invite',$a+['targetAccountID'=>5]],
    ['/api/clans/invite/decline',$elder+['inviteID'=>3]],
    ['/api/clans/transfer',$a+['targetAccountID'=>2]],
    ['/api/clans/disband',$a],
    ['/api/clans/activity',$b+['limit'=>3]],
    ['/api/clans/activity',$b+['offset'=>3,'limit'=>3]],
    ['/api/clans/leaderboard',['accountID'=>2,'metric'=>'demons','offset'=>100]],
    ['/api/clans/disband',$b],
    ['/api/clans/my',$a],
    ['/api/clans/quests',$a],
    ['/api/clans/leave',$a],
    ['/api/clans/get',$a+['clanID'=>1]],
]);
$cases=array_merge($cases,[
    ['/uploadGJLevel21.php',array_merge($upload,['levelName'=>'Rating Test'])],
    ['/rateGJStars20.php',$a+['levelID'=>6,'stars'=>5]],
    ['/rateGJStars20.php',$moderator+['levelID'=>6,'stars'=>5]],
    ['/rateGJStars20.php',$elder+['levelID'=>6,'stars'=>11]],
    ['/rateGJStars20.php',$elder+['levelID'=>6,'stars'=>10]],
    ['/rateGJDemon21.php',$moderator+['levelID'=>6,'rating'=>8]],
    ['/getGJLevels21.php',['type'=>0,'str'=>'6','gameVersion'=>22,'binaryVersion'=>51]],
    ['/rateGJStars211.php',$elder+['levelID'=>6,'stars'=>10]],
    ['/getGJLevels21.php',['type'=>0,'str'=>'6','gameVersion'=>22,'binaryVersion'=>51]],
    ['/suggestGJStars20.php',$a+['levelID'=>6,'stars'=>5]],
    ['/suggestGJStars20.php',$moderator+['levelID'=>6,'stars'=>5,'feature'=>4,'coins'=>1]],
    ['/suggestGJStars20.php',$elder+['levelID'=>6,'stars'=>5,'feature'=>4,'coins'=>1]],
    ['/getGJLevels21.php',['type'=>0,'str'=>'6','gameVersion'=>22,'binaryVersion'=>51]],
    ['/getGJUserInfo20.php',['targetAccountID'=>1]],
    ['/reportGJLevel.php',['levelID'=>6]],
    ['/reportGJLevel.php',['levelID'=>6]],
    ['/reportGJLevel.php',['levelID'=>999]],
    ['/rateGJDemon21.php',$owner+['levelID'=>6,'rating'=>3]],
    ['/suggestGJStars20.php',$owner+['levelID'=>6,'stars'=>10,'feature'=>1]],
    ['/getGJLevels21.php',['type'=>0,'str'=>'6','gameVersion'=>22,'binaryVersion'=>51]],
]);
$result=[];
foreach($cases as [$path,$form]){
    $_POST=$form;
    $response=$router->dispatch(new Request('POST',$path,[],$form,['REMOTE_ADDR'=>'192.0.2.1']));
    $result[]=['path'=>$path,'form'=>http_build_query($form),'body'=>$response->body,'status'=>$response->status,'content_type'=>$response->contentType];
}
if(file_put_contents(getenv('MUCHO_GO_CORE_FIXTURES')?:'/tmp/muchocore-go-core.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Cannot write core reference');
echo 'CORE_REFERENCE_OK '.count($result)."\n";
