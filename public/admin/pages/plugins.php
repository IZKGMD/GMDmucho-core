<?php
declare(strict_types=1);

use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;

function renderCustomPluginsPage(PDO $db): void
{
    $rootDir = defined('ROOT_DIR')
        ? ROOT_DIR
        : dirname(__DIR__, 3);

    $manager = PluginManager::fromEnvironment(
        $db,
        new Router(),
        $rootDir
    );

    $rows = $manager->diagnostics();

    $counts = [
        'ready' => 0,
        'disabled' => 0,
        'incompatible' => 0,
        'invalid' => 0,
        'missing-entrypoint' => 0,
    ];

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'invalid');
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    echo '<section class="card" style="border-color:#293448">';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">';
    echo '<div>';
    echo '<div style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#8c7dff">CUSTOM PLUGINS</div>';
    echo '<h2 style="margin:7px 0 4px">Persistent extension layer</h2>';
    echo '<p class="muted" style="margin:0;line-height:1.55">These plugins live outside the tracked core source and are preserved across stable MuchoCore updates.</p>';
    echo '</div>';
    echo '<a class="btn gray" href="https://github.com/IZKGMD/GMDmucho-core/blob/main/docs/CUSTOM_PLUGINS.md" target="_blank" rel="noopener noreferrer">Plugin docs ↗</a>';
    echo '</div>';

    echo '<div class="grid" style="margin-top:16px">';
    $summary = [
        ['Installed', count($rows), ''],
        ['Ready', $counts['ready'], 'green'],
        ['Disabled', $counts['disabled'], ''],
        ['Incompatible', $counts['incompatible'], 'red'],
        ['Invalid', $counts['invalid'] + $counts['missing-entrypoint'], 'red'],
    ];

    foreach ($summary as [$label, $value, $tone]) {
        echo '<div class="card">';
        echo '<div class="muted" style="font-size:11px">' . h($label) . '</div>';
        echo '<div class="value ' . h($tone) . '">' . number_format((int)$value) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="card" style="margin-top:13px;background:#0b1018">';
    echo '<div class="muted" style="font-size:11px">Plugin directory</div>';
    echo '<code style="display:block;margin-top:6px;word-break:break-all">' .
        h($manager->rootDirectory()) .
        '</code>';
    echo '<div class="muted" style="font-size:11px;margin-top:9px">Core version: <b style="color:#dfe7f5">v' .
        h((string)(trim((string)@file_get_contents($rootDir . '/VERSION')) ?: 'unknown')) .
        '</b></div>';
    echo '</div>';

    if (!$rows) {
        echo '<div class="card" style="margin-top:13px">';
        echo '<h3 style="margin-top:0">No custom plugins installed</h3>';
        echo '<p class="muted" style="line-height:1.6">Create a directory under <code>custom/plugins/</code> with a <code>manifest.json</code> and <code>plugin.php</code>. The bundled README and plugin documentation contain a working example.</p>';
        echo '</div>';
        echo '</section>';
        return;
    }

    echo '<div class="table"><table>';
    echo '<tr><th>Plugin</th><th>ID</th><th>Version</th><th>API</th><th>Compatibility</th><th>Permissions</th><th>Status</th></tr>';

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'invalid');
        $statusClass = match ($status) {
            'ready' => 'green',
            'disabled' => 'gray',
            default => 'red',
        };

        $min = $row['min_core_version'] ?? null;
        $max = $row['max_core_version'] ?? null;
        $compatibility = 'Any v1 core';

        if ($min !== null && $max !== null) {
            $compatibility = 'v' . $min . ' – v' . $max;
        } elseif ($min !== null) {
            $compatibility = 'v' . $min . '+';
        } elseif ($max !== null) {
            $compatibility = '≤ v' . $max;
        }

        $permissions = (array)($row['permissions'] ?? []);
        $permissionText = $permissions
            ? implode(', ', $permissions)
            : 'none';

        echo '<tr>';
        echo '<td><b>' . h((string)($row['name'] ?? $row['id'] ?? 'Unknown')) . '</b><br><small>' .
            h(basename((string)($row['directory'] ?? ''))) .
            '</small></td>';
        echo '<td><code>' . h((string)($row['id'] ?? '')) . '</code></td>';
        echo '<td>' . h((string)($row['version'] ?? '—')) . '</td>';
        echo '<td>v' . h((string)($row['api'] ?? '—')) . '</td>';
        echo '<td>' . h($compatibility) . '</td>';
        echo '<td>' . h($permissionText) . '</td>';
        echo '<td><span class="badge ' . h($statusClass) . '">' .
            h(strtoupper(str_replace('-', ' ', $status))) .
            '</span><br><small>' .
            h((string)($row['reason'] ?? '')) .
            '</small></td>';
        echo '</tr>';
    }

    echo '</table></div>';
    echo '<p class="muted" style="font-size:10px;line-height:1.5">Manifest diagnostics are read-only and do not execute <code>plugin.php</code>. PHP plugins are not sandboxed and should only be installed from trusted sources.</p>';
    echo '</section>';
}
