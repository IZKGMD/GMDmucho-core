<?php
// Temporary read-only database check. The local installer removes this file.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function database_check_fail($code, $message) {
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $message));
    exit;
}
if (time() > __EXPIRES__ || strtolower((string)$_SERVER['HTTP_HOST']) !== '__DOMAIN__') {
    database_check_fail(403, 'expired');
}
if ((!isset($_SERVER['HTTPS']) || strtolower((string)$_SERVER['HTTPS']) !== 'on')
    && (string)(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '') !== '443') {
    database_check_fail(403, 'https_required');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (int)$_SERVER['CONTENT_LENGTH'] > 8192) {
    database_check_fail(400, 'invalid_request');
}
$token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
if (!hash_equals('__HASH__', hash('sha256', $token))) {
    database_check_fail(403, 'invalid_token');
}
foreach (array('host', 'port', 'database', 'user', 'password') as $field) {
    if (!isset($_POST[$field]) || !is_string($_POST[$field])) database_check_fail(400, 'invalid_fields');
}
if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $_POST['host'])
    || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $_POST['database'])
    || !ctype_digit($_POST['port']) || (int)$_POST['port'] < 1 || (int)$_POST['port'] > 65535) {
    database_check_fail(400, 'invalid_fields');
}
if (!extension_loaded('pdo_mysql')) database_check_fail(503, 'pdo_mysql_missing');
try {
    $pdo = new PDO('mysql:host='.$_POST['host'].';port='.(int)$_POST['port'].';dbname='.$_POST['database'].';charset=utf8mb4',
        $_POST['user'], $_POST['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8));
    if (count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) !== 0) {
        database_check_fail(409, 'database_not_empty');
    }
    echo json_encode(array('ok' => true));
} catch (Exception $error) {
    // Do not return a database exception containing credentials or host details.
    database_check_fail(400, 'database_connection_failed');
}
