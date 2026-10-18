<?php
declare(strict_types=1);

$plain=false;
function adminIsPlain(): bool { global $plain; return $plain; }
function h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function canAdminPage(string $page): bool { return in_array($page,['dashboard','players','admins'],true); }
require __DIR__.'/../../public/admin/core/AdminUi.php';
require __DIR__.'/../../public/admin/core/AdminChrome.php';
function check(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
}
function document(string $html): DOMXPath {
    $doc=new DOMDocument();
    $old=libxml_use_internal_errors(true);
    $doc->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
    libxml_clear_errors();libxml_use_internal_errors($old);
    return new DOMXPath($doc);
}
$query=['page'=>'players','q'=>'Игрок & Name','filter'=>'active','p'=>2,'id'=>42,'tab'=>'history','redirect'=>'https://example.test','logout'=>1];
foreach([false,true] as $plain) {
    ob_start();
    adminBrand('Server <script>');adminInterfaceSwitch($query);
    $html=(string)ob_get_clean();$xpath=document($html);
    check($xpath->query('//script')->length===0,'brand name is escaped');
    check($xpath->query('//img[@width="48" and @height="48" and @alt="MuchoCore"]')->length===1,'both modes use the same square identity');
    check($xpath->query('//*[contains(@class,"admin-brand-copy")]/br')->length===1,'identity remains separated without CSS');
    check($xpath->query('//nav[@aria-label="Admin interface"]/a[@aria-current="true"]')->length===1,'exactly one interface is current');
    foreach($xpath->query('//nav/a') as $link) {
        parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
        check($params['q']===$query['q'] && $params['p']==='2' && $params['id']==='42' && $params['tab']==='history','switch preserves open list, record and tool tab');
        check(!array_key_exists('logout',$params) && !array_key_exists('redirect',$params),'switch drops action and redirect parameters');
    }
    $pages=require __DIR__.'/../../public/admin/config/pages.php';$page='players';
    ob_start();require __DIR__.'/../../public/admin/core/navigation.php';$nav=(string)ob_get_clean();
    $xpath=document($nav);
    check($xpath->query('//a[contains(@href,"page=clans") or contains(@href,"page=settings")]')->length===0,'navigation respects permissions in both modes');
    check($xpath->query('//li/a[@aria-current="page"]')->length===1,'navigation identifies the current section');
    check($xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " admin-nav-extra ")]//details[contains(concat(" ", normalize-space(@class), " "), " admin-nav-group ")]/summary')->length===1,'secondary sections are grouped even for restricted roles');
    check($xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " admin-nav-group ")]/ul/li/a[contains(@href,"page=admins")]')->length===1,'allowed administration section remains accessible');
    check($xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " admin-nav-group ")]/ul/li/a[contains(@href,"page=settings")]')->length===0,'groups do not leak inaccessible pages');
    if($plain) check($xpath->query('//img')->length===0,'native menu avoids decorative icon clutter');
}
$page='admins';
ob_start();
require __DIR__.'/../../public/admin/core/navigation.php';
$adminNav=document((string)ob_get_clean());
check($adminNav->query('//details[contains(@class,"admin-nav-extra") and @open]')->length===1,
    'more tools expands automatically for the active secondary page');
check($adminNav->query('//details[contains(@class,"admin-nav-group") and @open]//a[@aria-current="page"]')->length===1,
    'the active category expands automatically and highlights its current page');
$expectedGroups=adminExtraGroups();
check(count($expectedGroups) >= 4 && in_array('clientpatcher',$expectedGroups['Clients & extensions'],true),
    'all operator tool categories have stable labels');

check(!str_contains(adminInterfaceUrl(true,['q'=>['invalid']]),'Array'),'switch ignores non-scalar query values');
// Fresh-install pages must render before upload tables or backup constants exist.
function admin(): array { return ['id'=>1,'username'=>'Fixture','role'=>'owner']; }
function rank(string $role): int { return $role==='owner'?40:0; }
function csrf(): string { return 'fixture-csrf'; }
function tableExists(PDO $db,string $table): bool { return false; }
$_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/../../public/admin/db-backup-center-module.php';
require __DIR__.'/../../public/admin/client-release-upload-module.php';
$db=new PDO('sqlite::memory:');
$backupDir=sys_get_temp_dir().'/mc_admin_empty_'.bin2hex(random_bytes(6));
mkdir($backupDir,0700);
putenv('MUCHO_DB_BACKUP_DIR='.$backupDir);
try {
    ob_start();renderDbBackupCenter($db);$backupHtml=(string)ob_get_clean();
    check(str_contains($backupHtml,'DB Backup Center'),'backup page renders without a legacy directory constant');
    ob_start();renderClientReleaseUploader($db);$uploadHtml=(string)ob_get_clean();
    check(str_contains($uploadHtml,'muchoApkUpload'),'release page renders before the first upload initializes tables');
} finally {
    putenv('MUCHO_DB_BACKUP_DIR');
    rmdir($backupDir);
}
echo "MUCHOCORE_ADMIN_CHROME_OK\n";
