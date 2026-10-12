<?php

declare(strict_types=1);

namespace MuchoCore\Plugin;

use MuchoCore\Client\ClientFeatureRegistry;
use MuchoCore\Core\Environment;
use MuchoCore\Routing\Router;
use PDO;
use Throwable;

final class PluginManager
{
    private PluginEventBus $events;
    private ClientFeatureRegistry $clientFeatures;
    private array $loaded = [];
    private array $runtime = [];
    private bool $loadAttempted = false;
    private bool $bootEmitted = false;

    public function __construct(
        private readonly PDO $db,
        private readonly Router $router,
        private readonly string $rootDirectory,
        private readonly string $coreVersion = '0.0.0'
    ) {
        $this->events = new PluginEventBus();
        $this->clientFeatures = new ClientFeatureRegistry();
    }

    public static function fromEnvironment(
        PDO $db,
        Router $router,
        string $projectRoot
    ): self {
        $configuredRoot = Environment::get('MUCHO_PLUGIN_DIR');
        $root = is_string($configuredRoot) && trim($configuredRoot) !== ''
            ? trim($configuredRoot)
            : $projectRoot . '/custom/plugins';

        $version = is_file($projectRoot . '/VERSION')
            ? trim((string)file_get_contents($projectRoot . '/VERSION'))
            : '0.0.0';

        return new self($db, $router, $root, $version);
    }

    public function load(): void
    {
        if ($this->loadAttempted) {
            return;
        }
        $this->loadAttempted = true;

        $plan = $this->loadPlan();
        $metadataByDirectory = [];
        foreach ($plan['plugins'] as $metadata) {
            $metadataByDirectory[$metadata['directory']] = $metadata;
            if (!in_array($metadata['status'], ['ready', 'disabled'], true)) {
                error_log('[MuchoCore Plugin] failed to load ' .
                    basename($metadata['directory']) . ': ' . $metadata['reason']);
            }
        }
        foreach ($plan['order'] as $directory) {
            $metadata = $metadataByDirectory[$directory];
            foreach ($metadata['dependencies'] ?? [] as $id => $_range) {
                if (!isset($this->loaded[$id])) {
                    $this->runtime[$directory] = [
                        'status' => 'blocked',
                        'reason' => 'Dependency failed to load: ' . $id . '.',
                    ];
                    continue 2;
                }
            }
            $this->loadOne($directory, $metadata);
        }
    }

    public function boot(): void
    {
        if ($this->bootEmitted) {
            return;
        }
        $this->bootEmitted = true;
        $this->events->emit('server.boot', [
            'plugins' => $this->loaded,
        ]);
    }

    public function emit(string $event, array $payload = []): void
    {
        $this->events->emit($event, $payload);
    }

    public function clientFeatures(): ClientFeatureRegistry
    {
        return $this->clientFeatures;
    }

    public function loaded(): array
    {
        return $this->loaded;
    }

    public function listenerCounts(): array
    {
        return $this->events->listenerCounts();
    }

    public function rootDirectory(): string
    {
        return $this->rootDirectory;
    }

    /**
     * Read plugin manifests without executing plugin.php.
     *
     * @return list<array<string,mixed>>
     */
    public function diagnostics(): array
    {
        $rows = $this->loadPlan()['plugins'];
        foreach ($rows as &$row) {
            if (isset($this->runtime[$row['directory']])) {
                $row = array_replace($row, $this->runtime[$row['directory']]);
            }
            // Configuration may contain operator secrets; diagnostics are metadata only.
            unset($row['config']);
        }
        unset($row);
        return $rows;
    }

    /** @return array{plugins: list<array<string,mixed>>, order: list<string>} */
    private function loadPlan(): array
    {
        $rows = [];
        foreach ($this->directories() as $directory) {
            $metadata = $this->diagnoseOne($directory);
            if (!$this->enabled() && $metadata['status'] === 'ready') {
                $metadata['status'] = 'disabled';
                $metadata['reason'] = 'Disabled by MUCHO_PLUGINS_ENABLED.';
            }
            $rows[] = $metadata;
        }
        return (new PluginDependencyResolver())->resolve($rows);
    }

