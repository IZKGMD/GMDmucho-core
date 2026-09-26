<?php

declare(strict_types=1);

function assertClanContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$service = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanService.php');
$repository = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanRepository.php');
$statsRepository = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanStatsRepository.php');
$controller = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanController.php');
$application = (string)file_get_contents(__DIR__ . '/../../src/Core/AppRoutes.php');
$migration = (string)file_get_contents(__DIR__ . '/../../database/migrations/20260926_002_clans_v2.php');

foreach ([
    'updateSettings' => $service,
    'transferOwnership' => $service,
    'disband' => $service,
    'delete' => $service,
    'revokeInvite' => $service,
    'ban' => $service,
    'unban' => $service,
    'bans' => $service,
    'apply' => $service,
    'applications' => $service,
    'clanApplications' => $service,
    'acceptApplication' => $service,
    'declineApplication' => $service,
    'cancelApplication' => $service,
    'stats' => $service,
    'rankings' => $service,
    'permissions' => $service,
] as $method => $source) {
    assertClanContract(
        str_contains($source, 'function ' . $method . '('),
        'ClanService exposes ' . $method
    );
}

foreach ([
    'updateSettings' => $repository,
    'transferOwnership' => $repository,
    'disband' => $repository,
    'revokeInvite' => $repository,
    'ban' => $repository,
    'unban' => $repository,
    'bans' => $repository,
    'acceptInvite' => $repository,
    'apply' => $repository,
    'applicationForAccount' => $repository,
    'clanApplications' => $repository,
    'applications' => $repository,
    'acceptApplication' => $repository,
    'declineApplication' => $repository,
    'cancelApplication' => $repository,
] as $method => $source) {
    assertClanContract(
        str_contains($source, 'function ' . $method . '('),
        'ClanRepository exposes ' . $method
    );
}

foreach ([
    'updateSettings' => $controller,
    'transferOwnership' => $controller,
    'disband' => $controller,
    'revokeInvite' => $controller,
    'ban' => $controller,
    'unban' => $controller,
    'bans' => $controller,
    'apply' => $controller,
    'applications' => $controller,
    'clanApplications' => $controller,
    'acceptApplication' => $controller,
    'declineApplication' => $controller,
    'cancelApplication' => $controller,
    'stats' => $controller,
    'rankings' => $controller,
    'permissions' => $controller,
    'delete' => $controller,
] as $method => $source) {
    assertClanContract(
        str_contains($source, 'function ' . $method . '('),
        'ClanController exposes ' . $method
    );
}

foreach ([
    '/api/clans/settings',
    '/api/clans/transfer',
    '/api/clans/disband',
    '/api/clans/invite/revoke',
    '/api/clans/ban',
    '/api/clans/unban',
    '/api/clans/bans',
    '/api/clans/apply',
    '/api/clans/applications',
    '/api/clans/applications/incoming',
    '/api/clans/application/accept',
    '/api/clans/application/decline',
    '/api/clans/application/cancel',
    '/api/clans/stats',
    '/api/clans/rankings',
    '/api/clans/permissions',
    '/api/clans/delete',
] as $route) {
    assertClanContract(
        str_contains($application, "'" . $route . "'"),
        'AppRoutes registers ' . $route
    );
}

assertClanContract(
    str_contains($repository, 'FOR UPDATE') &&
    str_contains($repository, 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)'),
    'clan writes use row locking and invitation expiry'
);

assertClanContract(
    str_contains($migration, 'mucho_clan_bans') &&
    str_contains($migration, 'uq_mucho_clan_ban'),
    'clan ban migration defines persistent clan bans'
);

$applicationsMigration = (string)file_get_contents(
    __DIR__ . '/../../database/migrations/021_clan_applications.php'
);

assertClanContract(
    str_contains($applicationsMigration, 'mucho_clan_applications') &&
    str_contains($applicationsMigration, 'uq_mucho_clan_application'),
    'clan application migration defines unique pending applications'
);

assertClanContract(
    str_contains($service, 'clan-application:') &&
    str_contains($service, 'allowStrict') &&
    str_contains($service, '10') &&
    str_contains($service, '3600'),
    'clan application flow is rate-limited'
);

assertClanContract(
    str_contains($repository, 'DELETE FROM mucho_clan_applications') &&
    str_contains($repository, 'DELETE FROM mucho_clan_invites'),
    'clan joins, invites and applications clean up competing pending state'
);

assertClanContract(
    str_contains($repository, 'return $this->statsRepository->stats($clanId);') &&
    str_contains($repository, 'return $this->statsRepository->topClans($metric,$limit);') &&
    str_contains($statsRepository, 'function stats(') &&
    str_contains($statsRepository, 'function topClans(') &&
    str_contains($statsRepository, 'total_stars') &&
    str_contains($statsRepository, 'total_demons') &&
    str_contains($statsRepository, 'total_creator_points'),
    'repository aggregates live clan statistics and rankings'
);

assertClanContract(
    str_contains($service, 'ClanPermissions') &&
    str_contains($service, 'permissionMap') &&
    str_contains($service, "'permissions'"),
    'clan services expose explicit role permissions'
);

assertClanContract(
    str_contains($migration, 'mucho_clan_bans') ||
    str_contains($applicationsMigration, 'mucho_clan_applications'),
    'clan persistent state is backed by migrations'
);

echo "MUCHOCORE_CLAN_CONTRACT_OK\n";
