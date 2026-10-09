<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Client\ClientBridgeController;
use MuchoCore\Client\ClientFeatureRegistry;
use MuchoCore\Http\Request;
use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginEventBus;
use MuchoCore\Routing\Router;

$check = static function (bool $condition, string $reason): void {
    if (!$condition) {
        throw new RuntimeException('FAIL MuchoClient: ' . $reason);
    }
    echo 'PASS ' . $reason . PHP_EOL;
};

$registry = new ClientFeatureRegistry();
$registry->add(
    'welcome',
    'Welcome',
    'Demonstration of a safe menu item',
    '/extensions/welcome'
);

$pdo = new class extends PDO {
    public function __construct() {}
};

$context = new PluginContext(
    $pdo,
    new Router(),
    new PluginEventBus(),
    ['client_features'],
    'Test plugin',
    $registry
);

$context->clientFeature(
    'custom-feature',
    'Custom Feature',
    'Provided by a PHP server plugin',
    '/extensions/custom'
);

$check(count($registry->all()) === 2, 'plugin SDK advertises two safe client modules');

foreach ([
    ['bad', 'bad', 'bad', 'https://evil.example/path'],
    ['bad', 'bad', 'bad', '//evil.example/path'],
    ['bad', 'bad', 'bad', '/api/clans/my'],
    ['bad id', 'bad', 'bad', '/extensions/bad'],
] as $bad) {
    $rejected = false;
    try {
        $registry->add(...$bad);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'invalid external/untrusted client module rejected');
}

$reserved = false;
try {
    $registry->add('clans', 'Fake Clans', '', '/extensions/fake');
} catch (InvalidArgumentException) {
    $reserved = true;
}
$check($reserved, 'plugin cannot override core Clans feature');

$duplicate = false;
try {
    $registry->add('welcome', 'duplicate', '', '/extensions/welcome');
} catch (InvalidArgumentException) {
    $duplicate = true;
}
$check($duplicate, 'duplicate feature rejected');

$denied = false;
try {
    (new PluginContext(
        $pdo,
        new Router(),
        new PluginEventBus(),
        ['routes'],
        'Unprivileged plugin',
        $registry
    ))->clientFeature('privilege', 'Denied', '', '/extensions/denied');
} catch (RuntimeException) {
    $denied = true;
}
$check($denied, 'client_features SDK permission is enforced');

$bridge = new ClientBridgeController($registry, '1.1.0');

$request = static fn(string $method, string $path, array $post = []): Request =>
    new Request($method, $path, [], $post, []);

$manifestResponse = $bridge->manifest($request('GET', '/muchoclient/manifest'));
$manifest = json_decode($manifestResponse->body, true, 8, JSON_THROW_ON_ERROR);

$check($manifestResponse->status === 200 &&
    $manifestResponse->contentType === 'application/json; charset=utf-8',
    'manifest returns JSON transport');
$check($manifest['client']['id'] === 'izkgmd.muchoclient' &&
    $manifest['client']['protocol'] === 1 &&
    $manifest['client']['required_for_extensions'] === true &&
    $manifest['client']['required_for_legacy_gameplay'] === false,
    'client requirements do not break GD 1.x or vanilla gameplay');
$check(
    count($manifest['features']) === 3 &&
    $manifest['features'][0]['id'] === 'clans' &&
    $manifest['features'][1]['id'] === 'welcome' &&
    $manifest['features'][2]['id'] === 'custom-feature',
    'built-in and SDK features share a single client manifest'
);
$check(
    !str_contains($manifestResponse->body, 'password') &&
    !str_contains($manifestResponse->body, 'secret') &&
    !str_contains($manifestResponse->body, 'token'),
    'public manifest contains no client secrets or tokens'
);

$check(
    $bridge->manifest($request('POST', '/muchoclient/manifest'))->status === 405,
    'manifest rejects non-GET requests'
);
$check(
    $bridge->negotiate($request('GET', '/muchoclient/negotiate'))->status === 405,
    'negotiation rejects non-POST requests'
);

$valid = json_decode(
    $bridge->negotiate($request('POST', '/muchoclient/negotiate', [
        'client_version' => '0.1.0',
        'protocol' => '1',
    ]))->body,
    true,
    8,
    JSON_THROW_ON_ERROR
);
$check($valid['compatible'] === true &&
    $valid['status'] === 'ready' &&
    $valid['session_token'] === null,
    'compatible client succeeds without issuing false auth credentials'
);

$old = json_decode(
    $bridge->negotiate($request('POST', '/muchoclient/negotiate', [
        'client_version' => '0.0.9',
        'protocol' => '1',
    ]))->body,
    true,
    8,
    JSON_THROW_ON_ERROR
);
$check($old['compatible'] === false &&
    $old['status'] === 'upgrade_required',
    'outdated client cannot activate extra modules');

