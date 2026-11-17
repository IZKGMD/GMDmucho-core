<?php
/**
 * HalogenGDPSCore legacy endpoint NAME coverage for the PHP7 Native port.
 *
 * These are reference route names, not Halogen source code or wire fixtures.
 * Source: FruitSpace/HalogenGDPSCore, master tree at 7051c0ea02af594c93f62795ab09ddc636949b4b
 * License of the reference project: MIT, Copyright (c) 2023 FruitSpace.
 *
 * IMPORTANT: Route registration does not prove response correctness, host
 * compatibility, authentication semantics, or GD 2.209 client acceptance.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/compat/php7/domain.php';

function halogenRouteCheck($condition, $description)
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

$referencePaths = array(
    '/database/acceptGJFriendRequest20.php',
    '/database/blockGJUser20.php',
    '/database/deleteGJAccComment20.php',
    '/database/deleteGJComment20.php',
    '/database/deleteGJFriendRequests20.php',
    '/database/deleteGJLevelUser20.php',
    '/database/deleteGJMessages20.php',
    '/database/downloadGJLevel.php',
    '/database/downloadGJLevel19.php',
    '/database/downloadGJLevel20.php',
    '/database/downloadGJLevel21.php',
    '/database/downloadGJLevel22.php',
    '/database/downloadGJMessage20.php',
    '/database/getAccountURL.php',
    '/database/getGJAccountComments20.php',
    '/database/getGJChallenges.php',
    '/database/getGJCommentHistory.php',
    '/database/getGJComments.php',
    '/database/getGJComments19.php',
    '/database/getGJComments20.php',
    '/database/getGJComments21.php',
    '/database/getGJCreators.php',
    '/database/getGJCreators19.php',
    '/database/getGJDailyLevel.php',
    '/database/getGJFriendRequests20.php',
    '/database/getGJGauntlets.php',
    '/database/getGJGauntlets21.php',
    '/database/getGJLevelScores.php',
    '/database/getGJLevelScores211.php',
    '/database/getGJLevels.php',
    '/database/getGJLevels19.php',
    '/database/getGJLevels20.php',
    '/database/getGJLevels21.php',
    '/database/getGJMapPacks.php',
    '/database/getGJMapPacks20.php',
    '/database/getGJMapPacks21.php',
    '/database/getGJMessages20.php',
    '/database/getGJRewards.php',
    '/database/getGJScores.php',
    '/database/getGJScores19.php',
    '/database/getGJScores20.php',
    '/database/getGJSongInfo.php',
    '/database/getGJTopArtists.php',
    '/database/getGJUserInfo20.php',
    '/database/getGJUserList20.php',
    '/database/getGJUsers20.php',
    '/database/likeGJItem.php',
    '/database/likeGJItem19.php',
    '/database/likeGJItem20.php',
    '/database/likeGJItem21.php',
    '/database/likeGJItem211.php',
    '/database/rateGJDemon21.php',
    '/database/rateGJStars20.php',
    '/database/rateGJStars211.php',
    '/database/readGJFriendRequest20.php',
    '/database/removeGJFriend20.php',
    '/database/reportGJLevel.php',
    '/database/requestUserAccess.php',
    '/database/suggestGJStars20.php',
    '/database/unblockGJUser20.php',
    '/database/updateGJAccSettings20.php',
    '/database/updateGJDesc20.php',
    '/database/updateGJUserScore.php',
    '/database/updateGJUserScore19.php',
    '/database/updateGJUserScore20.php',
    '/database/updateGJUserScore21.php',
    '/database/updateGJUserScore22.php',
    '/database/uploadFriendRequest20.php',
    '/database/uploadGJAccComment20.php',
    '/database/uploadGJComment.php',
    '/database/uploadGJComment19.php',
    '/database/uploadGJComment20.php',
    '/database/uploadGJComment21.php',
    '/database/uploadGJLevel.php',
    '/database/uploadGJLevel19.php',
    '/database/uploadGJLevel20.php',
    '/database/uploadGJLevel21.php',
    '/database/uploadGJMessage20.php',
);
halogenRouteCheck(count($referencePaths) === 78, 'Halogen reference inventory changed unexpectedly');
halogenRouteCheck(count(array_unique($referencePaths)) === count($referencePaths), 'Duplicate reference endpoints');

$router = new \MuchoCore\Routing\Router();
foreach (array('src/Core/Application.php', 'compat/php7/core/src/Core/Application.php') as $applicationPath) {
    $source = file_get_contents($root . '/' . $applicationPath);
    halogenRouteCheck(is_string($source) && $source !== '', 'Application source unavailable: ' . $applicationPath);
    preg_match_all('~\$route\s*\(\s*\x27(/[^\x27]+)\x27~', $source, $registered);
    halogenRouteCheck(!empty($registered[1]), 'Application route declarations missing: ' . $applicationPath);
    $routes = array();
    foreach ($registered[1] as $entry) {
        $routes[$router->normalizePath($entry)] = true;
    }
    foreach ($referencePaths as $original) {
        $normalized = $router->normalizePath($original);
        halogenRouteCheck(isset($routes[$normalized]), 'Legacy route not registered in ' .
            $applicationPath . ': ' . $original . ' -> ' . $normalized);
    }
}

// Compiled PHP7 and original PHP8 aliases must evolve together. Otherwise a
// valid-looking Halogen or GD 2.209 request can route differently on 5v.pl.
function halogenExtractAliases($source)
{
    preg_match_all('~\x27(/[^\x27]+)\x27\s*=>\s*\x27(/[^\x27]+)\x27~', $source, $found, PREG_SET_ORDER);
    $aliases = array();
    foreach ($found as $pair) {
        $aliases[$pair[1]] = $pair[2];
    }
    ksort($aliases);
    return $aliases;
}
$modernRouter = file_get_contents($root . '/src/Routing/Router.php');
$compiledRouter = file_get_contents($root . '/compat/php7/core/src/Routing/Router.php');
halogenRouteCheck(is_string($modernRouter) && is_string($compiledRouter), 'Router source unavailable');
$modernAliases = halogenExtractAliases($modernRouter);
$compiledAliases = halogenExtractAliases($compiledRouter);
halogenRouteCheck(count($modernAliases) >= 70, 'Modern route alias table unexpectedly empty');
halogenRouteCheck($modernAliases === $compiledAliases, 'PHP7 compiled routing aliases diverge from PHP8');

echo 'PHP7_HALOGEN_LEGACY_ROUTE_CONTRACT_OK ' . count($referencePaths) . "\n";
