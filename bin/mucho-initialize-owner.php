<?php
declare(strict_types=1);

use MuchoCore\Admin\FirstRunSetup;
use MuchoCore\Database\Database;

// Only the operator's install process may invoke this command. Never expose
// it as a web route, or pass passwords through arguments or shell history.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!in_array('--initialize', $argv ?? [], true)) {
    fwrite(STDERR, "Usage: php bin/mucho-initialize-owner.php --initialize (server console only)\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$db = (new Database())->connection();
$existing = (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
$alreadyInitialized = FirstRunSetup::prepare($db, $existing);
if ($existing !== 0 || $alreadyInitialized) {
    echo "Owner already initialized; existing administrator credentials preserved.\n";
    exit(0);
}

$passwordPath = (string)(getenv('MUCHO_ADMIN_PASSWORD_FILE') ?: '/run/secrets/admin_password');
if (!is_file($passwordPath) || !is_readable($passwordPath)) {
    fwrite(STDERR, "Missing private owner password file; installation is incomplete.\n");
    exit(1);
}

$password = file_get_contents($passwordPath);
if (!is_string($password) || strlen($password) < 12 || strlen($password) > 256
    || preg_match('/[\x00-\x1F\x7F]/', $password)) {
    fwrite(STDERR, "Invalid owner password: use 12–256 characters, without control characters.\n");
    exit(1);
}

$username = (string)(getenv('ADMIN_USER') ?: 'admin');
$owner = FirstRunSetup::createOwner($db, $username, $password);
unset($password);
if ($owner === null) {
    echo "Owner initialized concurrently; no administrator credentials changed.\n";
    exit(0);
}

echo "First administrator created: " . $owner['username'] . ". Sign in at /admin/.\n";
