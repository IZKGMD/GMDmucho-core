<?php

declare(strict_types=1);

use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/muchocore-plugin-test-' . bin2hex(random_bytes(5));
$pluginDir = $root . '/custom/plugins/demo-plugin';
$futurePluginDir = $root . '/custom/plugins/future-plugin';
$configuredPluginRoot = $root . '/custom/plugins';
$oldPluginDir = getenv('MUCHO_PLUGIN_DIR');
$oldPluginsEnabled = getenv('MUCHO_PLUGINS_ENABLED');

mkdir($pluginDir, 0770, true);
mkdir($futurePluginDir, 0770, true);
file_put_contents($root . '/VERSION', "1.0.3\n");

$cleanup = static function () use ($root, $oldPluginDir, $oldPluginsEnabled): void {
    if ($oldPluginDir === false) {
        putenv('MUCHO_PLUGIN_DIR');
        unset($_ENV['MUCHO_PLUGIN_DIR']);
    } else {
        putenv('MUCHO_PLUGIN_DIR=' . $oldPluginDir);
        $_ENV['MUCHO_PLUGIN_DIR'] = $oldPluginDir;
    }

    if ($oldPluginsEnabled === false) {
        putenv('MUCHO_PLUGINS_ENABLED');
        unset($_ENV['MUCHO_PLUGINS_ENABLED']);
    } else {
        putenv('MUCHO_PLUGINS_ENABLED=' . $oldPluginsEnabled);
        $_ENV['MUCHO_PLUGINS_ENABLED'] = $oldPluginsEnabled;
    }

    if (!is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($root);
};

try {
    file_put_contents(
        $pluginDir . '/manifest.json',
        json_encode([
            'id' => 'demo-plugin',
            'name' => 'Demo Plugin',
            'version' => '1.0.0',
            'api' => 1,
            'min_core_version' => '1.0.0',
            'max_core_version' => '2.0.0',
            'enabled' => true,
            'permissions' => ['events'],
        ], JSON_THROW_ON_ERROR)
    );

    $pluginSource = implode(PHP_EOL, [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        'return new class implements \\MuchoCore\\Plugin\\PluginInterface',
        '{',
        '    public function register(\\MuchoCore\\Plugin\\PluginContext $context): void',
        '    {',
        '        $context->on(' . "'demo.event'" . ', static function (array $payload): void {',
        '            file_put_contents(',
        '                $payload[' . "'marker'" . '],',
        '                (string)($payload[' . "'value'" . '] ?? ' . "''" . ')',
        '            );',
        '        });',
        '    }',
        '};',
        '',
    ]);

    file_put_contents($pluginDir . '/plugin.php', $pluginSource);
    file_put_contents($futurePluginDir . '/plugin.php', $pluginSource);

    file_put_contents(
        $futurePluginDir . '/manifest.json',
        json_encode([
            'id' => 'future-plugin',
            'name' => 'Future Plugin',
            'version' => '9.0.0',
            'api' => 1,
            'min_core_version' => '1.0.0',
            'max_core_version' => '1.0.2',
            'enabled' => true,
            'permissions' => ['events'],
        ], JSON_THROW_ON_ERROR)
    );

    $marker = $root . '/event.marker';
    $futureMarker = $root . '/future.marker';

    putenv('MUCHO_PLUGINS_ENABLED=1');
    $_ENV['MUCHO_PLUGINS_ENABLED'] = '1';
    putenv('MUCHO_PLUGIN_DIR=' . $configuredPluginRoot);
    $_ENV['MUCHO_PLUGIN_DIR'] = $configuredPluginRoot;

    $pdo = new class extends PDO {
        public function __construct() {}
    };

    $manager = PluginManager::fromEnvironment(
        $pdo,
        new Router(),
        $root
    );

    if ($manager->rootDirectory() !== $configuredPluginRoot) {
        throw new RuntimeException('custom plugin environment directory contract failed');
    }

    $diagnostics = $manager->diagnostics();
    $demoDiagnostic = null;
    $futureDiagnostic = null;

    foreach ($diagnostics as $diagnostic) {
        if ($diagnostic['id'] === 'demo-plugin') {
            $demoDiagnostic = $diagnostic;
        }
        if ($diagnostic['id'] === 'future-plugin') {
            $futureDiagnostic = $diagnostic;
        }
    }

    if (!is_array($demoDiagnostic) || $demoDiagnostic['status'] !== 'ready') {
        throw new RuntimeException('compatible plugin diagnostics contract failed');
    }

    if (
        !is_array($futureDiagnostic) ||
        $futureDiagnostic['status'] !== 'incompatible' ||
        !str_contains((string)$futureDiagnostic['reason'], 'up to 1.0.2')
    ) {
        throw new RuntimeException('incompatible plugin diagnostics contract failed');
    }

    $manager->load();

    if (!isset($manager->loaded()['demo-plugin'])) {
        throw new RuntimeException(
            'custom plugin was not loaded; root=' . $manager->rootDirectory()
        );
    }

    if (isset($manager->loaded()['future-plugin'])) {
        throw new RuntimeException('incompatible plugin was loaded');
    }

    $manager->emit('demo.event', [
        'marker' => $marker,
        'value' => 'plugin-ok',
    ]);

    if (file_get_contents($marker) !== 'plugin-ok') {
        throw new RuntimeException('custom plugin event contract failed');
    }

    if (file_exists($futureMarker)) {
        throw new RuntimeException('incompatible plugin unexpectedly executed');
    }

    echo "plugin-sdk-contract: OK\n";
} finally {
    $cleanup();
}
