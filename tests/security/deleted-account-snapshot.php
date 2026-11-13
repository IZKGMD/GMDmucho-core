<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/admin/core/DeletedAccountSnapshot.php';

use MuchoCore\Admin\DeletedAccountSnapshot;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('Deleted account snapshots: ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
};

$account = [
    'account_id' => 42,
    'username' => 'TestPlayer',
    'email' => 'test@example.invalid',
    'password_hash' => 'reusable-hash-do-not-archive',
    'gjp2_hash' => 'derived-hash-do-not-archive',
    'recovery_token' => 'private-token',
    'future_secret_key' => 'private-key',
    'password_reset_token' => 'another-sensitive-field',
];
$original = $account;
$clean = DeletedAccountSnapshot::sanitizeAccount($account);
$assert($account === $original,
    'snapshot sanitization does not modify source account');
$assert($clean === [
    'account_id' => 42,
    'username' => 'TestPlayer',
    'email' => 'test@example.invalid',
], 'password/GJP2/future secret fields removed without losing identity');

$payload = json_encode([
    'account' => $account,
    'profile' => ['stars' => 250, 'demons' => 3],
], JSON_THROW_ON_ERROR);
$redacted = DeletedAccountSnapshot::sanitizeJson($payload);
$result = json_decode($redacted, true, 512, JSON_THROW_ON_ERROR);
$assert($result['account'] === $clean && $result['profile']['stars'] === 250,
    'historical snapshot can be redacted while preserving game profile fields');
$assert(!str_contains($redacted, 'reusable-hash') &&
    !str_contains($redacted, 'derived-hash') &&
    !str_contains($redacted, 'private-token'),
    'credential bytes are absent from saved JSON');

$invalidRejected = false;
try {
    DeletedAccountSnapshot::sanitizeJson('{"profile":{}}');
} catch (RuntimeException) {
    $invalidRejected = true;
}
$assert($invalidRejected, 'unrecognized archive structure fails closed');

$action = file_get_contents(dirname(__DIR__, 2) . '/public/admin/actions/accounts.php');
$scrub = file_get_contents(dirname(__DIR__, 2) . '/bin/mucho-scrub-deleted-account-snapshots.php');
$assert(is_string($action) &&
    str_contains($action, "DeletedAccountSnapshot::sanitizeAccount(\$account)") &&
    !str_contains($action, "'account'=>\$account,"),
    'admin deletion path uses sanitized snapshot rather than raw account row');
$assert(is_string($scrub) &&
    str_contains($scrub, '--confirm=REDACT') &&
    str_contains($scrub, 'DatabaseBackupVerifier::verify') &&
    str_contains($scrub, 'BINARY snapshot=BINARY :original'),
    'historical archive repair requires verified backup and concurrent-write guard');

echo "MUCHOCORE_DELETED_ACCOUNT_REDACTION_OK\n";
