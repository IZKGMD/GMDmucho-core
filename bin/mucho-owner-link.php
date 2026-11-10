<?php
declare(strict_types=1);

use MuchoCore\Admin\FirstRunSetup;
use MuchoCore\Core\Environment;
use MuchoCore\Database\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/vendor/autoload.php';

$db = (new Database())->connection();
$count = (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($count !== 0 || FirstRunSetup::prepare($db, $count)) {
    fwrite(STDERR, "Owner onboarding is already complete; use /admin/ to sign in.\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
$bootstrapPath = (string)(
    Environment::get('MUCHO_ADMIN_BOOTSTRAP', $rootDir.'/storage/admin-bootstrap.php')
    ?? $rootDir.'/storage/admin-bootstrap.php'
);
$config = is_readable($bootstrapPath) ? require $bootstrapPath : null;
$hash = is_array($config) && is_string($config['password_hash'] ?? null)
    ? $config['password_hash']
    : '';

$secretPath = (string)(getenv('MUCHO_ADMIN_PASSWORD_FILE') ?: '/run/secrets/admin_password');
if (!is_file($secretPath) || !is_readable($secretPath)) {
    fwrite(STDERR, "Admin activation material is missing. Check installation.\n");
    exit(1);
}
$secret = rtrim((string)file_get_contents($secretPath), "\r\n");
if (strlen($secret) < 10 || strlen($secret) > 512 ||
    $hash === '' || !password_verify($secret, $hash)) {
    fwrite(STDERR, "Admin activation material is invalid. Check installation.\n");
    exit(1);
}

$origin = rtrim((string)(getenv('MUCHO_ACCOUNT_URL') ?: ''), '/');
if (!preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?$~D', $origin)) {
    fwrite(STDERR, "Set MUCHO_ACCOUNT_URL to a valid HTTPS server origin.\n");
    exit(1);
}

// Printed only by an explicit operator CLI invocation; never through public HTTP.
// The URL fragment is not sent in HTTP requests or reverse-proxy access logs.
echo "Open this PRIVATE link to create the first administrator:\n";
echo $origin.'/admin/#setup='.rawurlencode($secret)."\n";
echo "Do not publish or forward this link. It stops working after owner registration.\n";
