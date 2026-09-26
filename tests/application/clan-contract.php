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
$controller = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanController.php');
$application = (string)file_get_contents(__DIR__ . '/../../src/Core/Application.php');
$migration = (string)file_get_contents(__DIR__ . '/../../database/migrations/20260926_002_clans_v2.php');

foreach ([
    'updateSettings' => $service,
    'transferOwnership' => $service,
    'disband' => $service,
    'revokeInvite' => $service,
    'ban' => $service,
    'unban' => $service,
    'bans' => $service,
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
] as $route) {
    assertClanContract(
        str_contains($application, "\$route"),
        'Application registers ' . $route
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

echo "MUCHOCORE_CLAN_CONTRACT_OK\n";
