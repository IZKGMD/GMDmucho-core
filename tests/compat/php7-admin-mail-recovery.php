<?php
/** Standalone PHP 7 native mail recovery test; no real email is sent. */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (array('bootstrap','runtime','schema','auth','news','admin') as $module) {
    require_once $root.'/compat/php7/'.$module.'.php';
}

class MailFixturePDO extends PDO
{
    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = array())
    {
        return parent::prepare($this->convert($sql), $options);
    }
    #[\ReturnTypeWillChange]
    public function query($sql, ...$args)
    {
        return parent::query($this->convert($sql), ...$args);
    }
    private function convert($sql)
    {
        $sql = str_replace(' FOR UPDATE', '', $sql);
        $sql = preg_replace(
            '/DATE_ADD\(UTC_TIMESTAMP\(\),INTERVAL (\d+) MINUTE\)/',
            "datetime('now','+$1 minutes')", $sql
        );
        $sql = preg_replace(
            '/DATE_SUB\(UTC_TIMESTAMP\(\),INTERVAL (\d+) MINUTE\)/',
            "datetime('now','-$1 minutes')", $sql
        );
        $sql = str_replace('UTC_TIMESTAMP()', "datetime('now')", $sql);
        $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(admin_id) DO UPDATE SET', $sql);
        $sql = preg_replace('/VALUES\(([a-z_]+)\)/', 'excluded.$1', $sql);
        return $sql;
    }
}

function mailAssert($ok, $message)
{
    if (!$ok) throw new RuntimeException('FAIL PHP7 mail recovery: '.$message);
    echo 'PASS '.$message.PHP_EOL;
}

