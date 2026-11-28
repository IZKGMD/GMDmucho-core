<?php
/**
 * Isolated native GD 2.209 thread privacy regression on PHP 7.0 and 8.3.
 * No production databases, credentials or live GD clients are used.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/compat/php7/domain.php';

function threadPrivacyCheck($ok, $why) {
    if (!$ok) throw new RuntimeException('Native 2.209 threads: ' . $why);
}
$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === ''
    || !ctype_digit((string)$port)) {
    throw new RuntimeException('Thread regression requires isolated CI MariaDB.');
}
$connect = function($database) use($host, $password, $port) {
    return new PDO('mysql:host=' . $host . ';port=' . $port
        . ($database === null ? '' : ';dbname=' . $database)
        . ';charset=utf8mb4', 'root', $password,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
};
$name = 'mc_thread_privacy_' . bin2hex(random_bytes(5));
$server = $connect(null);
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0,
        messages_state TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE,
        stars INT NOT NULL DEFAULT 0,
        cube INT NOT NULL DEFAULT 1,
        color1 INT NOT NULL DEFAULT 0,
        color2 INT NOT NULL DEFAULT 3,
        special INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE blocks (
        account_id BIGINT UNSIGNED NOT NULL,
        blocked_account_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY(account_id,blocked_account_id)
    ) ENGINE=InnoDB');
    foreach (require $root . '/compat/php7/core/database/migrations/20261016_005_gd2209_threads.php'
        as $sql) { $db->exec($sql); }
    foreach (require $root . '/compat/php7/core/database/migrations/20261018_004_gd2209_native_threads.php'
        as $sql) { $db->exec($sql); }
    $add = $db->prepare('INSERT INTO accounts(
        account_id,username,password_hash,gjp2_hash) VALUES(?,?,?,?)');
    foreach (array(1=>'Owner',2=>'Member',3=>'Invitee') as $id=>$username) {
        $add->execute(array($id,$username,
            password_hash('pw-'.$id,PASSWORD_BCRYPT),
            password_hash('gjp-'.$id,PASSWORD_BCRYPT)));
        $db->prepare('INSERT INTO profiles(account_id) VALUES(?)')
            ->execute(array($id));
    }
    $auth = new \MuchoCore\Account\AccountAuthenticator($db);
    $threads = new \MuchoCore\Social\ThreadService($db, $auth);
    $actions = new \MuchoCore\Social\Gd2209ThreadActionService($db, $auth);
    $thread = $threads->create(1, 'pw-1', 'Private test');
    threadPrivacyCheck($thread > 0 && $threads->invite(1,'pw-1',$thread,2),
        'owner can create and invite a real member');
    $pending = $threads->invites(2,'pw-2');
    threadPrivacyCheck(count($pending) === 1
        && $threads->respondInvite(2,'pw-2',(int)$pending[0]['id'],true),
        'invited user explicitly joins the group');
    $id2 = $threads->sendWithId(2,'pw-2',$thread,'  padded binary text  ');
    threadPrivacyCheck($id2 > 0 &&
        $db->query('SELECT body FROM mucho_thread_messages WHERE id='.$id2)->fetchColumn()
            === '  padded binary text  ',
        'server must persist exact native message body, including spaces');
    $id1 = $threads->sendWithId(1,'pw-1',$thread,'owner announcement');
    threadPrivacyCheck($id1 > 0
        && (int)$threads->nativeThreads(1,'pw-1',0,2)['rows'][0]['unread_count'] === 1
        && (int)$threads->nativeThreads(2,'pw-2',0,2)['rows'][0]['unread_count'] === 1,
        'unread counters ignore own messages and count peer messages');
    threadPrivacyCheck($actions->change(1,'pw-1',0,3,(string)$thread),
        'native mark-read succeeds on an authenticated active thread');
    threadPrivacyCheck((int)$threads->nativeThreads(1,'pw-1',0,2)['rows'][0]['unread_count'] === 0,
        'mark-read removes unread badge');
    threadPrivacyCheck($actions->change(1,'pw-1',$thread,1,''),
        'clear-history only applies to requesting account');
    threadPrivacyCheck($threads->messages(1,'pw-1',$thread,0) === array()
        && $threads->search(1,'pw-1',$thread,'padded',0) === array()
        && $threads->nativeSnapshot(1,'pw-1',$thread)['messages'] === array(),
        'legacy and native read/search cannot resurrect cleared history');
    threadPrivacyCheck(count($threads->messages(2,'pw-2',$thread,0)) === 2,
        'peer still sees all messages after requester clears history');

    $after = $threads->sendWithId(2,'pw-2',$thread,'new data after clear');
    threadPrivacyCheck($after > 0
        && count($threads->messages(1,'pw-1',$thread,0)) === 1
        && count($threads->search(1,'pw-1',$thread,'new data',0)) === 1
        && (int)$threads->nativeThreads(1,'pw-1',0,2)['rows'][0]['unread_count'] === 1,
        'post-clear messages are visible and counted without older history');

    threadPrivacyCheck($threads->invite(1,'pw-1',$thread,3),
        'create pending invitation for a legacy invitee');
    $db->exec('DELETE FROM profiles WHERE account_id IN (2,3)');
    $snapshot = $threads->nativeSnapshot(1,'pw-1',$thread);
    $view = $threads->nativeMemberView(1,'pw-1',$thread);
    threadPrivacyCheck(count($snapshot['members']) === 2
        && count($view['members']) === 2
        && count($threads->members(1,'pw-1',$thread)) === 2,
        'missing legacy profile must not erase active group member');
    threadPrivacyCheck(count($snapshot['invites']) === 1
        && count($view['invites']) === 1
        && (int)$snapshot['invites'][0]['invitee_account_id'] === 3,
        'missing legacy profile must not erase pending invite');
    $wire = \MuchoCore\Protocol\Gd2209ThreadWire::invites($snapshot['invites']);
    threadPrivacyCheck(strpos($wire,'3:3:'.base64_encode('Invitee')) === 0,
        'native invite encoder falls back to account ID for missing user ID');

    // Keep the authenticating invitee's profile, but remove the inviter's
    // to verify that inbox invites still have an owner/user row.
    $db->exec('INSERT INTO profiles(account_id) VALUES(3)');
    $db->exec('DELETE FROM profiles WHERE account_id=1');
    threadPrivacyCheck(count($threads->invites(3,'pw-3')) === 1,
        'inbox invite survives inviter missing profile');
    echo "MUCHOCORE_PHP7_NATIVE_GD2209_THREAD_PRIVACY_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS '.$name);
}
