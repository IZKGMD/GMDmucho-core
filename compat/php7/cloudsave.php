<?php
/** Account-bound PHP7 cloud saves. Encrypt even before the full-core migration. */
declare(strict_types=1);

/**
 * PHP 7.0 has no AEAD OpenSSL API. Encrypt-then-MAC with independent keys
 * keeps pre-migration saves confidential without depending on PHP 7.1 GCM.
 * The full-core migration re-encrypts the authenticated plaintext as AES-GCM.
 */
function mucho7_cloudsave_seal($plain, $masterKey)
{
    if (!is_string($plain) || !is_string($masterKey) || strlen($masterKey) !== 32) {
        throw new RuntimeException('Invalid cloud-save encryption input.');
    }
    $prefix = 'MC7ENC1:';
    $iv = random_bytes(16);
    $encKey = hash_hmac('sha256', 'muchocore/php7/cloudsave/enc/v1', $masterKey, true);
    $macKey = hash_hmac('sha256', 'muchocore/php7/cloudsave/mac/v1', $masterKey, true);
    $ciphertext = openssl_encrypt($plain, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if (!is_string($ciphertext)) {
        throw new RuntimeException('Cloud-save encryption failed.');
    }
    $tag = hash_hmac('sha256', $prefix . $iv . $ciphertext, $macKey, true);
    return $prefix . $iv . $tag . $ciphertext;
}

function mucho7_cloudsave_unseal($stored, $masterKey)
{
    if (!is_string($stored)) {
        throw new RuntimeException('Invalid cloud-save payload.');
    }
    // Existing installations contain plaintext. Preserve read compatibility;
    // new writes never use this format, and the full-core upgrade encrypts it.
    if (substr($stored, 0, 8) !== 'MC7ENC1:') {
        return $stored;
    }
    if (!is_string($masterKey) || strlen($masterKey) !== 32 || strlen($stored) < 72) {
        throw new RuntimeException('Invalid cloud-save encryption metadata.');
    }
    $prefix = substr($stored, 0, 8);
    $iv = substr($stored, 8, 16);
    $tag = substr($stored, 24, 32);
    $ciphertext = substr($stored, 56);
    $macKey = hash_hmac('sha256', 'muchocore/php7/cloudsave/mac/v1', $masterKey, true);
    $expected = hash_hmac('sha256', $prefix . $iv . $ciphertext, $macKey, true);
    if (!hash_equals($expected, $tag)) {
        throw new RuntimeException('Cloud-save authentication failed.');
    }
    $encKey = hash_hmac('sha256', 'muchocore/php7/cloudsave/enc/v1', $masterKey, true);
    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if (!is_string($plain)) {
        throw new RuntimeException('Cloud-save decryption failed.');
    }
    return $plain;
}

/** Create the same persistent private key consumed by the full-core importer. */
function mucho7_cloudsave_key(PDO $pdo, $root)
{
    $path = $root . '/config/cloudsave.key';
    $lockPath = $root . '/config/php7-cloudsave-key.lock';
    if (is_link($path) || is_link($lockPath)) {
        throw new RuntimeException('Cloud-save private file must not be a symlink.');
    }
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Unable to lock cloud-save key.');
    }
    try {
        @chmod($lockPath, 0600);
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Cloud-save key must not be a symlink.');
        }
        if (!is_file($path)) {
            // A lost key must NEVER be silently replaced if any encrypted
            // records already exist. Replacement would destroy recoverability.
            $existing = $pdo->query("SELECT 1 FROM php7_cloud_saves WHERE LEFT(save_data, 8)='MC7ENC1:' LIMIT 1");
            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException('Existing encrypted saves require their original key.');
            }
            mucho7_private_write($path, base64_encode(random_bytes(32)) . "\n");
        }
        $key = base64_decode(trim((string)file_get_contents($path)), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Cloud-save key is invalid.');
        }
        return $key;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function mucho7_cloudsave(PDO $pdo, $data, $backup)
{
    $aid = mucho7_identity($pdo, $data, true);
    if ($aid <= 0) return '-1';
    $root = dirname(__DIR__, 2);
    if ($backup) {
        $save = isset($data['saveData']) ? $data['saveData'] : null;
        if (!is_string($save) || $save === '' || strlen($save) > 8*1024*1024) return '-1';
        $encrypted = mucho7_cloudsave_seal($save, mucho7_cloudsave_key($pdo, $root));
        $query = $pdo->prepare('INSERT INTO php7_cloud_saves(account_id,save_data,sha256)
            VALUES(:id,:data,:hash) ON DUPLICATE KEY UPDATE
            save_data=VALUES(save_data),sha256=VALUES(sha256),updated_at=CURRENT_TIMESTAMP');
        $query->bindValue(':id', $aid, PDO::PARAM_INT);
        $query->bindValue(':data', $encrypted, PDO::PARAM_LOB);
        $query->bindValue(':hash', hash('sha256', $save));
        $query->execute();
        return '1';
    }
    $query = $pdo->prepare('SELECT save_data,sha256 FROM php7_cloud_saves WHERE account_id=?');
    $query->execute(array($aid));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_string($row['save_data']) || $row['save_data'] === '') return '-1';
    $stored = $row['save_data'];
    $encrypted = substr($stored, 0, 8) === 'MC7ENC1:';
    $save = mucho7_cloudsave_unseal(
        $stored, $encrypted ? mucho7_cloudsave_key($pdo, $root) : ''
    );
    if (!hash_equals((string)$row['sha256'], hash('sha256', $save))) return '-1';
    return strpos($save, ';21;30;a;a') === false ? $save . ';21;30;a;a' : $save;
}
