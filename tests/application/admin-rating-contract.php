<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Rating Studio contract test.
 */

$root=dirname(__DIR__,2);

$index=file_get_contents($root.'/public/admin/index.php');
$router=file_get_contents($root.'/public/admin/core/AdminRouter.php');
$pages=file_get_contents($root.'/public/admin/config/pages.php');
$rating=file_get_contents($root.'/public/admin/pages/rating.php');

$assert=static function(bool $ok,string $message): void {
    if (!$ok) {
        fwrite(STDERR,"FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
};

$assert(str_contains($pages,"'rating'=>'Rating Studio'"),'rating page registry');
$assert(str_contains($router,"'rating'"),'rating admin route');
$assert(str_contains($router,"../pages/rating.php"),'rating page renderer');
$assert(str_contains($index,'elseif ($action===\'level-rate-save\')'),'level rating action');
$assert(str_contains($index,"requireRank(20);"),'moderator rank gate');
$assert(str_contains($index,"requested_stars=0"),'rating clears pending request');
$assert(str_contains($index,"auto_level=:auto_level"),'rating persists auto level');
$assert(str_contains($index,"UPDATE profiles"),'rating syncs profile statistics');
$assert(str_contains($index,"level.rate"),'rating audit event');
$assert(str_contains($rating,'data-preset="demon"'),'demon quick preset');
$assert(str_contains($rating,"2 => ['Epic', 'Epic']"),'epic feature tier');
$assert(str_contains($rating,"3 => ['Legendary', 'Legendary']"),'legendary feature tier');
$assert(str_contains($rating,"4 => ['Mythic', 'Mythic']"),'mythic feature tier');
$assert(str_contains($rating,'Demon difficulty'),'demon difficulty control');
$assert(str_contains($rating,'Publish rating'),'publish control');

echo "MUCHOCORE_ADMIN_RATING_OK\n";
