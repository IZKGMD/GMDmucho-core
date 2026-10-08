<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Plugin/PluginCatalog.php';

use MuchoCore\Plugin\PluginCatalog;

$root = dirname(__DIR__, 2);
$catalogPath = $root . '/resources/plugin-catalog.json';

$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    echo "PASS {$label}\n";
};

$list = (new PluginCatalog($catalogPath, '1.1.0'))->listings();
$check(count($list) === 1, 'bundled catalog loads without execution');
$check($list[0]['id'] === 'welcome-endpoint', 'example is listed');
$check($list[0]['compatible'] === true, 'supported core is compatible');
$check($list[0]['permissions'] === ['routes'], 'SDK permissions visible');

$oldCore = (new PluginCatalog($catalogPath, '1.0.9'))->listings();
$check($oldCore[0]['compatible'] === false, 'older core is incompatible');

$sample = json_decode((string)file_get_contents($catalogPath), true, 16, JSON_THROW_ON_ERROR);
$temp = tempnam(sys_get_temp_dir(), 'mucho-catalog-');
if ($temp === false) {
    throw new RuntimeException('Cannot create temporary catalog fixture.');
}

try {
    $reject = static function (array $data, string $label) use ($temp, $check): void {
        file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR));
        $rejected = false;
        try {
            (new PluginCatalog($temp, '1.1.0'))->listings();
        } catch (RuntimeException) {
            $rejected = true;
        }
        $check($rejected, $label);
    };

    $badUrl = $sample;
    $badUrl['plugins'][0]['source_url'] = 'javascript:alert(1)';
    $reject($badUrl, 'non-HTTPS source URL rejected');

    $badHost = $sample;
    $badHost['plugins'][0]['source_url'] = 'https://github.com.evil.test/plugin';
    $reject($badHost, 'lookalike GitHub host rejected');

    $badPermission = $sample;
    $badPermission['plugins'][0]['permissions'] = ['routes', 'shell'];
    $reject($badPermission, 'unknown SDK permission rejected');

    $duplicate = $sample;
    $duplicate['plugins'][] = $sample['plugins'][0];
    $reject($duplicate, 'duplicate plugin id rejected');

    $badVersion = $sample;
    $badVersion['plugins'][0]['min_core_version'] = 'latest';
    $reject($badVersion, 'invalid version rejected');

    $badRange = $sample;
    $badRange['plugins'][0]['max_core_version'] = '1.0.0';
    $reject($badRange, 'inverted core version range rejected');

    $maxRange = $sample;
    $maxRange['plugins'][0]['max_core_version'] = '1.1.0';
    file_put_contents($temp, json_encode($maxRange, JSON_THROW_ON_ERROR));
    $check(
        (new PluginCatalog($temp, '1.1.1'))->listings()[0]['compatible'] === false,
        'catalog respects upper core compatibility boundary'
    );

    $badApi = $sample;
    $badApi['plugins'][0]['api'] = 2;
    file_put_contents($temp, json_encode($badApi, JSON_THROW_ON_ERROR));
    $check(
        (new PluginCatalog($temp, '1.1.0'))->listings()[0]['compatible'] === false,
        'future SDK stays visible but incompatible'
    );

    file_put_contents($temp, '{"schema_version":1,"plugins":"not-a-list"}');
    $formatRejected = false;
    try {
        (new PluginCatalog($temp, '1.1.0'))->listings();
    } catch (RuntimeException) {
        $formatRejected = true;
    }
    $check($formatRejected, 'malformed catalog schema rejected');

    $check(
        (new PluginCatalog($temp . '.missing', '1.1.0'))->listings() === [],
        'missing optional catalog is harmless'
    );
} finally {
    unlink($temp);
}

$page = (string)file_get_contents($root . '/public/admin/pages/plugins.php');
$check(
    str_contains($page, 'renderMuchoPluginMarketplacePreview($rootDir);') &&
    str_contains($page, 'h($entry[' . "'source_url'" . '])') &&
    str_contains($page, 'Manual install only'),
    'admin marketplace has read-only escaped link surface'
);

$example = (string)file_get_contents(
    $root . '/examples/plugins/welcome-endpoint/plugin.php'
);
$check(
    str_contains($example, "'/extensions/welcome'") &&
    str_contains($example, 'PluginInterface'),
    'example plugin uses supported extension API'
);

echo "MUCHOCORE_PLUGIN_MARKETPLACE_OK\n";
