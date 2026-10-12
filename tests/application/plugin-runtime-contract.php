<?php

declare(strict_types=1);

use MuchoCore\Client\ClientBridgeController;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Plugin\PluginDependencyResolver;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$runtimeRoot = sys_get_temp_dir() . '/muchocore-plugin-runtime-' . bin2hex(random_bytes(6));
mkdir($runtimeRoot, 0770, true);
$runtimeChecks = 0;
$runtimeCheck = static function (bool $ok, string $label) use (&$runtimeChecks): void {
    if (!$ok) {
        throw new RuntimeException('Plugin runtime: ' . $label);
    }
    $runtimeChecks++;
    echo 'PASS plugin runtime: ' . $label . PHP_EOL;
};
$runtimePdo = new class extends PDO {
    public function __construct() {}
};
$makePlugin = static function (
    string $folder, string $id, string $body, array $extra = []
) use ($runtimeRoot): string {
    $directory = $runtimeRoot . '/' . $folder;
    mkdir($directory, 0770, true);
    file_put_contents($directory . '/manifest.json', json_encode(array_replace([
        'id' => $id,
        'name' => $id,
        'version' => '1.0.0',
        'api' => 1,
        'permissions' => ['events', 'routes', 'client_features'],
    ], $extra), JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/plugin.php', '<?php return new class implements ' .
        '\\MuchoCore\\Plugin\\PluginInterface { public function register(' .
        '\\MuchoCore\\Plugin\\PluginContext $context): void { ' . $body . ' } };');
    return $directory;
};
$diagnosticMap = static function (PluginManager $manager): array {
    $map = [];
    foreach ($manager->diagnostics() as $row) {
        $map[$row['id']] = $row;
    }
    return $map;
};
$manifestRow = static fn(string $id, array $dependencies = [], string $status = 'ready', string $version = '1.0.0'): array => [
    'id' => $id,
    'directory' => '/' . $id,
    'version' => $version,
    'dependencies' => $dependencies,
    'status' => $status,
    'reason' => '',
];
$anyVersion = ['min_version' => null, 'max_version' => null];
$oldRuntimeErrorLog = ini_get('error_log');
ini_set('error_log', $runtimeRoot . '/errors.log');

try {
    $resolver = new PluginDependencyResolver();
    $plan = $resolver->resolve([
        $manifestRow('consumer', ['provider' => $anyVersion]),
        $manifestRow('provider'),
    ]);
    $runtimeCheck($plan['order'] === ['/provider', '/consumer'], 'dependencies load before alphabetically earlier consumers');

    foreach (['disabled', 'incompatible', 'invalid'] as $state) {
        $plan = $resolver->resolve([
            $manifestRow('consumer', ['provider' => $anyVersion]),
            $manifestRow('provider', [], $state),
        ]);
        $runtimeCheck($plan['plugins'][0]['status'] === 'blocked' && $plan['order'] === [], 'unavailable ' . $state . ' dependency blocks consumer');
    }

    $plan = $resolver->resolve([$manifestRow('consumer', ['missing' => $anyVersion])]);
    $runtimeCheck($plan['plugins'][0]['status'] === 'blocked' && str_contains($plan['plugins'][0]['reason'], 'Missing dependency'), 'missing dependency diagnosed');

    foreach ([
        ['min_version' => '1.0.1', 'max_version' => null],
        ['min_version' => null, 'max_version' => '0.9.9'],
    ] as $range) {
        $plan = $resolver->resolve([
            $manifestRow('consumer', ['provider' => $range]),
            $manifestRow('provider'),
        ]);
        $runtimeCheck($plan['plugins'][0]['status'] === 'blocked', 'dependency version boundary enforced');
    }
    $plan = $resolver->resolve([
        $manifestRow('consumer', ['provider' => ['min_version' => '1.0.0', 'max_version' => '1.0.0']]),
        $manifestRow('provider'),
    ]);
    $runtimeCheck($plan['order'] === ['/provider', '/consumer'], 'dependency version boundaries are inclusive');

    $plan = $resolver->resolve([
        $manifestRow('a', ['b' => $anyVersion]),
        $manifestRow('b', ['a' => $anyVersion]),
        $manifestRow('downstream', ['a' => $anyVersion]),
        $manifestRow('independent'),
    ]);
    $runtimeCheck($plan['plugins'][0]['status'] === 'dependency-cycle' && $plan['plugins'][1]['status'] === 'dependency-cycle', 'cycle members identified');
    $runtimeCheck($plan['plugins'][2]['status'] === 'blocked' && $plan['order'] === ['/independent'], 'cycle dependents blocked without blocking independent plugins');
    $plan = $resolver->resolve([$manifestRow('self', ['self' => $anyVersion])]);
    $runtimeCheck($plan['plugins'][0]['status'] === 'dependency-cycle', 'self-dependency is a cycle');
    $plan = $resolver->resolve([$manifestRow('duplicate'), $manifestRow('duplicate')]);
    $runtimeCheck($plan['order'] === [] && array_column($plan['plugins'], 'status') === ['duplicate-id', 'duplicate-id'], 'duplicate ids block every ambiguous provider');

    $provider = $makePlugin('z-provider', 'provider', <<<'PHP'
        $context->on('runtime.test', static function (array $payload): void {
            file_put_contents($payload['marker'], 'provider');
        });
        $context->on('server.boot', static function (array $payload): void {
            $file = $payload['plugins']['provider']['directory'] . '/boots.txt';
            file_put_contents($file, 'boot', FILE_APPEND);
        });
        $context->route('GET', '/extensions/provider', static fn(): string => 'provider');
        $context->clientFeature('provider', 'Provider', '', '/extensions/provider');
    PHP);
    $makePlugin('a-consumer', 'consumer', <<<'PHP'
        $context->route('GET', '/extensions/consumer', static fn(): string => (string)$context->config('value'));
    PHP, ['dependencies' => ['provider' => ['min_version' => '1.0.0']], 'config' => ['value' => 'default']]);
    file_put_contents($runtimeRoot . '/a-consumer/config.json', '{"value":"overridden"}');
    $makePlugin('b-failing', 'failing', <<<'PHP'
        $context->on('runtime.test', static function (array $payload): void {
            file_put_contents($payload['marker'], 'broken');
        });
        $context->route('GET', '/extensions/failed', static fn(): string => 'should not exist');
        $context->clientFeature('failed', 'Failed', '', '/extensions/failed');
        throw new RuntimeException('intentional registration failure');
    PHP);
    $dependent = $makePlugin('c-depends-on-failing', 'dependent', 'file_put_contents(__DIR__ . "/executed", "yes");', [
        'dependencies' => ['failing' => []],
    ]);
    $router = new Router();
    $router->add('GET', '/core-route', static fn(): Response => Response::text('core'));
    $manager = new PluginManager($runtimePdo, $router, $runtimeRoot, '1.1.0');
    $before = $manager->diagnostics();
    $runtimeCheck(!file_exists($dependent . '/executed'), 'manifest diagnostics do not execute plugin code');
    $manager->load();
    $runtimeCheck(array_keys($manager->loaded()) === ['provider', 'consumer'], 'independent healthy plugin survives failed plugin registration');
    $runtimeCheck($router->dispatch(new Request('GET', '/extensions/failed', [], [], []))->body === '-1', 'failed plugin route is rolled back');
    $runtimeCheck(array_column($manager->clientFeatures()->all(), 'id') === ['provider'], 'failed plugin feature is rolled back');
    $runtimeCheck($manager->listenerCounts()['runtime.test'] === 1, 'failed plugin event listener is rolled back');
    $runtimeCheck($router->dispatch(new Request('GET', '/core-route', [], [], []))->body === 'core', 'core route survives rollback');
    $runtimeCheck($router->dispatch(new Request('GET', '/extensions/consumer', [], [], []))->body === 'overridden', 'config.json overrides manifest defaults');
    $runtimeCheck(!file_exists($dependent . '/executed'), 'dependent PHP never executes when provider registration fails');
    $map = $diagnosticMap($manager);
    $runtimeCheck(!array_key_exists('config', $map['consumer']), 'diagnostics do not expose private plugin settings');
    $runtimeCheck($map['failing']['status'] === 'failed' && str_contains($map['failing']['reason'], 'intentional registration failure'), 'runtime failure diagnosed');
    $runtimeCheck($map['dependent']['status'] === 'blocked' && $map['provider']['status'] === 'loaded', 'runtime loaded and blocked states diagnosed');
    $manager->load();
    $manager->boot();
    $manager->boot();
    $runtimeCheck($manager->listenerCounts()['runtime.test'] === 1, 'load is idempotent per manager');
    $runtimeCheck(file_get_contents($provider . '/boots.txt') === 'boot', 'boot is idempotent per manager');

    $makePlugin('invalid-range', 'invalid-range', 'throw new RuntimeException("must never execute");', [
        'dependencies' => ['provider' => ['min_version' => '2.0.0', 'max_version' => '1.0.0']],
    ]);
    $makePlugin('invalid-shape', 'invalid-shape', '', ['dependencies' => ['provider']]);
    $makePlugin('invalid-field', 'invalid-field', '', ['dependencies' => ['provider' => ['minimum' => '1.0.0']]]);
    $invalidConfig = $makePlugin('invalid-config', 'invalid-config', 'file_put_contents(__DIR__ . "/executed", "yes");');
    file_put_contents($invalidConfig . '/config.json', '[1, 2]');
    $oversizeConfig = $makePlugin('oversize-config', 'oversize-config', '');
    file_put_contents($oversizeConfig . '/config.json', str_repeat(' ', 65537));
    $map = $diagnosticMap(new PluginManager($runtimePdo, new Router(), $runtimeRoot, '1.1.0'));
    foreach (['invalid-range', 'invalid-shape', 'invalid-field', 'invalid-config', 'oversize-config'] as $id) {
        $runtimeCheck($map[$id]['status'] === 'invalid', $id . ' rejected before execution');
    }
    $runtimeCheck(!file_exists($invalidConfig . '/executed'), 'invalid config diagnostics execute no PHP');

    $news = $runtimeRoot . '/news';
    mkdir($news);
    $newsExample = dirname(__DIR__, 2) . '/examples/plugins/mucho-news';
    copy($newsExample . '/manifest.json', $news . '/manifest.json');
    copy($newsExample . '/plugin.php', $news . '/plugin.php');
    file_put_contents($news . '/config.json', '{"title":"Server news","message":"Test announcement"}');
    $newsRouter = new Router();
    $newsManager = new PluginManager($runtimePdo, $newsRouter, $runtimeRoot, '1.1.0');
    $newsManager->load();
    $response = $newsRouter->dispatch(new Request('GET', '/extensions/news', [], [], []));
    $payload = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
    $runtimeCheck($payload['feature_id'] === 'news' && $payload['message'] === 'Test announcement', 'News plugin serves configured announcement');
    $bridge = new ClientBridgeController($newsManager->clientFeatures(), '1.1.0');
    $manifest = json_decode($bridge->manifest(new Request('GET', '/api/client/manifest', [], [], []))->body, true, 16, JSON_THROW_ON_ERROR);
    $runtimeCheck(in_array('news', array_column($manifest['features'], 'id'), true), 'News is discoverable through existing MuchoClient protocol');
    $runtimeCheck(!in_array('failed', array_column($manifest['features'], 'id'), true), 'failed feature never leaks into client discovery');

    echo 'MUCHOCORE_PLUGIN_RUNTIME_OK checks=' . $runtimeChecks . PHP_EOL;
} finally {
    if (is_string($oldRuntimeErrorLog)) {
        ini_set('error_log', $oldRuntimeErrorLog);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtimeRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($runtimeRoot);
}
