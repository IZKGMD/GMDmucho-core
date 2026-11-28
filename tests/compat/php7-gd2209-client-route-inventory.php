<?php
/**
 * Real Windows Geometry Dash 2.209 x64 PE static URL inventory, 19 Oct 2026.
 * SHA256: 4eee06d5c2e2213b4ba2b1f6233dd426bbdd0e9b29d7c375e499516b6f8f69d5
 *
 * The inventory contains only independently observed endpoint names, NOT
 * any redistributed binary, game code, client secrets, or network captures.
 * 111 native www.boomlings.com/database endpoints + 2 New cloud-save paths
 * + 1 malformed health URL embedded in the same executable = 114 names.
 *
 * IMPORTANT: route presence != working GD 2.209 gameplay/wire acceptance.
 * Browser recovery is a real file/rewritten PHP page, not an API handler.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/compat/php7/domain.php';

function client209RouteCheck($ok, $why) {
    if (!$ok) { throw new RuntimeException($why); }
}
$names = array(
    'acceptGJFriendRequest20.php',
    'accountManagement.php',
    'adminClearReportedContent.php',
    'adminCloseCase.php',
    'adminCommentBan.php',
    'adminCommentUnban.php',
    'adminDeleteUserComments.php',
    'adminFreezeAccount.php',
    'adminGetAbuseInfo.php',
    'adminGetAccountReview.php',
    'adminGetReportQueue.php',
    'adminGetReportedContent.php',
    'adminGetUserHistory.php',
    'adminMessageBan.php',
    'adminMessageUnban.php',
    'adminNameLock.php',
    'adminNameUnlock.php',
    'adminQueueMute.php',
    'adminQueueUnmute.php',
    'adminThreadAction.php',
    'adminTrendingBan.php',
    'adminTrendingUnban.php',
    'adminUnfreezeAccount.php',
    'adminUploadBan.php',
    'adminUploadUnban.php',
    'blockGJUser20.php',
    'claimGJNotificationReward.php',
    'deleteGJAccComment20.php',
    'deleteGJComment20.php',
    'deleteGJComments.php',
    'deleteGJEditorSound.php',
    'deleteGJFriendRequests20.php',
    'deleteGJLevelControls.php',
    'deleteGJLevelList.php',
    'deleteGJLevelUser20.php',
    'deleteGJMessages20.php',
    'downloadGJEditorSound.php',
    'downloadGJLevel22.php',
    'downloadGJMessage20.php',
    'followGJUser.php',
    'getAccountURL.php',
    'getCustomContentURL.php',
    'getGJAccountComments20.php',
    'getGJAudioFilesize.php',
    'getGJChallenges.php',
    'getGJCommentHistory.php',
    'getGJComments21.php',
    'getGJContentStatus.php',
    'getGJDailyLevel.php',
    'getGJDeathData.php',
    'getGJEditorSounds.php',
    'getGJFollowing.php',
    'getGJFriendRequests20.php',
    'getGJGauntlets21.php',
    'getGJLevelControls.php',
    'getGJLevelLists.php',
    'getGJLevelScores211.php',
    'getGJLevelScoresPlat.php',
    'getGJLevels21.php',
    'getGJMapPacks21.php',
    'getGJMessages20.php',
    'getGJNotifications.php',
    'getGJRewards.php',
    'getGJScores20.php',
    'getGJSecretReward.php',
    'getGJSongInfo.php',
    'getGJSongUsage.php',
    'getGJThreadInvites.php',
    'getGJThreadMembers.php',
    'getGJThreadMessages.php',
    'getGJThreads.php',
    'getGJTopArtists.php',
    'getGJUserInfo20.php',
    'getGJUserList20.php',
    'getGJUserSearch.php',
    'getGJUsers20.php',
    'getLevelRateInfo.php',
    'getSaveData.php',
    'likeGJItem211.php',
    'loginGJAccount.php',
    'lostpassword.php',
    'lostusername.php',
    'muteGJUser.php',
    'openedRegister.php',
    'rateGJDemon21.php',
    'rateGJStars211.php',
    'readGJFriendRequest20.php',
    'registerGJAccount.php',
    'removeGJFriend20.php',
    'reportGJContent.php',
    'requestUserAccess.php',
    'searchGJThreadMessages.php',
    'sendGJThreadMessage.php',
    'submitGJUserInfo.php',
    'suggestGJStars20.php',
    'syncGJFollowing.php',
    'unblockGJUser20.php',
    'unfollowGJUser.php',
    'updateGJAccSettings20.php',
    'updateGJDesc20.php',
    'updateGJSoundDesc.php',
    'updateGJThread.php',
    'updateGJUserScore22.php',
    'uploadFriendRequest20.php',
    'uploadGJAccComment20.php',
    'uploadGJComment21.php',
    'uploadGJDeathData.php',
    'uploadGJEditorSound.php',
    'uploadGJLevel21.php',
    'uploadGJLevelControls.php',
    'uploadGJLevelList.php',
    'backupGJAccountNew.php',
    'syncGJAccountNew.php',
    'checkIfServerOnline.php',
);
client209RouteCheck(count($names) === 114, 'Native inventory count changed');
client209RouteCheck(count($names) === count(array_unique($names)), 'Duplicate endpoint names');

$router = new \MuchoCore\Routing\Router();
$routesByRuntime = array();
foreach (array('modern'=>'src/Core/Application.php', 'compiled_php7'=>'compat/php7/core/src/Core/Application.php') as $runtime=>$path) {
    $source = file_get_contents($root . '/' . $path);
    client209RouteCheck(is_string($source), 'Missing app route source: ' . $path);
    preg_match_all('~\$route\s*\(\s*\x27(/[^\x27]+)\x27~', $source, $matches);
    client209RouteCheck(!empty($matches[1]), 'No declared routes: ' . $runtime);
    $registered = array();
    foreach ($matches[1] as $route) {
        $registered[$router->normalizePath($route)] = true;
    }
    $routesByRuntime[$runtime] = $registered;
}

$recovery = array('lostusername.php', 'lostpassword.php');
foreach ($names as $name) {
    if (in_array($name, $recovery, true)) {
        $webPath = $root . '/public/database/accounts/' . $name;
        client209RouteCheck(is_file($webPath), 'Missing native web recovery alias ' . $name);
        client209RouteCheck(strpos(file_get_contents($webPath), 'lostusername.php') !== false,
            'Recovery alias is not delegated to the existing secure controller: ' . $name);
        continue;
    }
    if ($name === 'checkIfServerOnline.php') {
        $request = new \MuchoCore\Http\Request('GET', '/database/' . $name, array(), array(), array());
        client209RouteCheck($router->dispatch($request)->body === '1', 'Malformed native health alias');
        continue;
    }
    $canonical = $router->normalizePath('/database/' . $name);
    foreach ($routesByRuntime as $runtime=>$registered) {
        client209RouteCheck(isset($registered[$canonical]),
            '2.209 EXE route missing in ' . $runtime . ': ' . $name . ' -> ' . $canonical);
    }
}
client209RouteCheck(strpos(file_get_contents($root . '/public/php7.php'), "'/lostusername','/lostpassword'") !== false,
    'Native PHP7 lostusername/lostpassword dispatch removed');
echo 'MUCHOCORE_GD2209_EXE_ROUTE_INVENTORY_OK ' . count($names) . "\n";
