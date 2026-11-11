<?php
declare(strict_types=1);

use MuchoCore\Database\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!in_array('--issue', $argv ?? [], true)) {
    fwrite(STDERR, "Usage: php bin/mucho-owner-recovery-link.php --issue (server console only)\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$origin = rtrim((string)(getenv('MUCHO_ACCOUNT_URL') ?: ''), '/');
if (!preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?$~D', $origin)) {
    fwrite(STDERR, "Set MUCHO_ACCOUNT_URL to a valid HTTPS server origin.\n");
    exit(1);
}

$db = (new Database())->connection();
$owners = $db->query(
    "SELECT id, username, totp_secret FROM admin_users WHERE role='owner' AND is_active=1"
)->fetchAll(PDO::FETCH_ASSOC);
if (count($owners) !== 1) {
    fwrite(STDERR, "Recovery requires exactly one active owner. No account was changed.\n");
    exit(1);
}
if ((string)($owners[0]['totp_secret'] ?? '') !== '') {
    fwrite(STDERR, "Owner has two-factor authentication. Use the local admin reset tool.\n");
    exit(1);
}

$token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$invalidPassword = bin2hex(random_bytes(48));
$stmt = $db->prepare(
    "UPDATE admin_users
     SET password_hash=:password_hash,
         password_must_set=1,
         password_setup_token_hash=:token_hash,
         password_setup_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
     WHERE id=:id AND role='owner' AND is_active=1"
);
$stmt->execute([
    'password_hash' => password_hash($invalidPassword, PASSWORD_DEFAULT),
    'token_hash' => hash('sha256', $token),
    'id' => (int)$owners[0]['id'],
]);
if ($stmt->rowCount() !== 1) {
    fwrite(STDERR, "Owner changed during recovery. Retry after checking administrators.\n");
    exit(1);
}

echo "Open this PRIVATE, one-time link within 24 hours to choose a new administrator password:\n";
echo $origin . '/admin/?setup=' . rawurlencode($token) . "\n";
echo "Username: " . $owners[0]['username'] . "\n";
echo "Previous password and sessions are invalidated. Do not share this link.\n";
