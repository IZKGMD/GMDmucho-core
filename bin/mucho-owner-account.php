<?php
declare(strict_types=1);

use MuchoCore\Admin\AdminEmailRecovery;
use MuchoCore\Admin\FirstRunSetup;
use MuchoCore\Database\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$action = $argv[1] ?? '';
$username = $argv[2] ?? '';
$secondFactor = $argv[3] ?? '';
if ($argc !== 4 || !in_array($action, ['create', 'recover'], true)
    || !in_array($secondFactor, ['keep-2fa', 'reset-2fa'], true)
    || ($action === 'create' && ($username === '' || $secondFactor !== 'keep-2fa'))
    || ($username !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/D', $username))) {
    fwrite(STDERR, "Use sudo mucho admin create or sudo mucho admin recover.\n");
    exit(2);
}

$password = stream_get_contents(STDIN, 73);
if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72
    || preg_match('/[\x00-\x1F\x7F]/', $password)) {
    fwrite(STDERR, "Use 12–72 password bytes without control characters.\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $db = (new Database())->connection();
    if ($action === 'create') {
        $owner = FirstRunSetup::createOwner($db, $username, $password);
        if ($owner === null) {
            fwrite(STDERR, "This installation already completed owner setup. Use sudo mucho admin recover.\n");
            exit(1);
        }
        echo "Owner created: {$owner['username']}. Sign in at /admin/.\n";
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    unset($password);
    AdminEmailRecovery::ensureSchema($db);
    $db->beginTransaction();

    $owners = $db->prepare(
        "SELECT id, username FROM admin_users WHERE role='owner' AND is_active=1"
        . ($username === '' ? '' : ' AND username=:username')
        . ' ORDER BY id FOR UPDATE'
    );
    $owners->execute($username === '' ? [] : ['username' => $username]);
    $matches = $owners->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches) !== 1) {
        $db->rollBack();
        fwrite(STDERR, $matches === []
            ? "No matching active owner. Check the username or restore an owner from a backup.\n"
            : "Multiple active owners. Run sudo mucho admin recover USERNAME.\n");
        exit(1);
    }

    $owner = $matches[0];
    $id = ['id' => (int)$owner['id']];
    $db->prepare('UPDATE admin_users SET password_hash=:hash, password_must_set=0,
        password_setup_token_hash=NULL, password_setup_expires_at=NULL WHERE id=:id')
        ->execute($id + ['hash' => $hash]);
    $db->prepare('UPDATE mucho_admin_client_tokens SET revoked_at=UTC_TIMESTAMP()
        WHERE admin_user_id=:id AND revoked_at IS NULL')->execute($id);
    $db->prepare('UPDATE admin_email_recovery SET reset_token_hash=NULL, reset_expires_at=NULL,
        reset_sent_at=NULL, pending_email=NULL, verify_token_hash=NULL, verify_expires_at=NULL
        WHERE admin_user_id=:id')->execute($id);

    if ($secondFactor === 'reset-2fa') {
        $db->prepare('UPDATE admin_users SET totp_secret=NULL WHERE id=:id')->execute($id);
        $db->prepare('DELETE FROM admin_recovery_codes WHERE admin_user_id=:id')->execute($id);
        $db->prepare('UPDATE admin_passkeys SET is_active=0 WHERE admin_user_id=:id')->execute($id);
    }

    $db->commit();
    echo "Password updated for {$owner['username']}. Sign in at /admin/.\n";
    if ($secondFactor === 'reset-2fa') {
        echo "Set up a new authenticator and passkeys after signing in.\n";
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Owner operation failed. Check the application and migrations with sudo mucho doctor.\n");
    exit(1);
}
