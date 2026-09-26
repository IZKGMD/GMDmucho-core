<?php

declare(strict_types=1);

function assertCoreRefactor(bool $condition,string $name): void
{
    if (!$condition) {
        fwrite(STDERR,"FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$application=(string)file_get_contents(__DIR__.'/../../src/Core/Application.php');
$services=(string)file_get_contents(__DIR__.'/../../src/Core/AppServices.php');
$routes=(string)file_get_contents(__DIR__.'/../../src/Core/AppRoutes.php');
$router=(string)file_get_contents(__DIR__.'/../../src/Routing/Router.php');
$aliases=(string)file_get_contents(__DIR__.'/../../src/Routing/CompatibilityAliases.php');
$endpoint=(string)file_get_contents(__DIR__.'/../../src/Http/LegacyEndpoint.php');
$pipeline=(string)file_get_contents(__DIR__.'/../../src/Core/RequestPipeline.php');

assertCoreRefactor(
    str_contains($application,'AppServices::build') &&
    str_contains($application,'AppRoutes::register'),
    'Application delegates wiring to dedicated modules'
);

assertCoreRefactor(
    strlen($application)<6500,
    'Application remains a thin bootstrap'
);

assertCoreRefactor(
    str_contains($services,'AccountController') &&
    str_contains($services,'ClanController') &&
    str_contains($services,'LevelScoreController'),
    'AppServices owns controller construction'
);

assertCoreRefactor(
    str_contains($services,'use MuchoCore\\Interaction\\CommentController;') &&
    str_contains($services,'use MuchoCore\\Interaction\\CommentRepository;') &&
    str_contains($services,'use MuchoCore\\Interaction\\CommentService;') &&
    str_contains($services,'use MuchoCore\\Comment\\CommentHistoryController;'),
    'AppServices keeps comment namespaces aligned with the source tree'
);

assertCoreRefactor(
    str_contains($services,'use MuchoCore\\LevelList\\LevelListController;') &&
    str_contains($services,'use MuchoCore\\LevelList\\LevelListRepository;') &&
    str_contains($services,'use MuchoCore\\LevelList\\LevelListService;'),
    'AppServices keeps level-list namespaces aligned with the source tree'
);

assertCoreRefactor(
    str_contains($services,'CommentCommandService') &&
    str_contains($services,'new CommentCommandService($pdo)'),
    'Comment moderation commands are wired as a dedicated service'
);

$commentService=(string)file_get_contents(__DIR__.'/../../src/Interaction/CommentService.php');
$commentCommands=(string)file_get_contents(__DIR__.'/../../src/Interaction/CommentCommandService.php');
assertCoreRefactor(
    !str_contains($commentService,'function handleCommand(') &&
    str_contains($commentService,'$commands->handle(') &&
    str_contains($commentCommands,'function handle(') &&
    str_contains($commentCommands,'function recalculateCreatorPoints('),
    'CommentService delegates moderator commands to CommentCommandService'
);

$clanRepository=(string)file_get_contents(__DIR__.'/../../src/Clan/ClanRepository.php');
$clanStatsRepository=(string)file_get_contents(__DIR__.'/../../src/Clan/ClanStatsRepository.php');
assertCoreRefactor(
    str_contains($clanRepository,'ClanStatsRepository $statsRepository') &&
    str_contains($clanRepository,'return $this->statsRepository->stats($clanId);') &&
    str_contains($clanRepository,'return $this->statsRepository->topClans($metric,$limit);') &&
    str_contains($clanStatsRepository,'function stats(') &&
    str_contains($clanStatsRepository,'function topClans('),
    'ClanRepository delegates statistics queries to ClanStatsRepository'
);


assertCoreRefactor(
    str_contains($routes,'/api/clans/rankings') &&
    str_contains($routes,'/getGJLevelScores'),
    'AppRoutes owns API route registration'
);

assertCoreRefactor(
    !str_contains($router,'static $compatAliases') &&
    str_contains($router,'CompatibilityAliases::all()'),
    'Router no longer contains the legacy alias table'
);

assertCoreRefactor(
    str_contains($aliases,'/getgjuserinfo22') &&
    str_contains($aliases,'/uploadgjcomment19'),
    'Compatibility alias coverage stays centralized'
);

assertCoreRefactor(
    str_contains($endpoint,'function text(callable $action): Response'),
    'Legacy endpoint error handling is centralized'
);

assertCoreRefactor(
    str_contains($pipeline,'function handle(Request $request): Response') &&
    str_contains($pipeline,'CompatibilityProfile::fromEnvironment'),
    'Request processing is isolated in one pipeline'
);

assertCoreRefactor(
    str_contains($aliases,'function all(): array'),
    'Compatibility aliases are isolated from routing logic'
);

echo "MUCHOCORE_CORE_REFACTOR_CONTRACT_OK\n";
