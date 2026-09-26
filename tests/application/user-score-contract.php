<?php

declare(strict_types=1);

function assertUserScoreContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$clientVersionFile = __DIR__ . '/../../src/Compatibility/ClientVersion.php';
$controllerFile = __DIR__ . '/../../src/User/UserController.php';
$serviceFile = __DIR__ . '/../../src/User/UserService.php';
$applicationFile = __DIR__ . '/../../src/Core/Application.php';

$clientVersion = (string)file_get_contents($clientVersionFile);
$controller = (string)file_get_contents($controllerFile);
$service = (string)file_get_contents($serviceFile);
$application = (string)file_get_contents($applicationFile);

assertUserScoreContract(
    str_contains($clientVersion, 'updategjuserscore'),
    'versionless updateGJUserScore is classified as legacy GD 1.x'
);

assertUserScoreContract(
    str_contains($controller, '$legacyUdidUpdate') &&
    str_contains($controller, '$version->effectiveGameVersion() < 19') &&
    str_contains($controller, "trim($udid) !== ''"),
    'legacy updateGJUserScore accepts UDID without accountID/GJP'
);

assertUserScoreContract(
    str_contains($controller, "'gameVersion'] = $version->effectiveGameVersion() ?: 1"),
    'versionless legacy score requests receive an inferred gameVersion'
);

assertUserScoreContract(
    str_contains($controller, "'update_user_score_failed'") &&
    str_contains($controller, 'has_udid=%d') &&
    str_contains($controller, 'has_gjp=%d'),
    'legacy score failures are traced without credential values'
);

assertUserScoreContract(
    str_contains($service, "string $udid = ''") &&
    str_contains($service, "string $username = ''") &&
    str_contains($service, "string $ip = ''"),
    'user score service accepts legacy identity context'
);

assertUserScoreContract(
    str_contains($service, '$this->legacy10->resolveAccount('),
    'legacy score updates resolve the account from UDID'
);

assertUserScoreContract(
    str_contains($service, 'return (string)$userId;') &&
    str_contains($service, 'updateGJUserScore returns the legacy userID'),
    'legacy updateGJUserScore returns userID'
);

assertUserScoreContract(
    str_contains($application, '$legacy10Identity') &&
    str_contains("new UserService("),
    'legacy identity service is injected into UserService'
);

echo "MUCHOCORE_USER_SCORE_CONTRACT_OK\n";
