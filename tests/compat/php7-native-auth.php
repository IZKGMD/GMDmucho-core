<?php
/** PHP 8/SQLite regression suite for PHP7-native GD auth wire/security. */
declare(strict_types=1);
require dirname(__DIR__,2) . '/compat/php7/runtime.php';
require dirname(__DIR__,2) . '/compat/php7/auth.php';
function ck($ok, $label) { if (!$ok) throw new RuntimeException($label); }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,code TEXT UNIQUE)");
$pdo->exec("INSERT INTO roles VALUES(1,'user')");
$pdo->exec("CREATE TABLE accounts(
  account_id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT UNIQUE,email TEXT UNIQUE,
  password_hash TEXT,gjp2_hash TEXT,role_id INTEGER,
  is_active INTEGER DEFAULT 1,is_banned INTEGER DEFAULT 0,
  last_login_at TEXT)");
$pdo->exec("CREATE TABLE profiles(user_id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER UNIQUE)");
$pdo->exec("CREATE TABLE php7_auth_events(id INTEGER PRIMARY KEY,
 action TEXT,ip_hash TEXT,username_hash TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pepper = str_repeat('Z',64);
$ip = '127.0.0.123';
$base = ['userName'=>'TestP7','password'=>'MyStrongPassword_99','email'=>'user@example.invalid'];
ck(mucho7_dispatch($pdo,'/database/registerGJAccount20.php','POST',$base) === '-1',
    'register must be blocked unless explicitly enabled');
$opts = ['enable_accounts'=>true,'enable_registration'=>true,
 'ip'=>$ip,'auth_pepper'=>$pepper];
ck(mucho7_dispatch($pdo,'/database/registerGJAccount20.php','POST',$base,$opts) === '1',
   'register must create account and profile');
ck(mucho7_dispatch($pdo,'/a/accounts/registerGJAccount22.php','POST',
    ['userName'=>'invalid!'], $opts) === '-4',
    'GD 2.209 registration 22 alias must reach validation');
ck((int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn() === 1,
   'register must create the user profile atomically');
ck((int)$pdo->query('SELECT role_id FROM accounts')->fetchColumn() === 1,
   'account must get ordinary role');
ck($pdo->query('SELECT password_hash FROM accounts')->fetchColumn() !== 'MyStrongPassword_99',
   'must not store plaintext password');
$opts['enable_registration'] = false;
ck(mucho7_dispatch($pdo,'/database/registerGJAccount20.php','POST',
    ['userName'=>'Other','password'=>'MyStrongPassword_99','email'=>'other@example.invalid'], $opts) === '-1',
   'registration opt-in must work independently of login');
$login = ['userName'=>'TestP7','password'=>'MyStrongPassword_99'];
$success = mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',$login,$opts);
ck($success === '1,1', 'GD account login must return accountID,userID');
ck(mucho7_dispatch($pdo,'/a/accounts/loginGJAccount22.php','POST',$login,$opts) === '1,1',
    'GD 2.209 nested login 22 alias must authenticate');
$hash = sha1($login['password'] . 'mI29fmAnxgTs');
ck(mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',
    ['userName'=>'TestP7','gjp2'=>$hash],$opts) === '1,1',
   'GJP2 login must work');
ck(mucho7_authenticate($pdo,1,$hash) === true,'GJP2 proof ownership');
ck(mucho7_authenticate($pdo,1,'bad') === false,'invalid credential rejected');
ck(mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',
    ['userName'=>'TestP7','password'=>'Wrong1'], $opts) === '-1',
    'invalid login is rejected');
$pdo->exec('UPDATE accounts SET is_banned=1 WHERE account_id=1');
ck(mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',$login,$opts) === '-1',
   'banned account cannot log in');
$pdo->exec('UPDATE accounts SET is_banned=0, is_active=0 WHERE account_id=1');
ck(mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',$login,$opts) === '-1',
   'inactive account cannot log in');
$pdo->exec('UPDATE accounts SET is_active=1 WHERE account_id=1');
$pdo->exec('DELETE FROM php7_auth_events');
for($i=0;$i<10;$i++){
  mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',
    ['userName'=>'TestP7','password'=>'bad'.$i],$opts);
}
ck(mucho7_dispatch($pdo,'/database/loginGJAccount20.php','POST',$login,$opts) === '-1',
  'lockout must stop brute-force even with correct secret');
ck(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php','POST',
    ['accountID'=>'1','gjp2'=>$hash], $opts) === '-1',
  'write routes still blocked until upload authorization is ported');
echo "PHP7_AUTH_CONTRACT_OK\n";
