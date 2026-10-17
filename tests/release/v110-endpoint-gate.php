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
$contracts = is_array($registry['contracts'] ?? null) ? $registry['contracts'] : [];
$report = [
    'target_release' => '1.1.0',
    'ready' => $issues === [],
    'endpoint_count' => count($contracts),
    'inventory_only_count' => count(array_filter($contracts,
        static fn(mixed $entry): bool => is_array($entry) && ($entry['inventory_only'] ?? false) === true)),
    'release_gated_count' => count(array_filter($contracts,
        static fn(mixed $entry): bool => is_array($entry) && ($entry['release_gate'] ?? false) === true)),
    'blocking_finding_count' => count($issues),
    'issues' => $issues,
];
if (in_array('--json', $argv, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($issues && !in_array('--report', $argv, true) ? 1 : 0);
}
if (!$issues) {
    echo "MUCHOCORE_V110_ENDPOINT_RELEASE_GATE_OK\n";
    exit(0);
}

fwrite(STDERR, sprintf("Endpoint certification: %d routes, %d inventory-only, %d release-gated.\n",
    $report['endpoint_count'], $report['inventory_only_count'], $report['release_gated_count']));
foreach (array_slice($issues, 0, 20) as $issue) {
    fwrite(STDERR, "NOT READY: {$issue}\n");
}
if (count($issues) > 20) {
    fwrite(STDERR, '... '.(count($issues)-20)." more findings. Use --json for the full report.\n");
}

if (in_array('--report', $argv, true)) {
    echo "Certification report: " . count($issues) . " blocking findings.\n";
    exit(0);
}

exit(1);
