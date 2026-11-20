<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bin/mucho-repair-gd2209-qa-levels.php';

function checkFixture(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$original = 'kS38,1_40_2_125_3_255_11_255_12_255_13_255_4_-1_6_1000_7_1_15_1_18_0_8_1;'
    . '1,8,2,355,3,15;1,8,2,385,3,15;';
$source = base64_encode(gzencode($original, 9));
$unique = [];
for ($id = 1; $id <= 29; $id++) {
    $stored = fixtureWalkthrough($source, $id);
    $unique[$stored] = true;
    $level = gzdecode(base64_decode($stored, true));
    checkFixture(is_string($level), 'Fixture must remain gzip-decodable');
    checkFixture(strpos($level, 'kS38,') === 0, 'Fixture must preserve editor settings');
    checkFixture(substr_count($level, ';') === 7, 'Six objects plus editor header');
    checkFixture(strpos($level, '1,8,2,355,3,15;') === false,
        'Original obstacle geometry must not be retained');
    $objects = explode(';', $level);
    for ($i = 1; $i <= 6; $i++) {
        checkFixture(preg_match('/^1,8,2,[0-9]+,3,[0-9]+$/D', $objects[$i]) === 1,
            'Only intended simple flying blocks are permitted');
        preg_match('/,3,([0-9]+)$/D', $objects[$i], $y);
        checkFixture((int)$y[1] >= 600, 'No blocks may obstruct ground-level cube path');
    }
}
checkFixture(count($unique) === 29, 'Cache-safe independent QA geometries');
try {
    fixtureWalkthrough('not valid gzipped data', 1);
    throw new RuntimeException('Rejected input unexpectedly passed');
} catch (RuntimeException $e) {
    checkFixture(strpos($e->getMessage(), 'Fixture') !== false,
        'Malformed input must fail closed');
}
echo "GD2209_QA_PLAYABLE_FIXTURE_GENERATOR_OK\n";
