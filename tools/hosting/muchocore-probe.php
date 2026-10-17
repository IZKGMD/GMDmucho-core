<?php
declare(strict_types=1);

/**
 * Temporary free/shared-hosting probe. Upload to the public web root as
 * muchocore-probe.php, test externally, then DELETE it.
 * No credentials, database connections, file writes or system commands.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (($_GET['mode'] ?? '') === 'gd') {
    header('Content-Type: text/plain; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo '-1';
        exit;
    }
    if (($_POST['probe'] ?? '') !== 'muchocore') {
        http_response_code(400);
        echo '-1';
        exit;
    }
    // Geometry Dash expects short plain-text responses to form POSTs.
    echo '1';
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$extensions = [];
foreach (['pdo', 'pdo_mysql', 'openssl', 'json', 'mbstring', 'session', 'zlib', 'zip'] as $name) {
    $extensions[$name] = extension_loaded($name);
}
$functions = [];
foreach (['flock', 'random_bytes', 'password_hash', 'gzopen', 'gzwrite', 'rename'] as $name) {
    $functions[$name] = function_exists($name);
}
$ready = PHP_VERSION_ID >= 80300;
foreach (['pdo', 'pdo_mysql', 'openssl', 'json', 'mbstring', 'session', 'zlib'] as $name) {
    $ready = $ready && $extensions[$name];
}
foreach ($functions as $available) {
    $ready = $ready && $available;
}

echo json_encode([
    'probe' => 'muchocore-shared-hosting-v1',
    'php_version' => PHP_VERSION,
    'php_ready' => $ready,
    'extensions' => $extensions,
    'functions' => $functions,
    'gd_post_test' => 'POST ?mode=gd with form field probe=muchocore; expect HTTP 200 and body 1',
    'warning' => 'PHP readiness does not prove database compatibility or access from Geometry Dash.',
    'delete_after_test' => true,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
