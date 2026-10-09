<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Release/V110EndpointGate.php';

use MuchoCore\Release\V110EndpointGate;

$path = dirname(__DIR__, 2) . '/tests/protocol/endpoint-contracts.json';
$registry = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

if (V110EndpointGate::issues($registry) === []) {
    throw new RuntimeException('Incomplete 1.1 protocol registry must not pass release.');
}

$certified = $registry;
$certified['policy']['certification_complete'] = true;
foreach ($certified['contracts'] as &$contract) {
    $contract['release_gate'] = true;
    foreach ($certified['policy']['required_dimensions'] as $dimension) {
        $contract[$dimension]['status'] = 'covered';
    }
}
unset($contract);

if (V110EndpointGate::issues($certified) !== []) {
    throw new RuntimeException('Fully certified fixture rejected.');
}

$missing = $certified;
$missing['contracts'] = array_values(array_filter(
    $missing['contracts'],
    static fn(array $entry): bool => $entry['id'] !== 'account.login'
));
if (V110EndpointGate::issues($missing) === []) {
    throw new RuntimeException('Missing P0 account.login was not rejected.');
}

$pending = $certified;
$pending['contracts'][0]['performance_budget']['status'] = 'pending';
if (V110EndpointGate::issues($pending) === []) {
    throw new RuntimeException('Pending performance certification passed release.');
}

$noSignoff = $certified;
$noSignoff['policy']['certification_complete'] = false;
if (V110EndpointGate::issues($noSignoff) === []) {
    throw new RuntimeException('Missing full endpoint signoff passed release.');
}

$workflow = (string)file_get_contents(
    dirname(__DIR__, 2) . '/.github/workflows/release-stable.yml'
);
if (
    !str_contains($workflow, 'php tests/release/v110-endpoint-gate.php') ||
    !str_contains($workflow, "steps.marker.outputs.version == '1.1.0'")
) {
    throw new RuntimeException('Stable release workflow bypasses endpoint gate.');
}

echo "MUCHOCORE_V110_ENDPOINT_GATE_CONTRACT_OK\n";
