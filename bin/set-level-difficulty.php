<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

$levelId = isset($argv[1]) ? (int)$argv[1] : 0;
$diffCode = $argv[2] ?? null;
$ratingCode = $argv[3] ?? null;

if ($levelId <= 0 || !$diffCode) {
    echo "Usage: php bin/set-level-difficulty.php <LEVEL_ID> <DIFFICULTY_CODE> [RATING_CODE]\n";
    echo "Difficulties: nightmare, impossible, unhuman, none\n";
    echo "Ratings: celestial, divine, none\n";
    exit(1);
}

$pdo = (new Database())->connection();

$diffMap = [
    'none' => 0,
    'nightmare' => 1,
    'impossible' => 2,
    'unhuman' => 3
];

$ratingMap = [
    'none' => 0,
    'celestial' => 1,
    'divine' => 2
];

$diffId = $diffMap[strtolower($diffCode)] ?? null;
$ratingId = $ratingCode ? ($ratingMap[strtolower($ratingCode)] ?? 0) : 0;

if ($diffId === null) {
    echo "Unknown difficulty: {$diffCode}\n";
    exit(1);
}

$stmt = $pdo->prepare('UPDATE levels SET custom_difficulty = :diff, custom_rate_tier = :rating WHERE level_id = :id');
$stmt->execute([
    ':diff' => $diffId,
    ':rating' => $ratingId,
    ':id' => $levelId
]);

if ($stmt->rowCount() === 0) {
    echo " Level #{$levelId} was not found or the parameters did not change.\n";
    exit(0);
}

echo " Level #{$levelId} updated: difficulty {$diffCode} ({$diffId}), rating " . ($ratingCode ?? 'none') . " ({$ratingId})\n";
