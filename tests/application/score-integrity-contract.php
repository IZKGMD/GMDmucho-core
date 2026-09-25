<?php

declare(strict_types=1);

use MuchoCore\Score\ScoreIntegrity;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$trusted = ScoreIntegrity::evaluateRegular(
    42, 100, 50, 120, 'progress'
);

if ($trusted['status'] !== ScoreIntegrity::STATUS_TRUSTED) {
    throw new RuntimeException('normal score must remain trusted');
}

$suspicious = ScoreIntegrity::evaluateRegular(
    100, 1, 1, 2, ''
);

if ($suspicious['status'] !== ScoreIntegrity::STATUS_SUSPICIOUS) {
    throw new RuntimeException('impossible regular score was not flagged');
}

$platformer = ScoreIntegrity::evaluatePlatformer(
    100,
    500
);

if ($platformer['status'] !== ScoreIntegrity::STATUS_SUSPICIOUS) {
    throw new RuntimeException('impossible platformer score was not flagged');
}

if (!ScoreIntegrity::quarantineEnabled()) {
    putenv('MUCHO_ANTICHEAT_QUARANTINE=1');
    $_ENV['MUCHO_ANTICHEAT_QUARANTINE'] = '1';
}

if (!ScoreIntegrity::quarantineEnabled()) {
    throw new RuntimeException('quarantine feature flag contract failed');
}

echo "score-integrity-contract: OK
";
