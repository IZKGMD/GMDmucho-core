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


require_once __DIR__.'/../../src/Admin/AdminClientInput.php';
use MuchoCore\Admin\AdminClientInput;

if (!str_contains($client, 'AdminClientInput::boundedInteger(')
    || str_contains($client, 'return max($minimum, min($maximum, $input[$key]));')) {
    throw new RuntimeException('Admin client must reject out-of-range numeric fields, never clamp them');
}
foreach ([
    ['input' => ['account_id' => 0], 'key' => 'account_id', 'min' => 1, 'max' => PHP_INT_MAX],
    ['input' => ['account_id' => -1], 'key' => 'account_id', 'min' => 1, 'max' => PHP_INT_MAX],
    ['input' => ['account_id' => '1'], 'key' => 'account_id', 'min' => 1, 'max' => PHP_INT_MAX],
    ['input' => ['account_id' => 1.0], 'key' => 'account_id', 'min' => 1, 'max' => PHP_INT_MAX],
    ['input' => ['stars' => 11], 'key' => 'stars', 'min' => 0, 'max' => 10],
    ['input' => ['stars' => -1], 'key' => 'stars', 'min' => 0, 'max' => 10],
    ['input' => [], 'key' => 'account_id', 'min' => 1, 'max' => PHP_INT_MAX],
] as $case) {
    try {
        AdminClientInput::boundedInteger($case['input'], $case['key'], $case['min'], $case['max']);
        throw new RuntimeException('Invalid numeric field was silently accepted: ' . $case['key']);
    } catch (InvalidArgumentException) {
        // Expected. Invalid account IDs must never resolve to another account.
    }
}
foreach ([
    [['account_id' => 1], 'account_id', 1, PHP_INT_MAX, 1],
    [['account_id' => PHP_INT_MAX], 'account_id', 1, PHP_INT_MAX, PHP_INT_MAX],
    [['stars' => 0], 'stars', 0, 10, 0],
    [['stars' => 10], 'stars', 0, 10, 10],
] as [$input, $key, $min, $max, $expected]) {
    if (AdminClientInput::boundedInteger($input, $key, $min, $max) !== $expected) {
        throw new RuntimeException('Valid numeric field was modified: ' . $key);
    }
}

echo "admin-client-rbac-contract: OK\n";
