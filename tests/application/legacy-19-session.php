<?php

declare(strict_types=1);

function assertLegacy19SessionContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$authenticator = (string)file_get_contents(
    __DIR__ . '/../../src/Account/AccountAuthenticator.php'
);

$service = (string)file_get_contents(
    __DIR__ . '/../../src/Account/AccountService.php'
);

assertLegacy19SessionContract(
    str_contains(
        $authenticator,
        'expires_at = DATE_ADD('
    ) &&
    str_contains(
        $authenticator,
        'INTERVAL 1 HOUR'
    ),
    'legacy 1.9 sessions refresh expiry on successful use'
);

assertLegacy19SessionContract(
    str_contains(
        $authenticator,
        's.expires_at > UTC_TIMESTAMP()'
    ) &&
    str_contains(
        $authenticator,
        'password_verify($udid'
    ),
    'legacy 1.9 authentication remains expiry and UDID bound'
);

assertLegacy19SessionContract(
    str_contains(
        $service,
        'rememberLegacy19UploadSession('
    ) &&
    str_contains(
        $service,
        'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
    ),
    'legacy 1.9 login creates a short-lived upload session'
);

echo "MUCHOCORE_LEGACY_19_SESSION_OK\n";
