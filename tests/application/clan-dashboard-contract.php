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
$identityMigration = (string)file_get_contents(__DIR__ . '/../../database/migrations/022_expand_clan_identity.php');
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
    str_contains($dashboard, 'clan-invite:') &&
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

assertClanDashboardContract(
    str_contains($dashboard, "maxlength=\"32\"") &&
    str_contains($dashboard, "maxlength=\"8\"") &&
    str_contains($dashboard, "A-Za-z0-9._-"),
    'dashboard uses expanded no-space clan identity limits'
);

assertClanDashboardContract(
    str_contains($service, "strlen($name)<=32") &&
    str_contains($service, "strlen($tag)<=8") &&
    str_contains($service, "A-Za-z0-9._-"),
    'service enforces expanded no-space clan identity limits'
);

assertClanDashboardContract(
    str_contains($identityMigration, 'VARCHAR(32)') &&
    str_contains($identityMigration, 'VARCHAR(8)'),
    'clan identity migration expands database columns'
);

assertClanDashboardContract(
    str_contains($dashboard, 'Clan rankings') &&
    str_contains($dashboard, 'Top Stars') &&
    str_contains($dashboard, 'Top Demons') &&
    str_contains($dashboard, 'Top Creators') &&
    str_contains($dashboard, 'Largest Clans'),
    'dashboard exposes clan rankings'
);

assertClanDashboardContract(
    str_contains($dashboard, 'total_stars') &&
    str_contains($dashboard, 'total_demons') &&
    str_contains($dashboard, 'total_creator_points') &&
    str_contains($dashboard, 'total_levels'),
    'dashboard exposes aggregated clan statistics'
);

assertClanDashboardContract(
    str_contains($dashboard, 'Delete clan permanently') &&
    str_contains($dashboard, 'All memberships, invites, applications and bans will be deleted'),
    'dashboard exposes destructive clan deletion'
);

assertClanDashboardContract(
    str_contains($dashboard, "myClan['permissions']") &&
    str_contains($dashboard, "str_replace('_', ' ', $permission)"),
    'dashboard exposes explicit clan permissions'
);

echo "MUCHOCORE_CLAN_DASHBOARD_CONTRACT_OK\n";
