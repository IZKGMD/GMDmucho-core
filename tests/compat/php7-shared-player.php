<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/compat/php7/player.php';
require dirname(__DIR__,2).'/compat/php7/core-update.php';
function playerCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
foreach(array('','/mc','/nested/mc') as $mount){
    $GLOBALS['mucho7_domain_mount']=$mount;
    $html='<a href="/dashboard?u=Player">/dashboard</a><form action="/dashboard"><img src="/profile-image.php?account=1"><script src="/assets/product-ui.js"></script><a href="https://example.invalid/dashboard">External</a>';
    $rendered=mucho7_player_html($html);
    playerCheck(strpos($rendered,'href="'.$mount.'/dashboard?u=Player"')!==false,'Dashboard link lost mount.');
    playerCheck(strpos($rendered,'action="'.$mount.'/dashboard"')!==false,'Form lost mount.');
    playerCheck(strpos($rendered,'src="'.$mount.'/public/assets/product-ui.js"')!==false,'Asset lost mount.');
    playerCheck(strpos($rendered,'>/dashboard</a>')!==false&&strpos($rendered,'https://example.invalid/dashboard')!==false,'Non-routing text changed.');
}
foreach(array('core/public/dashboard/index.php','core/public/dashboard/clans.php','core/public/clients.php','core/public/profile-image.php') as $path){
    playerCheck(mucho7_core_pack_path($path),'Portal missing from immutable pack allow-list.');
    playerCheck(is_file(dirname(__DIR__,2).'/compat/php7/'.$path),'Compiled player page missing.');
}
playerCheck(!mucho7_core_pack_path('core/public/dashboard/evil.php'),'Arbitrary public code admitted.');
playerCheck(mucho7_player_route('/getgjlevels21')===null,'Game route shadowed.');
echo "PHP7_SHARED_PLAYER_ROUTES_OK\n";
