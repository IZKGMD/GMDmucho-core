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

echo "MUCHOCORE_CORE_REFACTOR_CONTRACT_OK\n";
