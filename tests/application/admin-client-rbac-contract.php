<?php
declare(strict_types=1);

$rbac=(string)file_get_contents(__DIR__.'/../../src/Admin/AdminRbac.php');
$client=(string)file_get_contents(__DIR__.'/../../public/api/v2/admin-client.php');

foreach([
'auth.login'=>'dashboard.view','players.search'=>'players.view','players.get'=>'players.view',
'players.set_ban'=>'players.manage','players.set_role'=>'players.manage',
'players.update_profile'=>'players.manage','levels.search'=>'levels.view',
'levels.update'=>'levels.manage','audit.list'=>'audit.view'
] as $action=>$permission){
    if(!str_contains($rbac,"'".$action."' => '".$permission."'")){
        throw new RuntimeException("RBAC action mapping missing: {$action}");
    }
}
foreach([
'use MuchoCore\\Admin\\AdminRbac;','AdminRbac::permissionForContext',
'AdminRbac::can($db, $admin','AdminRbac::can($db, $loginAdmin','mucho_admin_action'
] as $needle){
    if(!str_contains($client,$needle))throw new RuntimeException("Admin client canonical authorization missing: {$needle}");
}
if(str_contains($client,'if ($rank < $minimumRank)'))throw new RuntimeException('numeric-rank enforcement remains');
$clients=(string)file_get_contents(__DIR__.'/../../public/admin/clients-module.php');

foreach([
    "'zip' => ['file' => 'MuchoGDPS-Client-Pack.zip'",
    "application/zip",
    "MuchoGDPS-Client-Pack.zip",
    "client_file=zip",
    "Unified Client Pack",
] as $needle){
    if(!str_contains($clients,$needle))throw new RuntimeException("Tenant client pack contract missing: {$needle}");
}

echo "admin-client-rbac-contract: OK\n";
