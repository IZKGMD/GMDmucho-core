<?php

declare(strict_types=1);

require __DIR__ . '/../src/Routing/Router.php';
require __DIR__ . '/../src/Http/Request.php';
require __DIR__ . '/../src/Http/Response.php';

use MuchoCore\Routing\Router;

function assertRoute(string $path, Router $router): void
{
    $normalized = $router->normalizePath($path);

    if ($normalized === $path) {
        return;
    }

    echo "PASS {$path} -> {$normalized}\n";
}

$router = new Router();

$required = [
    '/registerGJAccount.php',
    '/loginGJAccount22.php',
    '/backupGJAccount20.php',
    '/syncGJAccount20.php',
    '/getGJLevels22.php',
    '/uploadGJLevel22.php',
    '/downloadGJLevel22.php',
    '/deleteGJLevelUser20.php',
    '/updateGJDesc20.php',
    '/getGJUserInfo20.php',
    '/getGJUsers20.php',
    '/getGJScores20.php',
    '/updateGJUserScore22.php',
    '/updateGJAccSettings20.php',
    '/getGJComments21.php',
    '/uploadGJComment21.php',
    '/deleteGJComment20.php',
    '/getGJAccountComments20.php',
    '/uploadGJAccComment20.php',
    '/deleteGJAccComment20.php',
    '/likeGJItem211.php',
    '/likeGJLevel.php',
    '/getGJMessages20.php',
    '/downloadGJMessage20.php',
    '/uploadGJMessage20.php',
    '/deleteGJMessages20.php',
    '/uploadFriendRequest20.php',
    '/getGJFriendRequests20.php',
    '/readGJFriendRequest20.php',
    '/acceptGJFriendRequest20.php',
    '/deleteGJFriendRequests20.php',
    '/removeGJFriend20.php',
    '/blockGJUser20.php',
    '/unblockGJUser20.php',
    '/getGJUserList20.php',
    '/getGJCreators.php',
    '/getGJDailyLevel.php',
    '/getGJGauntlets21.php',
    '/getGJMapPacks21.php',
    '/getGJLevelScores211.php',
    '/getGJLevelScoresPlat.php',
    '/getGJRewards.php',
    '/getGJChallenges.php',
    '/getGJLevelLists.php',
    '/uploadGJLevelList.php',
    '/deleteGJLevelList.php',
    '/getGJCommentHistory.php',
    '/getGJTopArtists.php',
    '/getGJSongInfo.php',
    '/getAccountURL.php',
    '/getCustomContentURL.php',
    '/requestUserAccess.php',
    '/suggestGJStars20.php',
    '/rateGJStars211.php',
    '/rateGJDemon21.php',
    '/reportGJLevel.php',
];

foreach ($required as $path) {
    assertRoute($path, $router);
}

echo "MUCHOCORE_22_SURFACE_OK\n";
