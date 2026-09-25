<?php

declare(strict_types=1);

require __DIR__ . '/../../src/User/GameRole.php';

use MuchoCore\User\GameRole;

// Application test paths are rooted from the organized tests/application directory.

function assertRole(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL %s: expected %s, got %s\n",
                $name,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
        exit(1);
    }

    echo "PASS {$name}\n";
}

$cases = [
    [GameRole::USER, 'user'],
    [GameRole::MODERATOR, 'moderator'],
    [GameRole::ELDER_MODERATOR, 'elder_moderator'],
    [GameRole::OWNER, 'owner'],
    [GameRole::USER, 'PLAYER'],
    [GameRole::MODERATOR, 'HELPER'],
    [GameRole::MODERATOR, 'MOD'],
    [GameRole::ELDER_MODERATOR, 'ADMIN'],
    [GameRole::ELDER_MODERATOR, 'ELDER'],
    [GameRole::ELDER_MODERATOR, 'DEVELOPER'],
];

foreach ($cases as [$expected, $input]) {
    assertRole(
        $expected,
        GameRole::normalize($input),
        "normalize {$input}"
    );
}

assertRole('-1', GameRole::accessLevel('user'), 'user access');
assertRole('1', GameRole::accessLevel('moderator'), 'moderator access');
assertRole('2', GameRole::accessLevel('elder_moderator'), 'elder moderator access');
assertRole('2', GameRole::accessLevel('owner'), 'owner access');

assertRole(0, GameRole::badgeLevel('user'), 'user badge');
assertRole(1, GameRole::badgeLevel('moderator'), 'moderator badge');
assertRole(2, GameRole::badgeLevel('elder_moderator'), 'elder moderator badge');
assertRole(2, GameRole::badgeLevel('owner'), 'owner badge');

$roleFiles = [
    __DIR__ . '/../../bin/mucho-roles.php',
    __DIR__ . '/../../public/admin/pages/players.php',
    __DIR__ . '/../../public/admin/actions/legacy-dispatcher.php',
    __DIR__ . '/../../src/Moderation/ModerationService.php',
    __DIR__ . '/../../src/Interaction/CommentService.php',
];

foreach ($roleFiles as $file) {
    $contents = file_get_contents($file);

    if ($contents === false) {
        fwrite(STDERR, "FAIL cannot read {$file}\n");
        exit(1);
    }

    if (preg_match("/['\"]helper['\"]/", $contents)) {
        fwrite(
            STDERR,
            "FAIL legacy game role found in {$file}\n"
        );
        exit(1);
    }

    if (str_contains($contents, 'UPDATE accounts SET role=')) {
        fwrite(
            STDERR,
            "FAIL legacy accounts.role write found in {$file}\n"
        );
        exit(1);
    }
}

if (str_contains((string)file_get_contents(__DIR__ . '/../../src/Moderation/ModerationService.php'), "'elder',")) {
    fwrite(STDERR, "FAIL stale moderation role alias found\n");
    exit(1);
}

if (!str_contains((string)file_get_contents(__DIR__ . '/../../src/Moderation/ModerationService.php'), "'elder_moderator'")) {
    fwrite(STDERR, "FAIL canonical moderation role missing\n");
    exit(1);
}

if (!str_contains((string)file_get_contents(__DIR__ . '/../../src/Interaction/CommentService.php'), '"elder_moderator"')) {
    fwrite(STDERR, "FAIL canonical comment-command role missing\n");
    exit(1);
}

echo "PASS canonical game role sources\n";
echo "MUCHOCORE_GAME_ROLES_OK\n";
