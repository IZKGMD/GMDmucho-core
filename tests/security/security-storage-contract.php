<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/src/Security/RateLimitBackend.php';
require dirname(__DIR__,2).'/src/Security/PenaltyStoreBackend.php';
require dirname(__DIR__,2).'/src/Security/RateLimiter.php';
require dirname(__DIR__,2).'/src/Security/AbusePenaltyStore.php';
require dirname(__DIR__,2).'/src/Security/DatabaseRateLimiter.php';
require dirname(__DIR__,2).'/src/Security/DatabasePenaltyStore.php';

use MuchoCore\Security\AbusePenaltyStore;
use MuchoCore\Security\DatabasePenaltyStore;
use MuchoCore\Security\DatabaseRateLimiter;
use MuchoCore\Security\PenaltyStoreBackend;
use MuchoCore\Security\RateLimitBackend;
use MuchoCore\Security\RateLimiter;

foreach([
 [RateLimiter::class,RateLimitBackend::class],
 [AbusePenaltyStore::class,PenaltyStoreBackend::class],
 [DatabaseRateLimiter::class,RateLimitBackend::class],
 [DatabasePenaltyStore::class,PenaltyStoreBackend::class],
] as [$impl,$iface]){
    if(!is_subclass_of($impl,$iface,true))throw new RuntimeException("Backend contract failed for {$impl}");
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/src/Security/MuchoProtect.php');
foreach(['MUCHO_PROTECT_STORAGE','DatabaseRateLimiter','DatabasePenaltyStore','storageMode(): string'] as $needle){
    if(!str_contains($source,$needle))throw new RuntimeException("Storage selection missing: {$needle}");
}
echo "security-storage-contract: OK\n";
