<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assertCompatibility(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'public/admin/pages/compatibility.php',
    'public/admin/pages/players.php',
    'public/admin/core/AdminRouter.php',
    'public/admin/config/pages.php',
    'src/Admin/AdminRbac.php',
    'public/admin/index.php',
    'src/Diagnostics/ClientTrace.php',
    'bin/mucho-trace.php',
];

foreach ($files as $relative) {
    assertCompatibility(is_file($root . '/' . $relative), 'Missing Compatibility Lab file: ' . $relative);
}

$page = (string)file_get_contents($root . '/public/admin/pages/compatibility.php');
$router = (string)file_get_contents($root . '/public/admin/core/AdminRouter.php');
$pages = (string)file_get_contents($root . '/public/admin/config/pages.php');
$rbac = (string)file_get_contents($root . '/src/Admin/AdminRbac.php');
$index = (string)file_get_contents($root . '/public/admin/index.php');
$trace = (string)file_get_contents($root . '/src/Diagnostics/ClientTrace.php');
$cli = (string)file_get_contents($root . '/bin/mucho-trace.php');

assertCompatibility(str_contains($page, 'Compatibility Lab'), 'Compatibility Lab title is missing.');
assertCompatibility(str_contains($page, 'tests/client-fixtures'), 'Compatibility Lab must inspect committed client fixtures.');
assertCompatibility(str_contains($page, 'Open API Tester'), 'Compatibility Lab must link to the existing API tester.');
assertCompatibility(str_contains($page, 'getGJLevels21.php'), '2.2 level probe is missing.');
assertCompatibility(str_contains($router, "'compatibility'"), 'Admin router does not register Compatibility Lab.');
assertCompatibility(str_contains($pages, "'compatibility'=>'Compatibility Lab'"), 'Page registry does not expose Compatibility Lab.');
assertCompatibility(str_contains($rbac, "'compatibility' => 'tools.endpoint_test'"), 'Compatibility Lab must use the endpoint-test permission.');
assertCompatibility(str_contains($index, "'dashboard','ops','analytics','advanced','monitoring','intelligence','compatibility'"), 'Compatibility Lab is missing from the main navigation.');
assertCompatibility(str_contains($trace, 'response_sha256'), 'Trace storage must preserve response fingerprints.');
assertCompatibility(str_contains($cli, "mucho-trace diff"), 'Trace CLI diff support is required by the lab workflow.');

echo "compatibility-lab-contract: OK\n";
