<?php

declare (strict_types=1);
namespace MuchoCore\Plugin;

use Throwable;
final class PluginEventBus
{
    private $listeners = [];
    public function on(string $event, callable $listener)
    {
        $event = strtolower(trim($event));
        if ($event === '') {
            throw new \InvalidArgumentException('Plugin event name cannot be empty.');
        }
        $this->listeners[$event][] = $listener;
    }
    public function emit(string $event, array $payload = [])
    {
        $event = strtolower(trim($event));
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (Throwable $e) {
                error_log('[MuchoCore Plugin] event=' . $event . ' listener=' . \get_class($e) . ': ' . $e->getMessage());
            }
        }
    }
    public function listenerCounts(): array
    {
        $out = [];
        foreach ($this->listeners as $event => $listeners) {
            $out[$event] = count($listeners);
        }
        return $out;
    }
}
