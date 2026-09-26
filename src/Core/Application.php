<?php
declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountController;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Account\AccountService;
use MuchoCore\Artist\TopArtistController;
use MuchoCore\Artist\TopArtistRepository;
use MuchoCore\Artist\TopArtistService;
use MuchoCore\Comment\CommentHistoryController;
use MuchoCore\Comment\CommentHistoryRepository;
use MuchoCore\Comment\CommentHistoryService;
use MuchoCore\LevelList\LevelListController;
use MuchoCore\LevelList\LevelListRepository;
use MuchoCore\LevelList\LevelListService;
use MuchoCore\Url\UrlController;
use MuchoCore\Compatibility\DiscoveryController;
use MuchoCore\Compatibility\Legacy10IdentityController;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\CloudSave\CloudSaveService;
use MuchoCore\Clan\ClanController;
use MuchoCore\Clan\ClanRepository;
use MuchoCore\Clan\ClanService;
use MuchoCore\Compatibility\CompatibilityProfile;
use MuchoCore\CloudSave\CloudSaveRepository;
use MuchoCore\CloudSave\CloudSaveController;
use MuchoCore\Database\Database;
use MuchoCore\Diagnostics\ClientTrace;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Interaction\CommentController;
use MuchoCore\Interaction\CommentRepository;
use MuchoCore\Interaction\CommentService;
use MuchoCore\Interaction\LikeController;
use MuchoCore\Interaction\LikeRepository;
use MuchoCore\Interaction\LikeService;
use MuchoCore\Interaction\RewardsController;
use MuchoCore\Level\LevelController;
use MuchoCore\Level\LevelRepository;
use MuchoCore\Level\LevelService;
use MuchoCore\Level\LevelTransferController;
use MuchoCore\Level\LevelTransferRepository;
use MuchoCore\Level\LevelTransferService;
use MuchoCore\Moderation\ModerationController;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Moderation\ModerationService;
use MuchoCore\Music\SongController;
use MuchoCore\Music\SongRepository;
use MuchoCore\Music\SongService;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use MuchoCore\Protocol\GdLevelListEncoder;
use MuchoCore\Protocol\GdMessageEncoder;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Protocol\GdUserEncoder;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;
use MuchoCore\Score\LevelScoreController;
use MuchoCore\Score\PlatformerScoreController;
use MuchoCore\Security\MuchoProtect;
use MuchoCore\Social\MessageController;
use MuchoCore\Social\MessageRepository;
use MuchoCore\Social\MessageService;
use MuchoCore\Social\RelationshipController;
use MuchoCore\Social\RelationshipRepository;
use MuchoCore\Social\RelationshipService;
use MuchoCore\User\UserController;
use MuchoCore\User\UserRepository;
use MuchoCore\User\UserService;
use MuchoCore\V71\AuthService;
use PDO;
use Throwable;

