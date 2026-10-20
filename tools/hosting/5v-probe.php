<?php
/** Remove this diagnostic immediately after testing. Compatible with PHP 7. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$extensions = array('curl', 'openssl', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'gd');
$available = array();
foreach ($extensions as $name) {
    $available[$name] = extension_loaded($name);
}
echo json_encode(array(
    'php_version' => PHP_VERSION,
    'php_82_or_newer' => version_compare(PHP_VERSION, '8.2', '>='),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'extensions' => $available,
    'gd_edge_relay_possible' => function_exists('curl_init')
        && extension_loaded('openssl'),
    'full_muchocore_possible' => version_compare(PHP_VERSION, '8.2', '>=')
        && extension_loaded('pdo_mysql'),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
