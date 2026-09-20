<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

/*
 * Compatibility guard based on the established Cvolton/GMDprivateServer
 * 1.0-2.2 endpoint surface. It is intentionally static so CI never depends
 * on an external repository being available.
 */

$application = file_get_contents(
    dirname(__DIR__) . '/src/Core/Application.php'
);
$router = file_get_contents(
    dirname(__DIR__) . '/src/Routing/Router.php'
);

if ($application === false || $router === false) {
    throw new RuntimeException('Unable to read compatibility sources.');
}

$routes = [];
if (
    preg_match_all(
        '/\\$route\\(\\s*\'([^\']+)\'/',
        strtolower($application),
        $matches
    )
) {
    $routes = array_fill_keys($matches[1], true);
}

$aliases = [];
if (
    preg_match_all(
        '/\'([^\']+)\'\\s*=>\\s*\'([^\']+)\'/',
        strtolower($router),
        $matches
    )
) {
    foreach ($matches[1] as $i => $from) {
        $aliases[$from] = $matches[2][$i];
    }
}

function resolveAlias(string $name, array $aliases): string
{
    $path = '/' . strtolower($name);
    $seen = [];

    while (isset($aliases[$path]) && !isset($seen[$path])) {
        $seen[$path] = true;
        $path = $aliases[$path];
    }

    return ltrim($path, '/');
}

$missing = [];

foreach (["acceptGJFriendRequest20","blockGJUser20","deleteGJAccComment20","deleteGJComment20","deleteGJFriendRequests20","deleteGJLevelList","deleteGJLevelUser20","deleteGJMessages20","downloadGJLevel","downloadGJLevel19","downloadGJLevel20","downloadGJLevel21","downloadGJLevel22","downloadGJMessage20","getAccountURL","getCustomContentURL","getGJAccountComments20","getGJChallenges","getGJCommentHistory","getGJComments","getGJComments19","getGJComments20","getGJComments21","getGJCreators","getGJCreators19","getGJDailyLevel","getGJFriendRequests20","getGJGauntlets","getGJGauntlets21","getGJLevelLists","getGJLevelScores","getGJLevelScores211","getGJLevelScoresPlat","getGJLevels","getGJLevels19","getGJLevels20","getGJLevels21","getGJMapPacks","getGJMapPacks20","getGJMapPacks21","getGJMessages20","getGJRewards","getGJScores","getGJScores19","getGJScores20","getGJSongInfo","getGJTopArtists","getGJUserInfo20","getGJUserList20","getGJUsers20","likeGJItem","likeGJItem19","likeGJItem20","likeGJItem21","likeGJItem211","likeGJLevel","rateGJDemon21","rateGJStars20","rateGJStars211","readGJFriendRequest20","removeGJFriend20","reportGJLevel","requestUserAccess","suggestGJStars20","unblockGJUser20","updateGJAccSettings20","updateGJDesc20","updateGJUserScore","updateGJUserScore19","updateGJUserScore20","updateGJUserScore21","updateGJUserScore22","uploadFriendRequest20","uploadGJAccComment20","uploadGJComment","uploadGJComment19","uploadGJComment20","uploadGJComment21","uploadGJLevel","uploadGJLevel19","uploadGJLevel20","uploadGJLevel21","uploadGJLevelList","uploadGJMessage20"] as $endpoint) {
    $name = strtolower($endpoint);

    if (isset($routes['/' . $name])) {
        continue;
    }

    $resolved = resolveAlias($endpoint, $aliases);

    if (isset($routes['/' . $resolved])) {
        continue;
    }

    $missing[] = $endpoint;
}

if ($missing !== []) {
    fwrite(
        STDERR,
        "Missing legacy endpoint surface:\n - " .
        implode("\n - ", $missing) .
        "\n"
    );
    exit(1);
}

echo "LEGACY_ENDPOINT_SURFACE_OK (84 endpoints)\n";