final readonly class Application
{
    private Router $router;
    private PDO $pdo;
    private MuchoProtect $protect;
    private PluginManager $plugins;

    public function __construct()
    {
        $this->pdo = (new Database())->connection();
        $this->router = new Router();
        $this->protect = new MuchoProtect();
        $this->plugins = PluginManager::fromEnvironment(
            $this->pdo,
            $this->router,
            dirname(__DIR__, 2)
        );
        $this->plugins->load();

        $accountRepo = new AccountRepository($this->pdo);
        $accountService = new AccountService($this->pdo, $accountRepo);
        $accountController = new AccountController($accountService);
        $auth = new AccountAuthenticator($this->pdo);

        $v71Auth = new AuthService($this->pdo);
        $topArtistController = new TopArtistController(
            new TopArtistService(
                new TopArtistRepository($this->pdo)
            )
        );
        $levelListController = new LevelListController(
            new LevelListService(
                new LevelListRepository($this->pdo),
                $v71Auth
            )
        );
        $commentHistoryController = new CommentHistoryController(
            new CommentHistoryService(
                new CommentHistoryRepository($this->pdo)
            )
        );
        $urlController = new UrlController();

        $cloudSaveController = new CloudSaveController(
            new CloudSaveService(
                $this->pdo,
                $auth,
                new CloudSaveRepository($this->pdo)
            )
        );

        $legacy10Identity = new Legacy10IdentityService($this->pdo);

        $levelRepo = new LevelRepository($this->pdo);
        $levelService = new LevelService(
            $levelRepo,
            new GdLevelListEncoder()
        );
        $levelController = new LevelController($levelService, $auth);

        $transferRepo = new LevelTransferRepository($this->pdo);
        $transferService = new LevelTransferService(
            $this->pdo,
            $auth,
            $legacy10Identity,
            $transferRepo,
            new GdLevelDownloadEncoder()
        );
        $transferController =
            new LevelTransferController($transferService);

        $legacy10IdentityController =
            new Legacy10IdentityController($legacy10Identity);

        $songRepo = new SongRepository($this->pdo);
        $songController = new SongController(
            new SongService($songRepo, new GdSongEncoder())
        );

        $modRepo = new ModerationRepository($this->pdo);
        $modController = new ModerationController(
            new ModerationService($auth, $modRepo)
        );

        $userRepo = new UserRepository($this->pdo);
        $userController = new UserController(
            new UserService(
                $this->pdo,
                $auth,
                $accountRepo,
                $userRepo,
                new GdUserEncoder(),
                $legacy10Identity
            )
        );

        $commentRepo = new CommentRepository($this->pdo);
        $commentController = new CommentController(
            new CommentService(
                $commentRepo,
                $auth,
                new GdCommentEncoder(),
                $this->pdo,
                $legacy10Identity
            )
        );

        $likeController = new LikeController(
            new LikeService(
                new LikeRepository($this->pdo),
                $auth
            )
        );

        $relationshipRepo =
            new RelationshipRepository($this->pdo);

        $relationshipController = new RelationshipController(
            new RelationshipService(
                $relationshipRepo,
                $auth,
                new GdRelationshipEncoder()
            )
        );

        $messageController = new MessageController(
            new MessageService(
                new MessageRepository($this->pdo),
                $auth,
                new GdMessageEncoder(),
                $relationshipRepo
            )
        );

        $rewardsController =
            new RewardsController(
                new \MuchoCore\Interaction\RewardsService(
                    new \MuchoCore\Interaction\RewardsRepository(
                        $this->pdo
                    ),
                    $auth
                )
            );

        $clanController = new ClanController(
            new ClanService(
                $this->pdo,
                $auth,
                new ClanRepository($this->pdo)
            )
        );

        $discoveryController = new DiscoveryController($this->pdo);

        $levelScoreController = new LevelScoreController($this->pdo, $auth);
        $platformerScoreController = new PlatformerScoreController($this->pdo, $auth);

        $route = function (
            string $path,
            mixed $handler
        ): void {
            $this->router->add('ANY', $path, $handler);
        };

        $route('/health',
            static fn(Request $r): Response =>
                Response::text('1'));

        // Legacy/compatibility endpoints implemented by dedicated services.
        $route('/updateGJUserName',
            [$legacy10IdentityController, 'updateUsername']);

        $route('/getAccountURL',
            fn(Request $r): Response =>
                Response::text($urlController->accountUrl()));
        $route('/getCustomContentURL',
            fn(Request $r): Response =>
                Response::text($urlController->customContentUrl()));
        $route('/getGJCommentHistory',
            fn(Request $r): Response =>
                Response::text($commentHistoryController->get()));
        $route('/getGJLevelLists',
            fn(Request $r): Response =>
                Response::text($levelListController->get()));
        $route('/uploadGJLevelList',
            fn(Request $r): Response =>
                Response::text($levelListController->upload()));
        $route('/deleteGJLevelList',
            fn(Request $r): Response =>
                Response::text($levelListController->delete()));
        $route('/getGJTopArtists',
            fn(Request $r): Response =>
                Response::text($topArtistController->get()));

        $route('/loginGJAccount',
            [$accountController,'login']);
        $route('/registerGJAccount',
            [$accountController,'register']);

        /*
         * MuchoCore Secure Cloud Save v7
         * Copyright (C) 2026 IZK
         */

        $route('/backupGJAccount',
            [$cloudSaveController,'backup']);

        $route('/backupGJAccount20',
            [$cloudSaveController,'backup']);

        $route('/syncGJAccount',
            [$cloudSaveController,'sync']);

        $route('/syncGJAccount20',
            [$cloudSaveController,'sync']);

        $route('/getGJLevels21',
            [$levelController,'list']);
        $route('/updateGJLevel',
            [$transferController,'checkUpdate']);
        $route('/uploadGJLevel21',
            [$transferController,'upload']);
        $route('/uploadGJLevel22',
            [$transferController,'upload']);
        $route('/downloadGJLevel21',
            [$transferController,'download']);
        $route('/downloadGJLevel22',
            [$transferController,'download']);
        $route('/deleteGJLevelUser20',
            [$transferController,'delete']);

        $route('/updateGJLevelDesc20',
            [$transferController,'updateDescription']);

        $route('/getGJSongInfo',
            [$songController,'info']);

        $route('/suggestGJStars20',
            [$modController,'suggest']);

        $route('/rateGJStars20',
            [$modController,'rateStars']);

        $route('/rateGJStars211',
            [$modController,'rateStars']);

        $route('/rateGJDemon21',
            [$modController,'rateDemon']);

        $route('/reportGJLevel',
            [$modController,'report']);
        $route('/requestUserAccess',
            [$userController,'requestAccess']);

        $route('/getGJUserInfo20',
            [$userController,'profile']);
        $route('/getGJUsers20',
            [$userController,'search']);
        $route('/getGJScores20',
            [$userController,'scores']);
        $route('/updateGJAccSettings20',
            [$userController,'updateSettings']);
        $route('/updateGJUserScore',
            [$userController,'updateScore']);
        $route('/updateGJUserScore22',
            [$userController,'updateScore']);

        $route('/getGJComments21',
            [$commentController,'getLevelComments']);
        $route('/uploadGJComment20',
            [$commentController,'uploadLevelComment']);
        $route('/uploadGJComment21',
            [$commentController,'uploadLevelComment']);
        $route('/deleteGJComment20',
            [$commentController,'deleteComment']);
        $route('/getGJAccountComments20',
            [$commentController,'getAccountComments']);
        $route('/uploadGJAccComment20',
            [$commentController,'uploadAccountComment']);
        $route('/deleteGJAccComment20',
            [$commentController,'deleteAccountComment']);

        $route('/likeGJItem21',
            [$likeController,'like']);
        $route('/likeGJItem211',
            [$likeController,'like']);

        $route('/getGJMessages20',
            [$messageController,'getMessages']);
        $route('/downloadGJMessage20',
            [$messageController,'readMessage']);
        $route('/uploadGJMessage20',
            [$messageController,'sendMessage']);
        $route('/deleteGJMessages20',
            [$messageController,'deleteMessage']);

        $route('/uploadFriendRequest20',
            [$relationshipController,'send']);
        $route('/getGJFriendRequests20',
            [$relationshipController,'get']);
        $route('/readGJFriendRequest20',
            [$relationshipController,'read']);
        $route('/acceptGJFriendRequest20',
            [$relationshipController,'accept']);
        $route('/deleteGJFriendRequests20',
            [$relationshipController,'delete']);
        $route('/removeGJFriend20',
            [$relationshipController,'remove']);
        $route('/blockGJUser20',
            [$relationshipController,'block']);
        $route('/unblockGJUser20',
            [$relationshipController,'unblock']);
        $route('/getGJUserList20',
            [$relationshipController,'userList']);

        /*
         * MuchoCore Compatibility v6
         * Copyright (C) 2026 IZK
         */

        /*
         * MuchoCore Clans
         * Custom JSON API for clan management. Clan membership is also
         * reflected in standard Geometry Dash user-name response fields.
         */
        $route('/api/clans/create',
            [$clanController,'create']);
        $route('/api/clans/my',
            [$clanController,'myClan']);
        $route('/api/clans/get',
            [$clanController,'get']);
        $route('/api/clans/search',
            [$clanController,'search']);
        $route('/api/clans/join',
            [$clanController,'join']);
        $route('/api/clans/leave',
            [$clanController,'leave']);
        $route('/api/clans/invite',
            [$clanController,'invite']);
        $route('/api/clans/invite/accept',
            [$clanController,'acceptInvite']);
        $route('/api/clans/invite/decline',
            [$clanController,'declineInvite']);
        $route('/api/clans/kick',
            [$clanController,'kick']);
        $route('/api/clans/role',
            [$clanController,'setRole']);
        $route('/api/clans/invites',
            [$clanController,'invites']);

        $route('/getGJCreators',
            [$discoveryController,'creators']);
        $route('/getGJCreators19',
            [$discoveryController,'creators']);

        $route('/getGJDailyLevel',
            [$discoveryController,'daily']);

        $route('/getGJGauntlets',
            [$discoveryController,'gauntlets']);
        $route('/getGJGauntlets21',
            [$discoveryController,'gauntlets']);

        $route('/getGJMapPacks',
            [$discoveryController,'mapPacks']);
        $route('/getGJMapPacks20',
            [$discoveryController,'mapPacks']);
        $route('/getGJMapPacks21',
            [$discoveryController,'mapPacks']);

        /*
         * MuchoCore Level Scores v6.1
         * Copyright (C) 2026 IZK
         */

        $route('/getGJLevelScores',
            [$levelScoreController,'regular']);

        $route('/getGJLevelScores211',
            [$levelScoreController,'regular']);

        $route('/getGJLevelScoresPlat',
            [$platformerScoreController,'handle']);

        $route('/getGJRewards',
            [$rewardsController,'getRewards']);
        $route('/getGJSecretReward',
            [$rewardsController,'getSecretReward']);
        $route('/getGJChallenges',
            [$rewardsController,'getChallenges']);

        $this->plugins->boot();
    }

    public function handle(Request $request): Response
    {
        ClientTrace::captureRequest($request);
        $this->plugins->emit('request.received', [
            'request' => $request,
        ]);

        if (($_SERVER['MUCHO_PROTECT_PRECHECKED'] ?? '') !== '1') {
            $protection = $this->protect->inspect(
                $request,
                $this->router->normalizePath($request->path)
            );

            if ($protection['decision'] === 'block') {
                // Keep a successful HTTP transport response for Geometry Dash
                // clients; the legacy protocol uses "-1" as its failure signal.
                $response = Response::text('-1');
                $this->plugins->emit('request.completed', [
                    'request' => $request,
                    'response' => $response,
                    'blocked' => true,
                ]);
                ClientTrace::captureResponse($response);
                return $response;
            }
        }

        try {
            $compatibilityProfile = CompatibilityProfile::fromEnvironment();

            if (!$compatibilityProfile->allows($request->clientVersion())) {
                $response = Response::text('-1');
                $this->plugins->emit('request.completed', [
                    'request' => $request,
                    'response' => $response,
                    'compatible' => false,
                ]);
                ClientTrace::captureResponse($response);
                return $response;
            }

            $response = $this->router->dispatch($request);
            $this->plugins->emit('request.completed', [
                'request' => $request,
                'response' => $response,
                'compatible' => true,
            ]);
            ClientTrace::captureResponse($response);
            return $response;
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] %s %s | %s: %s | %s:%d',
                (string)($request->method ?? '?'),
                (string)($request->path ?? '?'),
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $response = Response::text('-1');
            $this->plugins->emit('request.failed', [
                'request' => $request,
                'error' => $e,
                'response' => $response,
            ]);
            ClientTrace::captureResponse($response);
            return $response;
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
