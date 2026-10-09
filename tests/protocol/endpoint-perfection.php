<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Routing/Router.php';

use MuchoCore\Routing\Router;

function endpointGateFail(string $message): never
{
    fwrite(STDERR, "FAIL endpoint-perfection: {$message}\n");
    exit(1);
}

function endpointGatePass(string $message): void
{
    echo "PASS endpoint-perfection: {$message}\n";
}

$root = dirname(__DIR__, 2);
$manifestPath = __DIR__ . '/endpoint-contracts.json';

$json = file_get_contents($manifestPath);
if ($json === false) {
    endpointGateFail('cannot read endpoint-contracts.json');
}

try {
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    endpointGateFail('invalid JSON: ' . $e->getMessage());
}

if (($manifest['schema_version'] ?? null) !== 1) {
    endpointGateFail('schema_version must be 1');
}

$requiredDimensions = $manifest['policy']['required_dimensions'] ?? null;
$allowedStatuses = $manifest['policy']['allowed_statuses'] ?? null;
$contracts = $manifest['contracts'] ?? null;

if (!is_array($requiredDimensions) || $requiredDimensions === []) {
    endpointGateFail('required_dimensions must be a non-empty array');
}
if (!is_array($allowedStatuses) || $allowedStatuses === []) {
    endpointGateFail('allowed_statuses must be a non-empty array');
}
if (!is_array($contracts) || $contracts === []) {
    endpointGateFail('contracts must be a non-empty array');
}

$router = new Router();
$ids = [];
$aliasOwners = [];
$fixtureAliases = [];

foreach ($contracts as $index => $contract) {
    if (!is_array($contract)) {
        endpointGateFail("contract #{$index} must be an object");
    }

    $id = $contract['id'] ?? null;
    $method = $contract['method'] ?? null;
    $canonical = $contract['canonical_path'] ?? null;
    $aliases = $contract['aliases'] ?? null;
    $families = $contract['families'] ?? null;

    if (!is_string($id) || $id === '') {
        endpointGateFail("contract #{$index} has no id");
    }
    if (isset($ids[$id])) {
        endpointGateFail("duplicate contract id {$id}");
    }
    $ids[$id] = true;

    $inventoryOnly = ($contract['inventory_only'] ?? false) === true;
    if (
        !in_array($method, ['POST', 'ANY'], true) ||
        ($method === 'ANY' && !$inventoryOnly)
    ) {
        endpointGateFail("{$id}: unverified route inventory may use ANY, certified endpoints require POST");
    }
    if ($inventoryOnly && ($contract['release_gate'] ?? false) === true) {
        endpointGateFail("{$id}: inventory-only route cannot become a release gate");
    }
    if ($inventoryOnly && ($contract['families'] ?? []) !== ['unverified']) {
        endpointGateFail("{$id}: unverified inventory must not claim tested client families");
    }
    if (!is_string($canonical) || !str_starts_with($canonical, '/')) {
        endpointGateFail("{$id}: invalid canonical_path");
    }
    if (!is_array($aliases) || $aliases === []) {
        endpointGateFail("{$id}: aliases must be non-empty");
    }
    if (!is_array($families) || $families === []) {
        endpointGateFail("{$id}: families must be non-empty");
    }

    foreach ($aliases as $alias) {
        if (!is_string($alias) || $alias === '') {
            endpointGateFail("{$id}: invalid alias");
        }

        $normalized = $router->normalizePath($alias);
        if ($normalized !== $canonical) {
            endpointGateFail(
                "{$id}: alias {$alias} normalizes to {$normalized}, expected {$canonical}"
            );
        }

        $aliasKey = strtolower($alias);
        if (isset($aliasOwners[$aliasKey]) && $aliasOwners[$aliasKey] !== $id) {
            endpointGateFail("alias {$alias} belongs to multiple contracts");
        }
        $aliasOwners[$aliasKey] = $id;
        $fixtureAliases[$normalized] = true;
    }

    foreach ($requiredDimensions as $dimension) {
        if (!is_string($dimension) || $dimension === '') {
            endpointGateFail('invalid dimension name in policy');
        }

        $entry = $contract[$dimension] ?? null;
        if (!is_array($entry)) {
            endpointGateFail("{$id}: missing dimension {$dimension}");
        }

        $status = $entry['status'] ?? null;
        if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
            endpointGateFail("{$id}: invalid status for {$dimension}");
        }

        $evidence = $entry['evidence'] ?? null;
        if (!is_array($evidence)) {
            endpointGateFail("{$id}: {$dimension}.evidence must be an array");
        }

        if ($status === 'covered' && $evidence === []) {
            endpointGateFail("{$id}: covered {$dimension} requires evidence");
        }
        if ($inventoryOnly && $status !== 'pending' && $status !== 'n/a') {
            endpointGateFail("{$id}: inventory-only route cannot claim certified evidence");
        }

        foreach ($evidence as $evidencePath) {
            if (!is_string($evidencePath) || $evidencePath === '') {
                endpointGateFail("{$id}: invalid evidence path in {$dimension}");
            }
            if (!is_file($root . '/' . $evidencePath)) {
                endpointGateFail("{$id}: missing evidence file {$evidencePath}");
            }
        }

        if ($dimension === 'performance_budget') {
            $p95 = $entry['p95_ms'] ?? null;
            if (
                !($inventoryOnly && $status === 'pending' && $p95 === null) &&
                (!is_int($p95) || $p95 <= 0)
            ) {
                endpointGateFail("{$id}: a measured performance budget requires a positive p95 target");
            }
        }
    }

    if (($contract['release_gate'] ?? false) === true) {
        foreach ($requiredDimensions as $dimension) {
            $status = $contract[$dimension]['status'];
            if (!in_array($status, ['covered', 'n/a'], true)) {
                endpointGateFail("{$id}: release-gated dimension {$dimension} is {$status}");
            }
        }
    }
}

