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

foreach ([
    "session.cookie_lifetime',
    '604800",
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
] as $needle) {
    assertClanDashboardContract(
        str_contains($dashboard, $needle),
        'dashboard contains ' . $needle
    );
}

assertClanDashboardContract(
    str_contains($dashboard, '$repo->acceptInvite($inviteId, $accountId)'),
    'dashboard accepts invitations through repository transaction path'
);

assertClanDashboardContract(
    str_contains($dashboard, '$repo->clanInvitations((int)$myClan['clan_id'])'),
    'dashboard loads outgoing clan invitations'
);

assertClanDashboardContract(
    str_contains($repository, 'function clanInvitations('),
    'repository exposes outgoing clan invitations'
);

echo "MUCHOCORE_CLAN_DASHBOARD_CONTRACT_OK\n";
