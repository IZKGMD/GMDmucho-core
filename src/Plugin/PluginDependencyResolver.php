<?php

declare(strict_types=1);

namespace MuchoCore\Plugin;

/** Resolve manifests without including or executing any plugin PHP. */
final class PluginDependencyResolver
{
    /** @return array{plugins: list<array<string,mixed>>, order: list<string>} */
    public function resolve(array $plugins): array
    {
        $plugins = array_values($plugins);
        $byId = [];
        foreach ($plugins as $index => $plugin) {
            $byId[(string)$plugin['id']][] = $index;
        }

        foreach ($byId as $id => $indices) {
            if (count($indices) < 2) {
                continue;
            }
            foreach ($indices as $index) {
                if ($plugins[$index]['status'] === 'ready') {
                    $plugins[$index]['status'] = 'duplicate-id';
                    $plugins[$index]['reason'] = 'Duplicate plugin id: ' . $id . '.';
                }
            }
        }

        $state = [];
        $stack = [];
        $order = [];
        $block = static function (int $index, string $reason) use (&$plugins): void {
            if ($plugins[$index]['status'] === 'ready') {
                $plugins[$index]['status'] = 'blocked';
                $plugins[$index]['reason'] = $reason;
            }
        };

        $visit = function (int $index) use (
            &$visit, &$plugins, &$state, &$stack, &$order, $byId, $block
        ): bool {
            if ($plugins[$index]['status'] !== 'ready') {
                return false;
            }
            if (($state[$index] ?? 0) === 2) {
                return true;
            }
            if (($state[$index] ?? 0) === 1) {
                $start = array_search($index, $stack, true);
                $cycle = array_slice($stack, $start === false ? 0 : $start);
                $names = array_map(
                    static fn(int $i): string => (string)$plugins[$i]['id'],
                    $cycle
                );
                $names[] = (string)$plugins[$index]['id'];
                foreach ($cycle as $member) {
                    $plugins[$member]['status'] = 'dependency-cycle';
                    $plugins[$member]['reason'] = 'Dependency cycle: ' . implode(' -> ', $names) . '.';
                }
                return false;
            }

            $state[$index] = 1;
            $stack[] = $index;
            foreach ($plugins[$index]['dependencies'] ?? [] as $id => $range) {
                $indices = $byId[$id] ?? [];
                if (count($indices) !== 1) {
                    $block($index, count($indices) === 0
                        ? 'Missing dependency: ' . $id . '.'
                        : 'Dependency has duplicate ids: ' . $id . '.');
                    break;
                }

                $dependency = $indices[0];
                $version = (string)$plugins[$dependency]['version'];
                $min = $range['min_version'];
                $max = $range['max_version'];
                if (
                    preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 ||
                    ($min !== null && version_compare($version, $min, '<')) ||
                    ($max !== null && version_compare($version, $max, '>'))
                ) {
                    $block($index, 'Dependency version mismatch: ' . $id .
                        ' has ' . $version . ', needs ' . ($min ?? 'any') .
                        ' to ' . ($max ?? 'any') . '.');
                    break;
                }

                if (!$visit($dependency)) {
                    $block($index, 'Dependency unavailable: ' . $id .
                        ' (' . $plugins[$dependency]['status'] . ').');
                    break;
                }
            }
            array_pop($stack);
            $state[$index] = 2;
            if ($plugins[$index]['status'] !== 'ready') {
                return false;
            }
            $order[] = (string)$plugins[$index]['directory'];
            return true;
        };

        foreach (array_keys($plugins) as $index) {
            $visit($index);
        }

        return ['plugins' => $plugins, 'order' => $order];
    }
}
