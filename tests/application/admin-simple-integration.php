<?php
declare(strict_types=1);
require __DIR__.'/../../public/admin/core/AdminUi.php';
require __DIR__.'/../../src/Admin/LevelAdminService.php';

use MuchoCore\Admin\LevelAdminService;

function check(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrf(): string { return 'fixture-csrf'; }
$permissions=['players.manage','players.password_reset','players.delete','levels.manage','levels.rate','music.manage'];
function canPermission(string $permission): bool { global $permissions; return in_array($permission,$permissions,true); }
function tableExists(PDO $db,string $table): bool { return true; }
function renderPage(PDO $db,string $page,array $query=[]): string {
    $_GET=$query;
    ob_start();
    require __DIR__.'/../../public/admin/pages/'.match($page) {
        'levels','moderation'=>'levels-moderation',default=>$page,
    }.'.php';
    return (string)ob_get_clean();
}
function document(string $html): DOMXPath {
    $doc=new DOMDocument();
    $before=libxml_use_internal_errors(true);
    $doc->loadHTML('<!doctype html><html><body>'.$html.'</body></html>');
    libxml_clear_errors();libxml_use_internal_errors($before);
    return new DOMXPath($doc);
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,code TEXT,priority INTEGER);
    INSERT INTO roles VALUES(1,'user',0);
    CREATE TABLE accounts(account_id INTEGER PRIMARY KEY,username TEXT,email TEXT,role_id INTEGER,is_active INTEGER,is_banned INTEGER,created_at TEXT);
    CREATE TABLE profiles(account_id INTEGER PRIMARY KEY,stars INTEGER DEFAULT 0,moons INTEGER DEFAULT 0,diamonds INTEGER DEFAULT 0,secret_coins INTEGER DEFAULT 0,user_coins INTEGER DEFAULT 0,demons INTEGER DEFAULT 0,creator_points INTEGER DEFAULT 0);
    CREATE TABLE levels(level_id INTEGER PRIMARY KEY,account_id INTEGER,name TEXT,stars INTEGER,difficulty INTEGER,demon INTEGER,demon_difficulty INTEGER,auto_level INTEGER,featured INTEGER,epic INTEGER,requested_stars INTEGER,downloads INTEGER,likes INTEGER,is_deleted INTEGER);
    CREATE TABLE songs(id INTEGER PRIMARY KEY,name TEXT,author_name TEXT,size REAL,is_verified INTEGER);
    INSERT INTO songs VALUES(1,'Pending song','Artist',3.5,0),(2,'Published song','Other artist',2,1)");
$insert=$db->prepare('INSERT INTO accounts VALUES(:id,:name,:email,1,1,:banned,CURRENT_TIMESTAMP)');
for($i=1;$i<=27;$i++) {
    $insert->execute(['id'=>$i,'name'=>'Player'.$i,'email'=>'person'.$i.'@example.test','banned'=>$i===2?1:0]);
    $db->exec('INSERT INTO profiles(account_id,stars) VALUES('.$i.',10)');
}
$db->exec("INSERT INTO levels VALUES(1,1,'Rated level',10,6,1,5,0,1,3,4,250,90,0),(2,1,'Unrated',0,0,0,0,0,0,0,6,12,4,0)");
$html=renderPage($db,'players');$xpath=document($html);
check($xpath->query('//details[contains(@class,"admin-record")]')->length===25,'players list has a bounded first page');
check(str_contains($html,'p=2') && !str_contains($html,'player-2"'),'first page offers the next page without duplicated records');
$html=renderPage($db,'players',['p'=>2]);
check(document($html)->query('//details[contains(@class,"admin-record")]')->length===2,'second page returns remaining players');
$html=renderPage($db,'players',['q'=>'person2@example.test','filter'=>'banned','p'=>1]);
check(str_contains($html,'player-2"') && !str_contains($html,'player-27"'),'email search and ban filter work together');
check(str_contains($html,'name="return_q" value="person2@example.test"'),'player POST forms retain list search');
$xpath=document($html);
check($xpath->query('//form[@method="post"][not(input[@name="csrf"]) or not(input[@name="id"])]')->length===0,'every player mutation has CSRF and target fields');
$permissions=[];
$html=renderPage($db,'players',['q'=>'Player2']);
check(!str_contains($html,'method="post"'),'read-only players list exposes no mutation controls');
$html=renderPage($db,'levels');
check(!str_contains($html,'method="post"') && !str_contains($html,'Publish rating'),'read-only level access exposes no edit or rating action');
$permissions=['players.manage','levels.manage','levels.rate','music.manage'];
$html=renderPage($db,'moderation',['q'=>'Rated level']);
check(str_contains($html,'level-1"') && !str_contains($html,'level-2"'),'moderation queue combines search with requested-star filtering');
check(str_contains($html,'/gd/extreme-demon.png'),'levels use original GD difficulty sprites');
$xpath=document($html);
check($xpath->query('//form[@method="post"][not(input[@name="id"]) or not(input[@name="csrf"])]')->length===0,'level forms keep their target and CSRF inside the form');
$before=$db->query('SELECT * FROM levels WHERE level_id=1')->fetch(PDO::FETCH_ASSOC);
LevelAdminService::saveDetails($db,1,'Renamed',true);
$after=$db->query('SELECT * FROM levels WHERE level_id=1')->fetch(PDO::FETCH_ASSOC);
foreach(['stars','difficulty','demon','demon_difficulty','auto_level','featured','epic','requested_stars','downloads','likes'] as $field) {
    check($before[$field]===$after[$field],'metadata edit preserves '.$field);
}
check((int)$db->query('SELECT creator_points FROM profiles WHERE account_id=1')->fetchColumn()===0,'hiding a level recalculates creator points');
LevelAdminService::saveDetails($db,1,'Restored',false);
check((int)$db->query('SELECT creator_points FROM profiles WHERE account_id=1')->fetchColumn()===5,'restoring a level restores creator points');
try { LevelAdminService::saveDetails($db,999,'Missing',true); check(false,'missing level must fail'); }
catch(RuntimeException $e) { check(!$db->inTransaction(),'failed level update closes its transaction'); }
try { LevelAdminService::saveDetails($db,1,' ',false); check(false,'empty level name must fail'); }
catch(RuntimeException $e) { check($db->query('SELECT name FROM levels WHERE level_id=1')->fetchColumn()==='Restored','invalid name leaves the level unchanged'); }
$html=renderPage($db,'songs',['filter'=>'pending']);
check(str_contains($html,'Pending song') && !str_contains($html,'Published song'),'pending-music shortcut filters the actual queue');
$html=renderPage($db,'songs',['q'=>'Other artist','filter'=>'verified']);
check(str_contains($html,'Published song') && !str_contains($html,'Pending song'),'music searches artists and respects verification filter');
check(adminListState(['p'=>-2])['p']===1,'pagination rejects negative offsets');
check(!str_contains(adminListUrl('players',adminListState(['q'=>'<&','redirect'=>'https://example.test']),2),'redirect'),'list URLs keep only known parameters');
echo "MUCHOCORE_ADMIN_SIMPLE_OK\n";
