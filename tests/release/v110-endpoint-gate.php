<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Release/V110EndpointGate.php';

use MuchoCore\Release\V110EndpointGate;

$path = dirname(__DIR__, 2) . '/tests/protocol/endpoint-contracts.json';

try {
    $registry = json_decode(
        (string)file_get_contents($path),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
} catch (\Throwable) {
    fwrite(STDERR, "Endpoint registry could not be parsed.\n");
    exit(1);
}

$issues = V110EndpointGate::issues(is_array($registry) ? $registry : []);
if (!$issues) {
    echo "MUCHOCORE_V110_ENDPOINT_RELEASE_GATE_OK\n";
    exit(0);
}

foreach ($issues as $issue) {
    fwrite(STDERR, "NOT READY: {$issue}\n");
}

if (in_array('--report', $argv, true)) {
    echo "Certification report: " . count($issues) . " blocking findings.\n";
    exit(0);
}

exit(1);
