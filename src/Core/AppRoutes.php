<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;

final class AppRoutes
{
    /**
     * @param array<string,mixed> $s
     */
    public static function register(Router $router, array $s): void
    {
        $add = static function (string $path, callable $handler) use ($router): void {
            $router->add('ANY', $path, $handler);
        };

        $add('/health', static fn(Request $r): Response => Response::text('1'));

        $add('/updateGJUserName', [$s['legacy10'], 'updateUsername']);

        $add('/getAccountURL', static fn(Request $r): Response =>
            Response::text($s['url']->accountUrl()));

        $add('/getCustomContentURL', static fn(Request $r): Response =>
            Response::text($s['url']->customContentUrl()));

        $add('/getGJCommentHistory', static fn(Request $r): Response =>
            Response::text($s['commentHistory']->get()));

        $add('/getGJLevelLists', static fn(Request $r): Response =>
            Response::text($s['levelLists']->get()));

        $add('/uploadGJLevelList', static fn(Request $r): Response =>
            Response::text($s['levelLists']->upload()));

        $add('/deleteGJLevelList', static fn(Request $r): Response =>
            Response::text($s['levelLists']->delete()));

        $add('/getGJTopArtists', static fn(Request $r): Response =>
            Response::text($s['artists']->get()));

        $add('/loginGJAccount', [$s['account'], 'login']);
        $add('/registerGJAccount', [$s['account'], 'register']);

        foreach ([
            '/backupGJAccount',
            '/backupGJAccount20',
        ] as $path) {
            $add($path, [$s['cloudSave'], 'backup']);
        }

        foreach ([
            '/syncGJAccount',
            '/syncGJAccount20',
        ] as $path) {
            $add($path, [$s['cloudSave'], 'sync']);
        }

        $add('/getGJLevels21', [$s['levels'], 'list']);

        foreach ([
            '/updateGJLevel' => 'checkUpdate',
            '/uploadGJLevel21' => 'upload',
            '/uploadGJLevel22' => 'upload',
            '/downloadGJLevel21' => 'download',
            '/downloadGJLevel22' => 'download',
            '/deleteGJLevelUser20' => 'delete',
            '/updateGJLevelDesc20' => 'updateDescription',
        ] as $path => $method) {
            $add($path, [$s['levelTransfer'], $method]);
        }

        $add('/getGJSongInfo', [$s['songs'], 'info']);

        $add('/suggestGJStars20', [$s['moderation'], 'suggest']);
        $add('/rateGJStars20', [$s['moderation'], 'rateStars']);
        $add('/rateGJStars211', [$s['moderation'], 'rateStars']);
        $add('/rateGJDemon21', [$s['moderation'], 'rateDemon']);
        $add('/reportGJLevel', [$s['moderation'], 'report']);

        $add('/requestUserAccess', [$s['users'], 'requestAccess']);
        $add('/getGJUserInfo20', [$s['users'], 'profile']);
        $add('/getGJUsers20', [$s['users'], 'search']);
        $add('/getGJScores20', [$s['users'], 'scores']);
        $add('/updateGJAccSettings20', [$s['users'], 'updateSettings']);
        $add('/updateGJUserScore', [$s['users'], 'updateScore']);
        $add('/updateGJUserScore22', [$s['users'], 'updateScore']);

        $add('/getGJComments21', [$s['comments'], 'getLevelComments']);
        $add('/uploadGJComment20', [$s['comments'], 'uploadLevelComment']);
        $add('/uploadGJComment21', [$s['comments'], 'uploadLevelComment']);
        $add('/deleteGJComment20', [$s['comments'], 'deleteComment']);
        $add('/getGJAccountComments20', [$s['comments'], 'getAccountComments']);
        $add('/uploadGJAccComment20', [$s['comments'], 'uploadAccountComment']);
        $add('/deleteGJAccComment20', [$s['comments'], 'deleteAccountComment']);

        foreach ([
            '/likeGJItem21',
            '/likeGJItem211',
        ] as $path) {
            $add($path, [$s['likes'], 'like']);
        }

        foreach ([
            '/getGJMessages20' => 'getMessages',
            '/downloadGJMessage20' => 'readMessage',
            '/uploadGJMessage20' => 'sendMessage',
            '/deleteGJMessages20' => 'deleteMessage',
        ] as $path => $method) {
            $add($path, [$s['messages'], $method]);
        }

        foreach ([
            '/uploadFriendRequest20' => 'send',
            '/getGJFriendRequests20' => 'get',
            '/readGJFriendRequest20' => 'read',
            '/acceptGJFriendRequest20' => 'accept',
            '/deleteGJFriendRequests20' => 'delete',
            '/removeGJFriend20' => 'remove',
            '/blockGJUser20' => 'block',
            '/unblockGJUser20' => 'unblock',
            '/getGJUserList20' => 'userList',
        ] as $path => $method) {
            $add($path, [$s['relationships'], $method]);
        }

        foreach ([
            '/api/clans/create' => 'create',
            '/api/clans/my' => 'myClan',
            '/api/clans/get' => 'get',
            '/api/clans/search' => 'search',
            '/api/clans/join' => 'join',
            '/api/clans/leave' => 'leave',
            '/api/clans/stats' => 'stats',
            '/api/clans/rankings' => 'rankings',
            '/api/clans/permissions' => 'permissions',
            '/api/clans/apply' => 'apply',
            '/api/clans/applications' => 'applications',
            '/api/clans/applications/incoming' => 'clanApplications',
            '/api/clans/application/accept' => 'acceptApplication',
            '/api/clans/application/decline' => 'declineApplication',
            '/api/clans/application/cancel' => 'cancelApplication',
            '/api/clans/invite' => 'invite',
            '/api/clans/invite/accept' => 'acceptInvite',
            '/api/clans/invite/decline' => 'declineInvite',
            '/api/clans/kick' => 'kick',
            '/api/clans/role' => 'setRole',
            '/api/clans/invites' => 'invites',
            '/api/clans/settings' => 'updateSettings',
            '/api/clans/transfer' => 'transferOwnership',
            '/api/clans/disband' => 'disband',
            '/api/clans/delete' => 'delete',
            '/api/clans/invite/revoke' => 'revokeInvite',
            '/api/clans/ban' => 'ban',
            '/api/clans/unban' => 'unban',
            '/api/clans/bans' => 'bans',
        ] as $path => $method) {
            $add($path, [$s['clans'], $method]);
        }

        foreach ([
            '/getGJCreators' => 'creators',
            '/getGJCreators19' => 'creators',
            '/getGJDailyLevel' => 'daily',
            '/getGJGauntlets' => 'gauntlets',
            '/getGJGauntlets21' => 'gauntlets',
            '/getGJMapPacks' => 'mapPacks',
            '/getGJMapPacks20' => 'mapPacks',
            '/getGJMapPacks21' => 'mapPacks',
        ] as $path => $method) {
            $add($path, [$s['discovery'], $method]);
        }

        foreach ([
            '/getGJLevelScores' => 'regular',
            '/getGJLevelScores211' => 'regular',
        ] as $path => $method) {
            $add($path, [$s['levelScores'], $method]);
        }

        $add('/getGJLevelScoresPlat', [$s['platformerScores'], 'handle']);

        foreach ([
            '/getGJRewards' => 'getRewards',
            '/getGJSecretReward' => 'getSecretReward',
            '/getGJChallenges' => 'getChallenges',
        ] as $path => $method) {
            $add($path, [$s['rewards'], $method]);
        }
    }
}
