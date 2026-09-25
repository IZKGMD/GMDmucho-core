<?php

declare(strict_types=1);

use MuchoCore\Plugin\PluginEventBus;

require dirname(__DIR__, 2) . '/src/Plugin/PluginEventBus.php';

$bus = new PluginEventBus();
$seen = 0;

$bus->on('demo.event', static function (array $payload) use (&$seen): void {
    $seen += (int)($payload['value'] ?? 0);
});

$bus->emit('demo.event', ['value' => 7]);

if ($seen !== 7) {
    throw new RuntimeException('plugin event bus contract failed');
}

$counts = $bus->listenerCounts();

if (($counts['demo.event'] ?? 0) !== 1) {
    throw new RuntimeException('plugin listener count contract failed');
}

echo "plugin-sdk-contract: OK
";
