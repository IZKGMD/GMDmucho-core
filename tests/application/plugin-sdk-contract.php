<?php

declare(strict_types=1);

use MuchoCore\Plugin\PluginInterface;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/muchocore-plugin-test-' . bin2hex(random_bytes(5));
$pluginDir = $root . '/custom/plugins/demo-plugin';
$oldPluginDir = getenv('MUCHO_PLUGIN_DIR');
$oldPluginsEnabled = getenv('MUCHO_PLUGINS_ENABLED');

mkdir($pluginDir, 0770, true);

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
            'enabled' => true,
            'permissions' => ['events'],
        ], JSON_THROW_ON_ERROR)
    );

    file_put_contents(
        $pluginDir . '/plugin.php',
        <<<'PLUGIN'
<?php

declare(strict_types=1);

use MuchoCorePluginPluginContext;
use MuchoCorePluginPluginInterface;

return new class implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->on('demo.event', static function (array $payload): void {
            file_put_contents(
                $payload['marker'],
                (string)($payload['value'] ?? '')
            );
        });
    }
};
PLUGIN
    );

    $marker = $root . '/event.marker';

    putenv('MUCHO_PLUGINS_ENABLED=1');
    $_ENV['MUCHO_PLUGINS_ENABLED'] = '1';
    putenv('MUCHO_PLUGIN_DIR=' . $pluginDir);
    $_ENV['MUCHO_PLUGIN_DIR'] = $pluginDir;

    $pdo = new class extends PDO {
        public function __construct() {}
    };

    $manager = PluginManager::fromEnvironment(
        $pdo,
        new Router(),
        $root
    );

    if ($manager->rootDirectory() !== $pluginDir) {
        throw new RuntimeException('custom plugin environment directory contract failed');
    }

    $manager->load();

    if (!isset($manager->loaded()['demo-plugin'])) {
        throw new RuntimeException(
            'custom plugin was not loaded; root=' . $manager->rootDirectory()
        );
    }

    $manager->emit('demo.event', [
        'marker' => $marker,
        'value' => 'plugin-ok',
    ]);

    if (file_get_contents($marker) !== 'plugin-ok') {
        throw new RuntimeException('custom plugin event contract failed');
    }

    echo "plugin-sdk-contract: OK\n";
} finally {
    $cleanup();
}
