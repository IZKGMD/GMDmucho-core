<?php

declare(strict_types=1);

namespace MuchoCore\Plugin;

use MuchoCore\Routing\Router;
use PDO;
use Throwable;

final class PluginManager
{
    private PluginEventBus $events;
    private array $loaded = [];

    public function __construct(
        private readonly PDO $db,
        private readonly Router $router,
        private readonly string $rootDirectory
    ) {
        $this->events = new PluginEventBus();
    }

    public static function fromEnvironment(
        PDO $db,
        Router $router,
        string $projectRoot
    ): self {
        $root = (string)(
            $_ENV['MUCHO_PLUGIN_DIR']
            ?? getenv('MUCHO_PLUGIN_DIR')
            ?: $projectRoot . '/plugins'
        );

        return new self($db, $router, $root);
    }

    public function load(): void
    {
        if (!$this->enabled() || !is_dir($this->rootDirectory)) {
            return;
        }

        $entries = scandir($this->rootDirectory);
        if ($entries === false) {
            return;
        }

        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $directory = $this->rootDirectory . '/' . $entry;
            if (is_dir($directory)) {
                $this->loadOne($directory);
            }
        }
    }

    public function boot(): void
    {
        $this->events->emit('server.boot', [
            'plugins' => $this->loaded,
        ]);
    }

    public function emit(string $event, array $payload = []): void
    {
        $this->events->emit($event, $payload);
    }

    public function loaded(): array
    {
        return $this->loaded;
    }

    public function listenerCounts(): array
    {
        return $this->events->listenerCounts();
    }

    private function loadOne(string $directory): void
    {
        $manifestPath = $directory . '/manifest.json';
        $pluginPath = $directory . '/plugin.php';

        if (!is_file($manifestPath) || !is_file($pluginPath)) {
            return;
        }

        try {
            $manifest = json_decode(
                (string)file_get_contents($manifestPath),
                true,
                16,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($manifest)) {
                throw new \RuntimeException('Plugin manifest must be an object.');
            }

            $name = trim((string)($manifest['name'] ?? basename($directory)));
            $version = trim((string)($manifest['version'] ?? '0.0.0'));
            $enabled = (bool)($manifest['enabled'] ?? true);
            $permissions = array_values(array_filter(
                (array)($manifest['permissions'] ?? ['events']),
                static fn(mixed $value): bool =>
                    is_string($value) && $value !== ''
            ));

            if ($name === '' || !$enabled) {
                return;
            }

            $plugin = require $pluginPath;

            if (!$plugin instanceof PluginInterface) {
                throw new \RuntimeException(
                    'plugin.php must return an instance of PluginInterface.'
                );
            }

            $context = new PluginContext(
                $this->db,
                $this->router,
                $this->events,
                $permissions,
                $name
            );

            $plugin->register($context);

            $this->loaded[$name] = [
                'name' => $name,
                'version' => $version,
                'directory' => $directory,
                'permissions' => $permissions,
            ];

            $this->events->emit('plugin.loaded', [
                'name' => $name,
                'version' => $version,
            ]);
        } catch (Throwable $e) {
            error_log(
                '[MuchoCore Plugin] failed to load ' .
                basename($directory) . ': ' .
                $e::class . ': ' . $e->getMessage()
            );
        }
    }

    private function enabled(): bool
    {
        $raw = $_ENV['MUCHO_PLUGINS_ENABLED']
            ?? getenv('MUCHO_PLUGINS_ENABLED')
            ?? '1';

        return in_array(
            strtolower(trim((string)$raw)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
