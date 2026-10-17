<?php

declare(strict_types=1);

/**
 * GD 2.209 known new endpoint route inventory (GD Docs changelog).
 * Presence of a registered route DOES NOT prove native wire compatibility.
 *
 * php bin/mucho-gd2209-coverage.php [--json] [--fail-missing]
 */
$known = [
    'getGJEditorSounds',
    'uploadGJEditorSound',
    'downloadGJEditorSound',
    'deleteGJEditorSound',
    'updateGJSoundDesc',
    'getGJLevelControls',
    'uploadGJLevelControls',
    'deleteGJLevelControls',
    'getGJDeathData',
    'uploadGJDeathData',
    'getGJThreads',
    'getGJThreadMessages',
    'getGJThreadMembers',
    'getGJThreadInvites',
    'sendGJThreadMessage',
    'updateGJThread',
    'searchGJThreadMessages',
    'claimGJNotificationReward',
    'getGJNotifications',
    'muteGJUser',
    'reportGJContent',
    'followGJUser',
    'unfollowGJUser',
    'getGJFollowing',
    'syncGJFollowing',
    'getGJSongUsage',
    'getGJUserSearch',
    'getGJAudioFilesize',
    'getGJContentStatus',
    'openedRegister',
    'adminGetUserHistory',
    'adminGetReportedContent',
    'adminClearReportedContent',
    'adminGetReportQueue',
    'adminGetAbuseInfo',
    'adminGetAccountReview',
    'adminThreadAction',
    'adminDeleteUserComments',
    'adminCommentBan',
    'adminUploadBan',
    'adminMessageBan',
    'adminFreezeAccount',
    'adminTrendingBan',
    'adminNameLock',
    'adminCommentUnban',
    'adminUploadUnban',
    'adminCloseCase',
    'adminMessageUnban',
    'adminUnfreezeAccount',
    'adminTrendingUnban',
    'adminNameUnlock',
    'deleteGJComments',
];
$source = file_get_contents(dirname(__DIR__) . '/src/Core/Application.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read Application.php\n");
    exit(2);
}
preg_match_all("~\\$route\\('/([^']+)'~", $source, $found);
$implementedPaths = array_fill_keys(
    array_map('strtolower', $found[1] ?? []), true
);
$implemented = [];
$missing = [];
foreach ($known as $endpoint) {
    if (isset($implementedPaths[strtolower($endpoint)])) {
        $implemented[] = $endpoint;
    } else {
        $missing[] = $endpoint;
    }
}
$result = [
    'label' => 'GD 2.209 new route inventory (not wire certification)',
    'reference' => 'https://github.com/gd-docs/gd-docs/blob/main/docs/changelog.md',
    'total' => count($known),
    'registered' => count($implemented),
    'missing' => count($missing),
    'registered_routes' => $implemented,
    'missing_routes' => $missing,
    'real_client_certified' => false,
];

if (in_array('--json', $argv, true)) {
    echo json_encode($result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} else {
    echo "GD 2.209 known server endpoint names: {$result['total']}\n";
    echo "Registered: {$result['registered']}; missing: {$result['missing']}\n";
    echo "Missing routes:\n";
    foreach ($missing as $name) {
        echo "  - {$name}.php\n";
    }
    echo "Registration is not evidence of accepted native requests/responses.\n";
}
if (in_array('--fail-missing', $argv, true) && $missing !== []) {
    exit(1);
}
