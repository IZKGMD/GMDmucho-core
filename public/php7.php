<?php
/**
 * Experimental *native*, read-only PHP 7 adapter; no forwarding to a VPS.
 * Does NOT include modern PHP8 MuchoCore classes or Composer dependencies.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$root = dirname(__DIR__);
if (!is_file(__DIR__ . '/php7-mode.on')
    || !is_file($root . '/config/php7-local.php')
    || !extension_loaded('pdo_mysql')) {
    http_response_code(503);
    echo '-1';
    exit;
}

$https = (isset($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
if (!$https) {
    http_response_code(403);
    echo '-1';
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
if (($method !== 'POST' && $method !== 'GET')
    || (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 262144)) {
    http_response_code(413);
    echo '-1';
    exit;
}

require_once $root . '/compat/php7/runtime.php';

try {
    $settings = require $root . '/config/php7-local.php';
    if (!is_array($settings)) {
        throw new RuntimeException('No configuration');
    }
    foreach (array('host','port','database','username','password') as $key) {
        if (!isset($settings[$key]) || !is_scalar($settings[$key])) {
            throw new RuntimeException('Incomplete configuration');
        }
    }
    $host = (string)$settings['host'];
    $port = (int)$settings['port'];
    $database = (string)$settings['database'];
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $host)
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)
        || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid configuration');
    }
    $dsn = 'mysql:host=' . $host . ';port=' . $port
        . ';dbname=' . $database . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$settings['username'], (string)$settings['password'],
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3
        )
    );
    echo mucho7_dispatch(
        $pdo,
        isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/',
        $method,
        $_POST
    );
} catch (Exception $exception) {
    // A public API must never disclose the DB name, password or SQL statement.
    error_log('[MuchoCore PHP7 experimental] Request rejected (configuration or schema unavailable)');
    http_response_code(503);
    echo '-1';
} catch (Error $exception) {
    error_log('[MuchoCore PHP7 experimental] Runtime error');
    http_response_code(503);
    echo '-1';
}
