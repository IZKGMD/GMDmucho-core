<?php

declare(strict_types=1);

function assertClanDashboardContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$dashboard = (string)file_get_contents(__DIR__ . '/../../public/dashboard/clans.php');
$repository = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanRepository.php');
$service = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanService.php');
$migration = (string)file_get_contents(__DIR__ . '/../../database/migrations/021_clan_applications.php');
$service = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanService.php');

foreach ([
    "session.cookie_lifetime",
    "604800",
    "session.gc_maxlifetime",
    "action === 'settings'",
    "action === 'transfer'",
    "action === 'disband'",
    "action === 'revoke_invite'",
    "action === 'ban'",
    "action === 'unban'",
    'Clan settings',
    'Ownership',
    'Invitations & bans',
    'my-clan-overview',
] as $needle) {
    assertClanDashboardContract(
        str_contains($dashboard, $needle),
        'dashboard contains ' . $needle
    );
}

assertClanDashboardContract(
    str_contains($dashboard, 'function pcResolveAccountId(') &&
    str_contains($dashboard, 'name="targetUsername"'),
    'dashboard supports username-based clan bans'
);

assertClanDashboardContract(
    str_contains($dashboard, 'class="btn alt danger"'),
    'dashboard marks destructive clan actions'
);

assertClanDashboardContract(
    str_contains($dashboard, '$repo->acceptInvite($inviteId, $accountId)'),
    'dashboard accepts invitations through repository transaction path'
);

assertClanDashboardContract(
    str_contains($dashboard, '$repo->clanInvitations((int)$myClan[\'clan_id\'])'),
    'dashboard loads outgoing clan invitations'
);

assertClanDashboardContract(
    str_contains($repository, 'function clanInvitations('),
    'repository exposes outgoing clan invitations'
);


assertClanDashboardContract(
    str_contains($dashboard, 'clan-invite-dashboard:') &&
    str_contains($dashboard, '30 invites/hour'),
    'dashboard rate-limits clan invitations'
);

assertClanDashboardContract(
    str_contains($service, 'clan-invite:') &&
    str_contains($service, 'allowStrict') &&
    str_contains($service, '30') &&
    str_contains($service, '3600'),
    'service rate-limits clan invitations'
);


assertClanDashboardContract(
    str_contains($dashboard, 'value="apply"') &&
    str_contains($dashboard, 'Apply to join') &&
    str_contains($dashboard, 'Join requests'),
    'dashboard supports closed-clan applications'
);

assertClanDashboardContract(
    str_contains($repository, 'function apply(') &&
    str_contains($repository, 'function acceptApplication(') &&
    str_contains($repository, 'function declineApplication(') &&
    str_contains($repository, 'function cancelApplication('),
    'repository exposes clan application lifecycle'
);

assertClanDashboardContract(
    str_contains($service, 'function apply(') &&
    str_contains($service, 'function acceptApplication(') &&
    str_contains($service, 'function declineApplication(') &&
    str_contains($service, 'function cancelApplication('),
    'service exposes clan application lifecycle'
);

assertClanDashboardContract(
    str_contains($migration, 'mucho_clan_applications') &&
    str_contains($migration, 'uq_mucho_clan_application'),
    'clan application migration exists'
);

echo "MUCHOCORE_CLAN_DASHBOARD_CONTRACT_OK\n";
