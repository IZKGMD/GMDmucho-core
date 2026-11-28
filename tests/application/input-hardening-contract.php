<?php

declare(strict_types=1);

function assertInputHardening(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$auth = (string)file_get_contents(
    __DIR__ . '/../../src/Account/AccountAuthenticator.php'
);
$regular = (string)file_get_contents(
    __DIR__ . '/../../src/Score/LevelScoreController.php'
);
$platformer = (string)file_get_contents(
    __DIR__ . '/../../src/Score/PlatformerScoreController.php'
);

assertInputHardening(
    str_contains($auth, 'DUMMY_PASSWORD_HASH') &&
    str_contains($auth, 'password_verify($credential, self::DUMMY_PASSWORD_HASH)'),
    'account authentication consumes dummy hash work on missing/inactive accounts'
);

assertInputHardening(
    str_contains($auth, 'SELECT user_id') &&
    str_contains($auth, 'FROM profiles') &&
    str_contains($auth, 'Unable to resolve profile user ID.') &&
    !str_contains($auth, '$account[\'user_id\'] = $accountId;'),
    'authentication resolves the actual profile user ID after lazy profile creation'
);

assertInputHardening(
    str_contains($regular, 'private function decodedNumber(') &&
    str_contains($regular, '): ?int') &&
    str_contains($regular, '$attempts === null || $clicks === null') &&
    str_contains($regular, '$playTime === null || $coins === null') &&
    str_contains($regular, 'if ($hasPercent)') &&
    str_contains($regular, 'preg_match(\'/^[0-9]{1,3}$/D\', $raw)'),
    'regular score decoder rejects malformed or out-of-range numeric fields'
);

assertInputHardening(
    str_contains($regular, '$progresses = $this->decodeProgresses') &&
    str_contains($regular, '$progresses === null || strlen($progresses) > 100000') &&
    str_contains($regular, '$decoded=') &&
    str_contains($regular, 'if($decoded===false){') &&
    str_contains($regular, 'return null;'),
    'regular score progress payload rejects malformed base64'
);

assertInputHardening(
    preg_match('/private function strictInt\\(mixed \\$value\\): int/', $platformer) === 1 &&
    str_contains($platformer, '$time<0 || $time>86400000') &&
    str_contains($platformer, '$points<0 || $points>100000000'),
    'platformer scores enforce strict integer and bounded score inputs'
);

assertInputHardening(
    str_contains($regular, "array_key_exists('type', \$data)") &&
    str_contains($regular, "preg_match('/^[0-9]{1,2}$/D'") &&
    str_contains($regular, "array_key_exists('s10', \$data)") &&
    str_contains($regular, "preg_match('/^[0-9]{1,10}$/D'"),
    'regular score rejects malformed selectors and timely IDs'
);

assertInputHardening(
    str_contains($platformer, "\$this->strictInt(\$d['accountID'] ?? null)") &&
    str_contains($platformer, "\$this->strictInt(\$d['levelID'] ?? null)") &&
    str_contains($platformer, "\$this->strictInt(\$d['mode'] ?? 0)") &&
    str_contains($platformer, "\$this->strictInt(\$d['type'] ?? 0)") &&
    str_contains($platformer, "gmdate("),
    'platformer score identifiers and selectors are strictly parsed and UTC-stable'
);

echo "MUCHOCORE_INPUT_HARDENING_OK\n";