// Keep the endpoint registry in sync with every statically declared core
// route. Plugin routes are intentionally dynamic; /health is not a game API.
$applicationSource = file_get_contents($root . '/src/Core/Application.php');
if (!is_string($applicationSource)) {
    endpointGateFail('cannot read core application route definitions');
}
preg_match_all(
    '~\$route\(\s*[\x27\x22](/[^\x27\x22]+)[\x27\x22]~',
    $applicationSource,
    $routeMatches
);
$applicationRoutes = $routeMatches[1] ?? [];
if (!$applicationRoutes) {
    endpointGateFail('no application routes were discovered');
}

$declaredCanonical = [];
foreach ($applicationRoutes as $rawRoute) {
    $canonical = $router->normalizePath($rawRoute);
    if ($canonical === '/health') {
        continue;
    }
    $declaredCanonical[$canonical] = true;
}

$contractCanonicals = [];
foreach ($contracts as $contract) {
    $canonical = $contract['canonical_path'];
    if (isset($contractCanonicals[$canonical])) {
        endpointGateFail("duplicate canonical endpoint {$canonical}");
    }
    $contractCanonicals[$canonical] = true;
}

foreach ($declaredCanonical as $canonical => $_) {
    if (!isset($contractCanonicals[$canonical])) {
        endpointGateFail(
            "application route {$canonical} is missing from endpoint certification inventory"
        );
    }
}

foreach ($contractCanonicals as $canonical => $_) {
    if (!isset($declaredCanonical[$canonical])) {
        endpointGateFail(
            "endpoint certification includes unregistered core route {$canonical}"
        );
    }
}

endpointGatePass(
    count($declaredCanonical) . ' canonical application game routes inventoried'
);

$fixtureFiles = glob($root . '/tests/client-fixtures/*/endpoints.json') ?: [];
foreach ($fixtureFiles as $fixtureFile) {
    $fixtureJson = file_get_contents($fixtureFile);
    if ($fixtureJson === false) {
        endpointGateFail("cannot read fixture {$fixtureFile}");
    }

    try {
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        endpointGateFail("invalid fixture JSON {$fixtureFile}: " . $e->getMessage());
    }

    foreach (($fixture['endpoints'] ?? []) as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }

        $path = $endpoint['path'] ?? null;
        if (!is_string($path) || $path === '') {
            endpointGateFail("fixture {$fixtureFile} contains an endpoint without path");
        }

        $normalized = $router->normalizePath($path);
        if (!isset($fixtureAliases[$normalized])) {
            endpointGateFail(
                "captured real-client endpoint {$path} ({$normalized}) has no perfection contract"
            );
        }
    }
}

endpointGatePass(count($contracts) . ' endpoint contracts validated');
endpointGatePass(count($fixtureFiles) . ' real-client fixture registries linked');
echo "MUCHOCORE_ENDPOINT_PERFECTION_OK\n";