    private function directories(): array
    {
        if (!is_dir($this->rootDirectory)) {
            return [];
        }
        $entries = scandir($this->rootDirectory);
        if ($entries === false) {
            return [];
        }

        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        $directories = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $directory = $this->rootDirectory . '/' . $entry;
            if (is_dir($directory)) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    private function loadOne(string $directory, array $metadata): void
    {
        $manifestPath = $directory . '/manifest.json';
        $pluginPath = $directory . '/plugin.php';

        if (!is_file($manifestPath) || !is_file($pluginPath)) {
            return;
        }

        $routerSnapshot = clone $this->router;
        $eventsSnapshot = clone $this->events;
        $featuresSnapshot = clone $this->clientFeatures;

        try {
            if ($metadata['status'] !== 'ready') {
                if ($metadata['status'] !== 'disabled') {
                    throw new \RuntimeException((string)$metadata['reason']);
                }
                return;
            }

            $id = (string)$metadata['id'];
            $name = (string)$metadata['name'];

            if (isset($this->loaded[$id])) {
                throw new \RuntimeException('Duplicate plugin id: ' . $id);
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
                (array)$metadata['permissions'],
                $name,
                $this->clientFeatures,
                (array)($metadata['config'] ?? [])
            );

            $plugin->register($context);

            $this->loaded[$id] = [
                'id' => $id,
                'name' => $name,
                'version' => (string)$metadata['version'],
                'directory' => $directory,
                'permissions' => (array)$metadata['permissions'],
                'api' => (int)$metadata['api'],
                'min_core_version' => $metadata['min_core_version'],
                'max_core_version' => $metadata['max_core_version'],
                'dependencies' => (array)($metadata['dependencies'] ?? []),
            ];

            $this->runtime[$directory] = [
                'status' => 'loaded',
                'reason' => 'Registered successfully for this request.',
            ];

            $this->events->emit('plugin.loaded', [
                'id' => $id,
                'name' => $name,
                'version' => (string)$metadata['version'],
            ]);
        } catch (Throwable $e) {
            $this->router->restoreRegistrationSnapshot($routerSnapshot);
            $this->events->restoreRegistrationSnapshot($eventsSnapshot);
            $this->clientFeatures->restoreRegistrationSnapshot($featuresSnapshot);
            $this->runtime[$directory] = [
                'status' => 'failed',
                'reason' => 'Registration failed: ' . $e::class . ': ' . $e->getMessage(),
            ];
            error_log(
                '[MuchoCore Plugin] failed to load ' .
                basename($directory) . ': ' .
                $e::class . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnoseOne(string $directory): array
    {
        try {
            $metadata = $this->readManifest($directory);
        } catch (Throwable $e) {
            return [
                'id' => basename($directory),
                'name' => basename($directory),
                'version' => '—',
                'directory' => $directory,
                'permissions' => [],
                'api' => 0,
                'min_core_version' => null,
                'max_core_version' => null,
                'enabled' => false,
                'status' => 'invalid',
                'reason' => $e->getMessage(),
            ];
        }

        return [
            'id' => (string)$metadata['id'],
            'name' => (string)$metadata['name'],
            'version' => (string)$metadata['version'],
            'directory' => $directory,
            'permissions' => (array)$metadata['permissions'],
            'api' => (int)$metadata['api'],
            'min_core_version' => $metadata['min_core_version'],
            'max_core_version' => $metadata['max_core_version'],
            'enabled' => (bool)$metadata['enabled'],
            'status' => (string)$metadata['status'],
            'reason' => $metadata['reason'],
            'dependencies' => (array)($metadata['dependencies'] ?? []),
            'config' => (array)($metadata['config'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readManifest(string $directory): array
    {
        $manifestPath = $directory . '/manifest.json';
        $pluginPath = $directory . '/plugin.php';

        if (!is_file($manifestPath)) {
            return [
                'id' => basename($directory),
                'name' => basename($directory),
                'version' => '—',
                'directory' => $directory,
                'permissions' => [],
                'api' => 0,
                'min_core_version' => null,
                'max_core_version' => null,
                'enabled' => false,
                'status' => 'invalid',
                'reason' => 'manifest.json is missing.',
            ];
        }

        if (!is_file($pluginPath)) {
            return [
                'id' => basename($directory),
                'name' => basename($directory),
                'version' => '—',
                'directory' => $directory,
                'permissions' => [],
                'api' => 0,
                'min_core_version' => null,
                'max_core_version' => null,
                'enabled' => false,
                'status' => 'missing-entrypoint',
                'reason' => 'plugin.php is missing.',
            ];
        }

        $manifest = json_decode(
            (string)file_get_contents($manifestPath),
            true,
            16,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($manifest)) {
            throw new \RuntimeException('Plugin manifest must be an object.');
        }

        $id = trim((string)($manifest['id'] ?? basename($directory)));
        $name = trim((string)($manifest['name'] ?? $id));
        $version = trim((string)($manifest['version'] ?? '0.0.0'));
        $api = (int)($manifest['api'] ?? 1);
        $enabled = (bool)($manifest['enabled'] ?? true);
        $permissions = array_values(array_unique(array_filter(
            (array)($manifest['permissions'] ?? ['events']),
            static fn(mixed $value): bool =>
                is_string($value) &&
                in_array($value, ['events', 'routes', 'database', 'client_features'], true)
        )));

        $minCore = $this->normalizeCoreVersion(
            $manifest['min_core_version'] ?? null,
            'min_core_version'
        );
        $maxCore = $this->normalizeCoreVersion(
            $manifest['max_core_version'] ?? null,
            'max_core_version'
        );

        if ($minCore !== null && $maxCore !== null && version_compare($minCore, $maxCore, '>')) {
            throw new \RuntimeException('Core version range is inverted.');
        }
        $dependencies = $this->readDependencies($manifest['dependencies'] ?? []);
        $configuration = $this->readConfiguration($directory, $manifest['config'] ?? []);

        if ($id === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            throw new \RuntimeException('Plugin id contains unsupported characters.');
        }

        if ($name === '') {
            throw new \RuntimeException('Plugin name cannot be empty.');
        }

        if ($api < 1) {
            throw new \RuntimeException('Plugin API version must be 1 or newer.');
        }

        if ($api > 1) {
            return [
                'id' => $id,
                'name' => $name,
                'version' => $version,
                'permissions' => $permissions,
                'api' => $api,
                'min_core_version' => $minCore,
                'max_core_version' => $maxCore,
                'enabled' => $enabled,
                'dependencies' => $dependencies,
                'config' => $configuration,
                'status' => 'incompatible',
                'reason' => 'Plugin API ' . $api . ' is newer than this core supports.',
            ];
        }

        if (
            $minCore !== null &&
            version_compare($this->coreVersion, $minCore, '<')
        ) {
            return [
                'id' => $id,
                'name' => $name,
                'version' => $version,
                'permissions' => $permissions,
                'api' => $api,
                'min_core_version' => $minCore,
                'max_core_version' => $maxCore,
                'enabled' => $enabled,
                'dependencies' => $dependencies,
                'config' => $configuration,
                'status' => 'incompatible',
                'reason' => 'Requires MuchoCore ' . $minCore . ' or newer.',
            ];
        }

        if (
            $maxCore !== null &&
            version_compare($this->coreVersion, $maxCore, '>')
        ) {
            return [
                'id' => $id,
                'name' => $name,
                'version' => $version,
                'permissions' => $permissions,
                'api' => $api,
                'min_core_version' => $minCore,
                'max_core_version' => $maxCore,
                'enabled' => $enabled,
                'dependencies' => $dependencies,
                'config' => $configuration,
                'status' => 'incompatible',
                'reason' => 'Supports MuchoCore up to ' . $maxCore . '.',
            ];
        }

        return [
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'permissions' => $permissions,
            'api' => $api,
            'min_core_version' => $minCore,
            'max_core_version' => $maxCore,
            'enabled' => $enabled,
            'dependencies' => $dependencies,
            'config' => $configuration,
            'status' => $enabled ? 'ready' : 'disabled',
            'reason' => $enabled ? 'Ready to load.' : 'Disabled in manifest.json.',
        ];
    }

    private function normalizeCoreVersion(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \RuntimeException($field . ' must be a version string.');
        }
        if (trim($value) === '') {
            return null;
        }

        $version = ltrim(trim((string)$value), 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new \RuntimeException($field . ' must use MAJOR.MINOR.PATCH format.');
        }

        return $version;
    }

    private function readDependencies(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException('dependencies must be an object keyed by plugin id.');
        }
        if (count($value) > 64) {
            throw new \RuntimeException('A plugin may declare at most 64 dependencies.');
        }
        $dependencies = [];
        foreach ($value as $id => $range) {
            if (!is_string($id) || preg_match('/^[A-Za-z0-9._-]+$/D', $id) !== 1 ||
                !is_array($range) || ($range !== [] && array_is_list($range))) {
                throw new \RuntimeException('Invalid plugin dependency declaration.');
            }
            if (array_diff(array_keys($range), ['min_version', 'max_version']) !== []) {
                throw new \RuntimeException('Dependency ranges support min_version and max_version.');
            }
            $min = $this->normalizeCoreVersion($range['min_version'] ?? null, 'min_version');
            $max = $this->normalizeCoreVersion($range['max_version'] ?? null, 'max_version');
            if ($min !== null && $max !== null && version_compare($min, $max, '>')) {
                throw new \RuntimeException('Dependency version range is inverted: ' . $id . '.');
            }
            $dependencies[$id] = ['min_version' => $min, 'max_version' => $max];
        }
        return $dependencies;
    }

    private function readConfiguration(string $directory, mixed $defaults): array
    {
        if (!is_array($defaults) || ($defaults !== [] && array_is_list($defaults))) {
            throw new \RuntimeException('config must be an object.');
        }
        $path = $directory . '/config.json';
        if (!is_file($path)) {
            return $defaults;
        }
        $size = filesize($path);
        if ($size === false || $size > 65536) {
            throw new \RuntimeException('config.json must not exceed 64 KiB.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException('config.json could not be read.');
        }
        $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('config.json must be an object.');
        }
        $overrides = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return array_replace($defaults, $overrides);
    }

    private function enabled(): bool
    {
        $raw = Environment::get('MUCHO_PLUGINS_ENABLED', '1') ?? '1';

        return in_array(
            strtolower(trim((string)$raw)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