$invalidProtocol = json_decode(
    $bridge->negotiate($request('POST', '/muchoclient/negotiate', [
        'client_version' => '0.1.0',
        'protocol' => '5',
    ]))->body,
    true,
    8,
    JSON_THROW_ON_ERROR
);
$check($invalidProtocol['compatible'] === false,
    'protocol mismatch fails closed');

$check(
    $bridge->negotiate($request('POST', '/muchoclient/negotiate', [
        'client_version' => 'latest',
        'protocol' => '1',
    ]))->status === 400,
    'untrusted client version metadata rejected'
);

$source = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Core/Application.php'
);
$check(
    str_contains($source, "'/muchoclient/manifest'") &&
    str_contains($source, "'/muchoclient/negotiate'"),
    'real application registers MuchoClient endpoints'
);

$geodeRoot = dirname(__DIR__, 2) . '/clients/geode/muchoclient';
$metadata = json_decode(
    (string)file_get_contents($geodeRoot . '/mod.json'),
    true,
    16,
    JSON_THROW_ON_ERROR
);
$clientCpp = (string)file_get_contents($geodeRoot . '/src/main.cpp');

$check(
    ($metadata['id'] ?? '') === 'izkgmd.muchoclient' &&
    ($metadata['version'] ?? '') === 'v0.2.1' &&
    isset($metadata['gd']['win'], $metadata['gd']['android']) &&
    isset($metadata['settings']['server-url']),
    'Geode companion metadata declares compatible client id and settings'
);
$check(
    str_contains($clientCpp, '"/muchoclient/manifest"') &&
    str_contains($clientCpp, '.followRedirects(false)') &&
    !str_contains($clientCpp, 'certVerification(false)'),
    'native client scaffold uses discovery path and retains HTTPS verification'
);


// End-to-end plugin MVP contract: a standalone server plugin advertises one
// menu item and serves its JSON payload without installing any client code.
$sampleRoot = dirname(__DIR__, 2) . '/examples/muchoclient-welcome';
$sampleManifest = json_decode(
    (string)file_get_contents($sampleRoot . '/manifest.json'),
    true,
    8,
    JSON_THROW_ON_ERROR
);
$check(
    ($sampleManifest['enabled'] ?? false) === true &&
    in_array('routes', $sampleManifest['permissions'] ?? [], true) &&
    in_array('client_features', $sampleManifest['permissions'] ?? [], true),
    'first plugin declares only necessary SDK permissions'
);

$sampleRegistry = new ClientFeatureRegistry();
$sampleRouter = new Router();
$sampleContext = new PluginContext(
    $pdo,
    $sampleRouter,
    new PluginEventBus(),
    $sampleManifest['permissions'],
    'MuchoClient Welcome',
    $sampleRegistry
);
$samplePlugin = require $sampleRoot . '/plugin.php';
$check(
    $samplePlugin instanceof \MuchoCore\Plugin\PluginInterface,
    'sample plugin implements the actual PHP plugin SDK'
);
$samplePlugin->register($sampleContext);
$features = $sampleRegistry->all();
$check(
    count($features) === 1 &&
    $features[0]['id'] === 'welcome' &&
    $features[0]['entrypoint'] === '/extensions/welcome',
    'live server plugin advertises a same-origin menu button'
);
$sampleResponse = $sampleRouter->dispatch(
    $request('GET', '/extensions/welcome')
);
$sampleBody = json_decode($sampleResponse->body, true, 8, JSON_THROW_ON_ERROR);
$check(
    $sampleResponse->status === 200 &&
    $sampleResponse->contentType === 'application/json; charset=utf-8' &&
    $sampleBody['schema_version'] === 1 &&
    $sampleBody['feature_id'] === 'welcome' &&
    str_contains($sampleBody['message'], 'server plugin'),
    'feature click receives safe JSON from the PHP server plugin'
);
$sampleCatalog = json_decode(
    (new ClientBridgeController($sampleRegistry, '1.1.0'))
        ->manifest($request('GET', '/muchoclient/manifest'))->body,
    true,
    8,
    JSON_THROW_ON_ERROR
);
$check(
    count($sampleCatalog['features']) === 2 &&
    $sampleCatalog['features'][1]['id'] === 'welcome',
    'sample plugin appears alongside core modules in the global catalog'
);
$check(
    str_contains($clientCpp, '"/muchoclient/negotiate"') &&
    str_contains($clientCpp, 'MuchoFeaturesPopup') &&
    str_contains($clientCpp, 'safeFeaturePath') &&
    !str_contains($clientCpp, 'certVerification(false)'),
    'client source performs explicit handshake and displays same-origin plugin JSON'
);

echo "MUCHOCORE_MUCHOCLIENT_BRIDGE_OK\n";
