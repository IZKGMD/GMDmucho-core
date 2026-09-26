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
use MuchoCore\CloudSave\CloudSaveController;
use MuchoCore\CloudSave\CloudSaveRepository;
use MuchoCore\CloudSave\CloudSaveService;
use MuchoCore\Clan\ClanController;
use MuchoCore\Clan\ClanRepository;
use MuchoCore\Clan\ClanService;
use MuchoCore\Comment\CommentHistoryController;
use MuchoCore\Comment\CommentHistoryRepository;
use MuchoCore\Comment\CommentHistoryService;
use MuchoCore\Interaction\CommentController;
use MuchoCore\Interaction\CommentRepository;
use MuchoCore\Interaction\CommentService;
use MuchoCore\Compatibility\DiscoveryController;
use MuchoCore\Compatibility\Legacy10IdentityController;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Interaction\LikeController;
use MuchoCore\Interaction\LikeRepository;
use MuchoCore\Interaction\LikeService;
use MuchoCore\Interaction\RewardsController;
use MuchoCore\Interaction\RewardsRepository;
use MuchoCore\Interaction\RewardsService;
use MuchoCore\Level\LevelController;
use MuchoCore\Level\LevelListController;
use MuchoCore\Level\LevelListRepository;
use MuchoCore\Level\LevelListService;
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
use MuchoCore\Url\UrlController;
use MuchoCore\V71\AuthService;
use PDO;

final class AppServices
{
    /**
     * @return array<string,mixed>
     */
    public static function build(PDO $pdo, Router $router): array
    {
        $auth = new AccountAuthenticator($pdo);
        $accountRepository = new AccountRepository($pdo);

        $legacy10 = new Legacy10IdentityService($pdo);

        $userRepository = new UserRepository($pdo);
        $levelRepository = new LevelRepository($pdo);
        $commentRepository = new CommentRepository($pdo);
        $relationshipRepository = new RelationshipRepository($pdo);

        return [
            'plugins' => PluginManager::fromEnvironment(
                $pdo,
                $router,
                dirname(__DIR__, 2)
            ),

            'account' => new AccountController(
                new AccountService($pdo, $accountRepository)
            ),

            'artists' => new TopArtistController(
                new TopArtistService(
                    new TopArtistRepository($pdo)
                )
            ),

            'levelLists' => new LevelListController(
                new LevelListService(
                    new LevelListRepository($pdo),
                    new AuthService($pdo)
                )
            ),

            'commentHistory' => new CommentHistoryController(
                new CommentHistoryService(
                    new CommentHistoryRepository($pdo)
                )
            ),

            'url' => new UrlController(),

            'cloudSave' => new CloudSaveController(
                new CloudSaveService(
                    $pdo,
                    $auth,
                    new CloudSaveRepository($pdo)
                )
            ),

            'legacy10' => new Legacy10IdentityController($legacy10),

            'levels' => new LevelController(
                new LevelService(
                    $levelRepository,
                    new GdLevelListEncoder()
                ),
                $auth
            ),

            'levelTransfer' => new LevelTransferController(
                new LevelTransferService(
                    $pdo,
                    $auth,
                    $legacy10,
                    new LevelTransferRepository($pdo),
                    new GdLevelDownloadEncoder()
                )
            ),

            'songs' => new SongController(
                new SongService(
                    new SongRepository($pdo),
                    new GdSongEncoder()
                )
            ),

            'moderation' => new ModerationController(
                new ModerationService(
                    $auth,
                    new ModerationRepository($pdo)
                )
            ),

            'users' => new UserController(
                new UserService(
                    $pdo,
                    $auth,
                    $accountRepository,
                    $userRepository,
                    new GdUserEncoder(),
                    $legacy10
                )
            ),

            'comments' => new CommentController(
                new CommentService(
                    $commentRepository,
                    $auth,
                    new GdCommentEncoder(),
                    $pdo,
                    $legacy10
                )
            ),

            'likes' => new LikeController(
                new LikeService(
                    new LikeRepository($pdo),
                    $auth
                )
            ),

            'relationships' => new RelationshipController(
                new RelationshipService(
                    $relationshipRepository,
                    $auth,
                    new GdRelationshipEncoder()
                )
            ),

            'messages' => new MessageController(
                new MessageService(
                    new MessageRepository($pdo),
                    $auth,
                    new GdMessageEncoder(),
                    $relationshipRepository
                )
            ),

            'rewards' => new RewardsController(
                new RewardsService(
                    new RewardsRepository($pdo),
                    $auth
                )
            ),

            'clans' => new ClanController(
                new ClanService(
                    $pdo,
                    $auth,
                    new ClanRepository($pdo)
                )
            ),

            'discovery' => new DiscoveryController($pdo),

            'levelScores' => new LevelScoreController($pdo, $auth),

            'platformerScores' => new PlatformerScoreController($pdo, $auth),

            'protect' => new MuchoProtect(),
        ];
    }
}
