<?php

declare(strict_types=1);

function assertClanApplicationsContract(bool $condition, string $name): void
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
$controller = (string)file_get_contents(__DIR__ . '/../../src/Clan/ClanController.php');
$application = (string)file_get_contents(__DIR__ . '/../../src/Core/Application.php');
$migration = (string)file_get_contents(__DIR__ . '/../../database/migrations/021_clan_applications.php');

assertClanApplicationsContract(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS mucho_clan_applications') &&
    str_contains($migration, 'UNIQUE KEY uq_mucho_clan_application'),
    'migration creates unique clan application storage'
);

assertClanApplicationsContract(
    str_contains($repository, 'function apply(') &&
    str_contains($repository, 'function applicationForAccount(') &&
    str_contains($repository, 'function clanApplications(') &&
    str_contains($repository, 'function applications(') &&
    str_contains($repository, 'function acceptApplication(') &&
    str_contains($repository, 'function declineApplication(') &&
    str_contains($repository, 'function cancelApplication('),
    'repository covers clan application lifecycle'
);

assertClanApplicationsContract(
    str_contains($service, 'function apply(') &&
    str_contains($service, 'function clanApplications(') &&
    str_contains($service, 'function acceptApplication(') &&
    str_contains($service, 'function declineApplication(') &&
    str_contains($service, 'function cancelApplication('),
    'service covers clan application lifecycle'
);

assertClanApplicationsContract(
    str_contains($controller, 'public function apply(') &&
    str_contains($controller, 'public function clanApplications(') &&
    str_contains($controller, 'public function acceptApplication(') &&
    str_contains($controller, 'public function declineApplication(') &&
    str_contains($controller, 'public function cancelApplication('),
    'controller exposes clan application endpoints'
);

foreach ([
    '/api/clans/apply',
    '/api/clans/applications',
    '/api/clans/applications/incoming',
    '/api/clans/application/accept',
    '/api/clans/application/decline',
    '/api/clans/application/cancel',
] as $route) {
    assertClanApplicationsContract(
        str_contains($application, $route),
        'application registers ' . $route
    );
}

assertClanApplicationsContract(
    str_contains($dashboard, 'value="apply"') &&
    str_contains($dashboard, 'Apply to join') &&
    str_contains($dashboard, 'Join requests') &&
    str_contains($dashboard, 'My applications'),
    'dashboard supports applicant and officer application flows'
);

echo "MUCHOCORE_CLAN_APPLICATIONS_CONTRACT_OK\n";
