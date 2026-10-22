<?php
/** Disposable local MariaDB integration; PHP 7.0 syntax throughout. */
declare(strict_types=1);
$root = dirname(__DIR__,2);
foreach (array('runtime','schema','auth','download','upload','users','interactions','cloudsave','profile') as $module) {
    require_once $root . '/compat/php7/' . $module . '.php';
}
function nativeFeatureAssert($ok, $message)
{
    if (!$ok) { throw new RuntimeException($message); }
}
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($password === false) { throw new RuntimeException('TEST_DB_ROOT_PASSWORD required'); }
$connection = 'mysql:host='.$host.';port='.$port.';charset=utf8mb4';
$attributes = array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
$admin = new PDO($connection, 'root', $password, $attributes);
$database = 'mucho_php7_features_ci';
$admin->exec('DROP DATABASE IF EXISTS mucho_php7_features_ci');
$admin->exec('CREATE DATABASE mucho_php7_features_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo = new PDO($connection.';dbname='.$database, 'root', $password, $attributes);
    mucho7_create_schema($pdo);
    // Updating an existing native database is additive and repeatable.
    mucho7_upgrade_features($pdo);
    mucho7_upgrade_features($pdo);
    $hash = password_hash('NativePass123!', PASSWORD_BCRYPT, array('cost'=>4));
    $gjp = sha1('NativePass123!mI29fmAnxgTs');
    $gjpHash = password_hash($gjp, PASSWORD_BCRYPT, array('cost'=>4));
    $insert = $pdo->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash)
        VALUES(?,?,?,?,?)');
    $insert->execute(array(1,'Owner','one@example.invalid',$hash,$gjpHash));
    $insert->execute(array(2,'Other','two@example.invalid',$hash,$gjpHash));
    // Deliberately use a userID different from the accountID.
    $pdo->exec('INSERT INTO profiles(user_id,account_id) VALUES(42,1),(77,2)');
    $pdo->exec("INSERT INTO levels(account_id,name,level_data) VALUES(1,'Public','kS1,1,2;')");
    $auth = array('accountID'=>'1','gjp2'=>$gjp,'gameVersion'=>'22','binaryVersion'=>'51');
    $opts = array('enable_accounts'=>true,'enable_writes'=>true,'enable_cloudsave'=>true);
    $call = function ($route, $data, $options = null) use ($pdo, $opts) {
        return mucho7_dispatch($pdo, '/a/'.$route.'.php', 'POST', $data, $options === null ? $opts : $options);
    };
    $comment = array_merge($auth, array('levelID'=>'1','comment'=>strtr(base64_encode('Native comment 😎'),'+/','-_'),'percent'=>'85'));
    nativeFeatureAssert($call('getGJComments21',array('levelID'=>'1')) === '-2','empty comments return -2');
    nativeFeatureAssert($call('uploadGJComment21',$comment,array()) === '-1','writes stay disabled by default');
    nativeFeatureAssert($call('uploadGJComment21',array_merge($comment,array('gjp2'=>'bad'))) === '-1','forged comment auth');
    nativeFeatureAssert($call('uploadGJComment21',$comment) === '1','first comment id');
    nativeFeatureAssert($call('uploadGJComment21',$comment) === '-1','comment cooldown');
    $read = $call('getGJComments21',array('levelID'=>'1','gameVersion'=>'22','binaryVersion'=>'51','count'=>'20'));
    nativeFeatureAssert(strpos($read,'~3~42~') !== false && strpos($read,':1~Owner~') !== false,'comment author uses profile userID');
    nativeFeatureAssert(strpos($read,'~15~') !== false && substr($read,-7) === '#1:0:20','2.209 comment timestamp and pagination');
    nativeFeatureAssert(strpos($call('getGJCommentHistory',array('userID'=>'42','binaryVersion'=>'51')),'1~1~2~') === 0,'history includes level id');
    $vote = array_merge($auth,array('type'=>'2','itemID'=>'1','like'=>'1'));
    nativeFeatureAssert($call('likeGJItem211',$vote) === '1','vote comment');
    nativeFeatureAssert($call('likeGJItem211',$vote) === '1','duplicate vote is idempotent');
    nativeFeatureAssert((int)$pdo->query('SELECT likes FROM php7_comments WHERE id=1')->fetchColumn() === 1,'duplicate not counted');
    nativeFeatureAssert($call('likeGJItem211',array_merge($vote,array('like'=>'0'))) === '1','change vote direction');
    nativeFeatureAssert((int)$pdo->query('SELECT likes FROM php7_comments WHERE id=1')->fetchColumn() === -1,'vote delta reflects old direction');
    $pdo->exec('UPDATE levels SET is_unlisted=1 WHERE level_id=1');
    nativeFeatureAssert($call('getGJComments21',array('levelID'=>'1')) === '-2','private level comments hidden');
    nativeFeatureAssert($call('getGJCommentHistory',array('userID'=>'42')) === '-2','private level history hidden');
    nativeFeatureAssert($call('likeGJItem211',$vote) === '-1','private level comment vote refused');
    $pdo->exec('UPDATE levels SET is_unlisted=0 WHERE level_id=1');
    nativeFeatureAssert($call('deleteGJComment20',array_merge($auth,array('accountID'=>'2','commentID'=>'1'))) === '-1','cannot delete another author');
    nativeFeatureAssert($call('deleteGJAccountComment20',array_merge($auth,array('commentID'=>'1'))) === '-1','kind confusion cannot delete level comment');
    nativeFeatureAssert($call('deleteGJComment20',array_merge($auth,array('commentID'=>'1'))) === '1','owner deletes comment');
    nativeFeatureAssert($call('uploadGJAccountComment20',array_merge($auth,array('comment'=>base64_encode('Profile post')))) === '1','profile post');
    nativeFeatureAssert(strpos($call('getGJAccountComments20',array('accountID'=>'1','binaryVersion'=>'51')),'2~'.base64_encode('Profile post')) === 0,'read profile post');
    nativeFeatureAssert($call('updateGJUserScore22',array_merge($auth,array('stars'=>'123','moons'=>'9','coins'=>'11','icon'=>'4','creator_points'=>'999'))) === '42','score update returns userID');
    $profile = $pdo->query('SELECT * FROM profiles WHERE account_id=1')->fetch();
    nativeFeatureAssert((int)$profile['stars']===123 && (int)$profile['moons']===9 && (int)$profile['creator_points']===0,'score allowlist excludes privilege fields');
    nativeFeatureAssert(strpos($call('getGJUserInfo20',array_merge($auth,array('targetAccountID'=>'2'))),'1:Other:2:77')===0,'authenticated profile read');
    nativeFeatureAssert(strpos($call('getGJLevels21',array_merge($auth,array('type'=>'5','str'=>'42'))),'Public')!==false,'authenticated author search');
    nativeFeatureAssert($call('getGJLevels21',array_merge($auth,array('gjp2'=>'bad','type'=>'0'))) === '-1','viewer credential required');
    $backup = array_merge($auth,array('saveData'=>" binary\0data\n "));
    nativeFeatureAssert($call('backupGJAccount20',$backup,array('enable_accounts'=>true,'enable_writes'=>true))==='-1','cloudsave independent opt-in');
    nativeFeatureAssert($call('backupGJAccount20',$backup)==='1','save to local database');
    nativeFeatureAssert($call('syncGJAccount20',$auth)===$backup['saveData'].';21;30;a;a','sync preserves every byte');
    nativeFeatureAssert($call('syncGJAccount20',array_merge($auth,array('accountID'=>'2')))==='-1','other account cannot read save');
    nativeFeatureAssert($call('backupGJAccount20',array_merge($backup,array('gjp2'=>'bad')))==='-1','bad credential cannot overwrite save');
    nativeFeatureAssert($call('backupGJAccount20',array_merge($auth,array('saveData'=>str_repeat('z',8*1024*1024+1))))==='-1','oversize save rejected');
    nativeFeatureAssert($call('syncGJAccount20',$auth)===$backup['saveData'].';21;30;a;a','failed writes preserve prior save');
    nativeFeatureAssert($call('syncGJAccount20',array('userName'=>'Owner','password'=>'','gjp2'=>$gjp))===$backup['saveData'].';21;30;a;a','username and empty password compatibility');
    $large = str_repeat('z',8*1024*1024-10).';21;30;a;a';
    nativeFeatureAssert($call('backupGJAccount20',array_merge($auth,array('saveData'=>$large)))==='1','8 MiB save fits native bound');
    nativeFeatureAssert($call('syncGJAccount20',$auth)===$large,'large save preserves bytes and avoids duplicate suffix');
    unset($large);
    $pdo->exec("UPDATE php7_cloud_saves SET save_data='corrupted' WHERE account_id=1");
    nativeFeatureAssert($call('syncGJAccount20',$auth)==='-1','storage integrity failure refused');
    $xor = ''; $key = '37526'; $plain = 'NativePass123!';
    for ($i=0;$i<strlen($plain);$i++) { $xor .= $plain[$i]^$key[$i%5]; }
    nativeFeatureAssert(mucho7_authenticate($pdo,1,strtr(base64_encode($xor),'+/','-_')),'legacy GJP authentication');
    require __DIR__ . '/php7-native-features-front.php';
    nativeFeatureFront(array_merge($opts,array('host'=>$host,'port'=>$port,
        'database'=>$database,'username'=>'root','password'=>$password)), $auth);
    $pdo->exec('UPDATE accounts SET is_banned=1 WHERE account_id=1');
    nativeFeatureAssert($call('updateGJUserScore22',$auth)==='-1','banned account score refused');
    nativeFeatureAssert($call('backupGJAccount20',$backup)==='-1','banned account save refused');
    echo "PHP7_NATIVE_REAL_MARIADB_COMMENTS_VOTES_STATS_CLOUDSAVE_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS mucho_php7_features_ci');
}