$db = new MailFixturePDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("
CREATE TABLE accounts(account_id INTEGER PRIMARY KEY,email TEXT,username TEXT,
    password_hash TEXT,gjp2_hash TEXT,is_active INTEGER DEFAULT 1,is_banned INTEGER DEFAULT 0);
CREATE TABLE php7_admin_users(id INTEGER PRIMARY KEY,account_id INTEGER,username TEXT,
    password_hash TEXT,role TEXT,is_active INTEGER DEFAULT 1,password_must_set INTEGER DEFAULT 0);
CREATE TABLE php7_admin_mail_recovery(admin_id INTEGER PRIMARY KEY,
    verified_email TEXT UNIQUE,verified_at TEXT,pending_email TEXT,
    verify_token_hash TEXT UNIQUE,verify_expires_at TEXT,verify_sent_at TEXT,
    reset_token_hash TEXT UNIQUE,reset_expires_at TEXT,reset_sent_at TEXT);
CREATE TABLE php7_auth_events(id INTEGER PRIMARY KEY AUTOINCREMENT,action TEXT,ip_hash TEXT,
    username_hash TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE php7_admin_security(account_id INTEGER PRIMARY KEY,auth_version INTEGER DEFAULT 0);
CREATE TABLE php7_admin_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,
    action TEXT,target_id INTEGER,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
");
$old = password_hash('old-admin-password', PASSWORD_BCRYPT, array('cost'=>4));
$db->prepare('INSERT INTO accounts(account_id,email,username,password_hash,gjp2_hash)
    VALUES(1,?,?,?,?)')->execute(array('owner@example.test','Owner',$old,$old));
$db->prepare("INSERT INTO php7_admin_users(id,account_id,username,role)
    VALUES(1,1,'Owner','owner')")->execute();
$db->prepare("INSERT INTO php7_admin_security(account_id,auth_version) VALUES(1,4)")->execute();

$cfg = array('site_url'=>'https://test.5v.pl','auth_pepper'=>str_repeat('p',64));
$emails = array();
$sender = function($to,$subject,$body,$headers) use (&$emails) {
    $emails[] = array($to,$subject,$body,$headers);
    return true;
};
mailAssert(mucho7_admin_mail_origin($cfg)==='https://test.5v.pl','trusted site origin');
mailAssert(mucho7_admin_mail_normal_email(" Test@Example.org\r\nBCC: victim@example.org")===null,
    'email header injection blocked');
mailAssert(mucho7_admin_mail_origin(array('site_url'=>'https://attacker.invalid/evil'))===null,
    'origin path injection blocked');
mailAssert(mucho7_admin_mail_link($cfg,'/mc/admin.php','email_verify',str_repeat('a',64))
    ==='https://test.5v.pl/mc/admin.php?email_verify='.str_repeat('a',64),'secure link format');

mailAssert(mucho7_admin_mail_request_verify($db,1,' Owner@Example.TEST ','192.0.2.10',
    $cfg,'/mc/admin.php',$sender),'verification relay accepted');
mailAssert(count($emails)===1 && $emails[0][0]==='owner@example.test'
    && strpos($emails[0][3],'no-reply@test.5v.pl')!==false,
    'queued mail uses verified local sender and normalized recipient');
preg_match('/email_verify=([a-f0-9]{64})/', $emails[0][2], $m);
$verify = isset($m[1])?$m[1]:'';
$hash = $db->query('SELECT verify_token_hash FROM php7_admin_mail_recovery WHERE admin_id=1')->fetchColumn();
mailAssert(strlen($verify)===64 && $hash===hash('sha256',$verify),
    'only hashed verification token is stored');
mailAssert(mucho7_admin_mail_status($db,1)['verified_email']===null,
    'relay acceptance is not email verification');
$db->exec("UPDATE php7_admin_mail_recovery SET verify_expires_at=datetime('now','-1 hour') WHERE admin_id=1");
mailAssert(!mucho7_admin_mail_confirm($db,$verify),'expired verification token rejected');
$db->exec("UPDATE php7_admin_mail_recovery SET verify_expires_at=datetime('now','+30 minutes') WHERE admin_id=1");
mailAssert(mucho7_admin_mail_confirm($db,$verify),'one-time email confirmation');
mailAssert(!mucho7_admin_mail_confirm($db,$verify),'verification replay rejected');
mailAssert(mucho7_admin_mail_status($db,1)['verified_email']==='owner@example.test',
    'email becomes verified only after confirmation');

mucho7_admin_mail_request_reset($db,'owner@example.test','192.0.2.11',
    $cfg,'/mc/admin.php',$sender);
mailAssert(count($emails)===2,'verified owner receives reset message');
preg_match('/email_reset=([a-f0-9]{64})/', $emails[1][2], $m);
$reset = isset($m[1])?$m[1]:'';
mailAssert(strlen($reset)===64,'reset link token generated');
mailAssert(!mucho7_admin_mail_reset_password($db,$reset,'new-valid-password',
    'mismatched','192.0.2.12',$cfg),'password mismatch rejected');
$db->exec("UPDATE php7_admin_mail_recovery SET reset_expires_at=datetime('now','-1 hour') WHERE admin_id=1");
mailAssert(!mucho7_admin_mail_reset_password($db,$reset,'new-valid-password',
    'new-valid-password','192.0.2.16',$cfg),'expired password reset token rejected');
$db->exec("UPDATE php7_admin_mail_recovery SET reset_expires_at=datetime('now','+20 minutes') WHERE admin_id=1");
mailAssert(mucho7_admin_mail_reset_password($db,$reset,'new-valid-password',
    'new-valid-password','192.0.2.13',$cfg),'valid one-time password reset');
mailAssert(!mucho7_admin_mail_reset_password($db,$reset,'other-valid-password',
    'other-valid-password','192.0.2.14',$cfg),'reset replay rejected');
$u = $db->query('SELECT password_hash,gjp2_hash FROM accounts WHERE account_id=1')->fetch(PDO::FETCH_ASSOC);
mailAssert(password_verify('new-valid-password',$u['password_hash']) &&
    password_verify(sha1('new-valid-password'.'mI29fmAnxgTs'),$u['gjp2_hash']),
    'linked owner game and admin credentials updated together');
mailAssert((int)$db->query('SELECT auth_version FROM php7_admin_security WHERE account_id=1')
    ->fetchColumn()===5,'old sessions invalidated via auth_version');
mailAssert(mucho7_admin_mail_status($db,1)['verified_email']==='owner@example.test',
    'verified address survives successful reset');
$mailCount=count($emails);
mucho7_admin_mail_request_reset($db,'unknown@example.test','192.0.2.15',
    $cfg,'/mc/admin.php',$sender);
mailAssert(count($emails)===$mailCount,'unknown email is not mailed');
mailAssert(!mucho7_admin_mail_valid_token('bad'),'invalid tokens rejected');
echo "MUCHOCORE_PHP7_ADMIN_MAIL_RECOVERY_OK\n";
