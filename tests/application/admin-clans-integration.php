<?php
declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use MuchoCore\Admin\ClanAdminService;
use MuchoCore\Clan\ClanRepository;

function clanCheck(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
}
$host=getenv('TEST_DB_HOST');
$port=getenv('TEST_DB_PORT') ?: '3306';
$password=getenv('TEST_DB_ROOT_PASSWORD');
if ($host!=='127.0.0.1' || !is_string($password) || $password==='') {
    throw new RuntimeException('Requires an isolated local/CI MariaDB, never production.');
}
$name='mc_admin_clans_'.bin2hex(random_bytes(6));
$db=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4','root',$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
try {
    $db->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $db->exec('USE '.$name);
    $db->exec("CREATE TABLE accounts(account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(24),is_active TINYINT DEFAULT 1,is_banned TINYINT DEFAULT 0) ENGINE=InnoDB;
        INSERT INTO accounts(account_id,username) VALUES(1,'Owner'),(2,'Member'),(3,'Other')");
    foreach(['002_profiles.php','020_clans.php','20260926_002_clans_v2.php','20261010_001_appearance.php','20261010_002_clan_quests.php'] as $migration) {
        foreach(require __DIR__.'/../../database/migrations/'.$migration as $sql) $db->exec($sql);
    }
    $repo=new ClanRepository($db);
    $clan=$repo->create(1,'Original','ORIG','Description',true,50);
    $id=(int)$clan['clan_id'];
    $repo->join($id,2);
    $repo->join($id,3);
    $apply=static fn(string $action,array $data=[])=>ClanAdminService::apply($db,$action,['id'=>$id]+$data,'QA');
    $save=['name'=>'Updated','tag'=>'edit','description'=>'New description','max_members'=>40];
    $apply('clan-save',$save);
    $row=$repo->getById($id);
    clanCheck($row['name']==='Updated' && $row['tag']==='EDIT' && (int)$row['is_open']===0,'admin edits details and joining policy');
    clanCheck((int)$row['member_count']===3 && (int)$row['max_members']===40,'metadata changes preserve members');
    clanCheck($db->query('SELECT message FROM mucho_clan_events ORDER BY event_id DESC LIMIT 1')->fetchColumn()==='Admin QA updated clan settings.','activity attributes admin edits to the administrator');
    try { $apply('clan-save',array_replace($save,['max_members'=>2])); throw new LogicException('Expected validation failure'); }
    catch(InvalidArgumentException) { clanCheck((int)$repo->getById($id)['max_members']===40,'limit cannot drop below membership'); }
    $apply('clan-role',['account_id'=>2,'role'=>'officer']);
    clanCheck($repo->member($id,2)['role']==='officer','admin promotes a member');
    try { $apply('clan-kick',['account_id'=>1]); throw new LogicException('Expected owner protection'); }
    catch(RuntimeException $e) { clanCheck(!($e instanceof LogicException) && $repo->member($id,1)['role']==='owner','owner cannot be removed'); }
    try { $apply('clan-transfer',['account_id'=>2,'confirm_tag'=>'wrong']); throw new LogicException('Expected confirmation failure'); }
    catch(InvalidArgumentException) { clanCheck((int)$repo->getById($id)['owner_account_id']===1,'transfer requires typed confirmation'); }
    $apply('clan-transfer',['account_id'=>2,'confirm_tag'=>'EDIT']);
    clanCheck((int)$repo->getById($id)['owner_account_id']===2 && $repo->member($id,1)['role']==='officer' && $repo->member($id,2)['role']==='owner','transfer updates clan and both membership roles');
    $apply('clan-kick',['account_id'=>3]);
    clanCheck($repo->member($id,3)===null && (int)$repo->getById($id)['member_count']===2,'removal uses the updated owner and settles quests');
    try { $apply('clan-delete',['confirm_tag'=>'wrong']); throw new LogicException('Expected confirmation failure'); }
    catch(InvalidArgumentException) { clanCheck($repo->getById($id)!==null,'delete requires typed confirmation'); }
    $apply('clan-delete',['confirm_tag'=>'EDIT']);
    clanCheck($repo->getById($id)===null && (int)$db->query('SELECT COUNT(*) FROM mucho_clan_members')->fetchColumn()===0,'delete cascades clan membership cleanup');
    clanCheck(!$db->inTransaction(),'all operations close their transactions');
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('DROP DATABASE IF EXISTS '.$name);
}
echo "MUCHOCORE_ADMIN_CLANS_OK\n";
