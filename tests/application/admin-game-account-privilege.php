<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/admin/core/GameAccountPrivilegePolicy.php';

use MuchoCore\Admin\GameAccountPrivilegePolicy;

$cases = [
    ['normal player can be edited by administrator', 30, 'user', 0, 0, false],
    ['normal player password can be reset', 30, 'user', 0, null, false],
    ['game owner cannot be edited by administrator', 30, 'owner', 100, 0, true],
    ['game owner password is owner-managed', 30, 'owner', 100, null, true],
    ['elder moderator is protected', 30, 'elder_moderator', 75, null, true],
    ['moderator remains editable with a higher admin rank', 30, 'moderator', 50, null, false],
    ['a regular player cannot be promoted by an admin', 30, 'user', 0, 50, true],
    ['owner promotion is reserved for owner', 30, 'user', 0, 100, true],
    ['custom elevated role fails closed', 30, 'unknown_game_role', 80, null, true],
    ['custom harmless role remains manageable', 30, 'unknown_game_role', 0, null, false],
    ['lower-rank admin cannot manage a moderator', 10, 'moderator', 50, null, true],
    ['owner is allowed past the owner rank check', 40, 'owner', 100, null, true],
];

foreach ($cases as [$name, $actor, $current, $currentPriority, $requestedPriority, $expected]) {
    if (GameAccountPrivilegePolicy::ownerRequired(
        $actor, $current, $currentPriority, $requestedPriority
    ) !== $expected) {
        throw new RuntimeException('Game account owner gate failed: ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}

// Prevent a future refactor from retaining only the safe extracted module
// while routing the real public/admin/index.php POST dispatcher around it.
$source = file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
if (!is_string($source)) {
    throw new RuntimeException('Active administrator handler unavailable.');
}
$sections = [
    'account-save' => ["if (\$action==='account-save') {", "elseif (\$action==='music-upload')"],
    'password-reset' => ["elseif (\$action==='password-reset')", "elseif (\$action==='account-delete')"],
];
foreach ($sections as $name => [$begin, $end]) {
    $start = strpos($source, $begin);
    $stop = $start === false ? false : strpos($source, $end, $start);
    if ($start === false || $stop === false) {
        throw new RuntimeException('Active admin action not found: ' . $name);
    }
    $handler = substr($source, $start, $stop - $start);
    $guardAt = strpos($handler, 'adminRequireGameAccountPrivilege($db, $id');
    $updateAt = strpos($handler, 'UPDATE accounts SET');
    if ($guardAt === false || $updateAt === false || $guardAt >= $updateAt) {
        throw new RuntimeException('Active admin privilege guard missing before write: ' . $name);
    }
    echo 'PASS active ' . $name . ' verifies privilege before writing' . PHP_EOL;
}
if (!str_contains($source, 'GameAccountPrivilegePolicy::ownerRequired(') ||
    !str_contains($source, 'requireRank(40);')) {
    throw new RuntimeException('Active admin handler is missing owner-rank enforcement.');
}
echo "MUCHOCORE_ADMIN_GAME_OWNER_PRIVILEGE_OK\n";
