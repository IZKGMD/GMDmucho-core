<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach ([
    'GdHash.php',
    'GdXor.php',
    'GdLegacyText.php',
    'ProtocolText.php',
    'GdLevelListEncoder.php',
    'GdLevelDownloadEncoder.php',
] as $protocol) {
    require_once $root . '/src/Protocol/' . $protocol;
}
require_once $root . '/public/admin/core/AdminUi.php';

use MuchoCore\Protocol\GdLevelListEncoder;
use MuchoCore\Protocol\GdLevelDownloadEncoder;

function ratingAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Demon rating regression: ' . $message);
    }
}

function wireField(string $response, int $key): string
{
    $pairs = explode(':', explode('#', $response, 2)[0]);
    for ($i = 0, $length = count($pairs) - 1; $i < $length; $i += 2) {
        if ($pairs[$i] === (string)$key) {
            return $pairs[$i + 1];
        }
    }
    throw new RuntimeException('Missing GD wire field ' . $key);
}

$ratingUi = (string)file_get_contents($root . '/public/admin/pages/rating.php');
$rateAction = (string)file_get_contents($root . '/public/admin/index.php');
$commentCommands = (string)file_get_contents($root . '/src/Interaction/CommentService.php');
$moderation = (string)file_get_contents($root . '/src/Moderation/ModerationRepository.php');
$cli = (string)file_get_contents($root . '/bin/rate.php');
$adminCli = (string)file_get_contents($root . '/bin/mucho-admin');
$advanced = (string)file_get_contents($root . '/public/admin/advanced-actions.php');
$adminApi = (string)file_get_contents($root . '/public/api/v2/admin-client.php');

$profiles = [
    'easy-demon' => 3,
    'medium-demon' => 4,
    'hard-demon' => 0,
    'insane-demon' => 5,
    'extreme-demon' => 6,
];

$base = [
    'level_id' => 222,
    'account_id' => 3,
    'user_id' => 3,
    'username' => 'izk',
    'name' => 'Demon Wire Test',
    'description' => 'GD 2.209 protocol',
    'level_data' => 'TEST_LEVEL_DATA',
    'level_version' => 1,
    'game_version' => 22,
    'difficulty' => 50,
    'downloads' => 12,
    'audio_track' => 0,
    'likes' => 1,
    'demon' => 1,
    'auto_level' => 0,
    'stars' => 10,
    'featured' => 1,
    'epic' => 2,
    'object_count' => 100,
    'length' => 2,
    'original_level_id' => 0,
    'two_player' => 0,
    'coins' => 1,
    'coins_verified' => 1,
    'requested_stars' => 0,
    'ldm' => 0,
    'song_id' => 0,
    'extra_string' => '',
    'created_at' => '2026-01-01 12:00:00',
    'updated_at' => '2026-01-02 12:00:00',
    'wt' => 0,
    'wt2' => 0,
    'settings_string' => '',
    'copy_password' => '0',
    'song_ids' => '',
    'sfx_ids' => '',
    'ts' => 0,
];

$listEncoder = new GdLevelListEncoder();
$downloadEncoder = new GdLevelDownloadEncoder();

foreach ($profiles as $profile => $expected) {
    $row = $base;
    $row['demon_difficulty'] = $expected;

    ratingAssert(adminDifficultyProfile($row) === $profile,
        $profile . ' admin icon must match GD field 43');

    // Exercise native serializer for every Demon type on 2.209 and 2.1.
    foreach ([[22, 51], [21, 35]] as [$gv, $bv]) {
        $list = $listEncoder->encode([$row], 1, 0, 10, $gv, $bv);
        $download = $downloadEncoder->encode($row, $gv, false, $bv);
        foreach (['search' => $list, 'download' => $download] as $type => $wire) {
            ratingAssert(wireField($wire, 43) === (string)$expected,
                $profile . ' ' . $type . ' field 43 GD ' . $gv);
            ratingAssert(wireField($wire, 17) === '1',
                $profile . ' ' . $type . ' demon flag');
            ratingAssert(wireField($wire, 18) === '10',
                $profile . ' ' . $type . ' stars');
            ratingAssert(wireField($wire, 9) === '50',
                $profile . ' ' . $type . ' difficulty');
        }
    }

    $escaped = preg_quote($profile, '/');
    $pattern = "/'{$escaped}'\\s*=>\\s*\\[[^\\]]*'demon_difficulty'\\s*=>\\s*{$expected}\\s*,/s";
    ratingAssert(preg_match($pattern, $ratingUi) === 1,
        $profile . ' Rating Studio selection');
    ratingAssert(preg_match($pattern, $rateAction) === 1,
        $profile . ' PHP8 persisted state');
}

ratingAssert(
    adminDifficultyProfile(['auto_level'=>0,'demon'=>0,'difficulty'=>50]) === 'insane',
    'modern in-game 50 difficulty displays Insane icon'
);
ratingAssert(
    adminDifficultyProfile(['auto_level'=>1,'demon'=>0,'difficulty'=>50]) === 'auto',
    'Auto flag overrides shared 50 difficulty code'
);

foreach ([
    'legacy moderation presets preserve GD Demon codes' => str_contains($advanced, "'demon-extreme'=>6"),
    'legacy moderator updates difficulty 50' => str_contains($advanced, 'difficulty=50,'),
    'legacy moderator clears custom subtype' => str_contains($advanced, 'custom_difficulty=0,'),
    'admin API forbids 1 and 2 Demon field codes' => str_contains($adminApi, 'if ($demon && !in_array($demonDifficulty, [0,3,4,5,6], true))'),
    'admin API clears stale request state' => str_contains($adminApi, 'requested_stars=0'),
] as $name => $valid) {
    ratingAssert($valid, $name);
}

foreach ([
    'php8 admin clears queue' => str_contains($rateAction, 'requested_stars=0'),
    'php8 admin invalidates cached search pages' => str_contains($rateAction, "->deletePrefix('mucho:levels:')"),
    'php8 admin resets incompatible custom difficulty' => str_contains($rateAction, 'custom_difficulty=0'),
    'moderation re-rate preserves subtype' => str_contains($moderation, 'THEN demon_difficulty ELSE 0 END'),
    'moderation removes pending requests' => substr_count($moderation, 'requested_stars=0') >= 3,
    'comment !demon maps ordinal input' => str_contains($commentCommands, '[1=>3,2=>4,3=>0,4=>5,5=>6]'),
    'comment !demon stores wire value' => str_contains($commentCommands, '=> $wireDemonDifficulty'),
    'rate.php uses canonical mapping' => str_contains($cli, '[0=>0,1=>3,2=>4,3=>0,4=>5,5=>6]'),
    'admin CLI uses canonical mapping' => str_contains($adminCli, '[0=>0,1=>3,2=>4,3=>0,4=>5,5=>6]'),
] as $name => $valid) {
    ratingAssert($valid, $name);
}

echo "MUCHOCORE_RATING_DEMON_PROTOCOL_OK\n";
