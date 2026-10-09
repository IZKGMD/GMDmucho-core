<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\CloudSave\CloudSaveRepository;

function cloudKeyCheck(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo "PASS {$name}\n";
}

$root = sys_get_temp_dir() . '/muchocore-key-test-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('Cannot create protected test directory.');
}
$keyFile = $root . '/cloudsave.key';
$originalKey = random_bytes(32);
file_put_contents($keyFile, base64_encode($originalKey) . PHP_EOL);
chmod($keyFile, 0600);

$oldValue = getenv('MUCHO_CLOUDSAVE_KEY_FILE');
putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $keyFile);
$_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $keyFile;

try {
    $pdo = new class extends PDO {
        public function __construct() {}
    };
    $repo = new CloudSaveRepository($pdo);
    $fileMethod = new ReflectionMethod($repo, 'keyPath');
    $encrypt = new ReflectionMethod($repo, 'encrypt');
    $decrypt = new ReflectionMethod($repo, 'decrypt');

    cloudKeyCheck(
        $fileMethod->invoke($repo) === $keyFile,
        'configured persistent private key path resolved'
    );

    $payload = "legacy save: \0 \r\n  trailing spaces  ";
    $envelope = $encrypt->invoke($repo, $payload);
    cloudKeyCheck(
        $decrypt->invoke(
            $repo,
            $envelope['ciphertext'],
            $envelope['nonce'],
            $envelope['tag']
        ) === $payload,
        'encrypted save round-trips byte-for-byte'
    );

    file_put_contents($keyFile, base64_encode(random_bytes(32)) . PHP_EOL);
    $rejected = false;
    try {
        $decrypt->invoke(
            $repo,
            $envelope['ciphertext'],
            $envelope['nonce'],
            $envelope['tag']
        );
    } catch (RuntimeException) {
        $rejected = true;
    }
    cloudKeyCheck($rejected, 'wrong encryption key rejects existing save');

    file_put_contents($keyFile, 'not a valid key');
    $rejected = false;
    try {
        $encrypt->invoke($repo, $payload);
    } catch (RuntimeException) {
        $rejected = true;
    }
    cloudKeyCheck($rejected, 'malformed encryption key rejected');

    unlink($keyFile);
    $rejected = false;
    try {
        $encrypt->invoke($repo, $payload);
    } catch (RuntimeException) {
        $rejected = true;
    }
    cloudKeyCheck(
        $rejected && !file_exists($keyFile),
        'missing key fails closed without generating a replacement'
    );
} finally {
    if ($oldValue === false) {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE');
        unset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE']);
    } else {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $oldValue);
        $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $oldValue;
    }
    if (is_file($keyFile)) {
        unlink($keyFile);
    }
    rmdir($root);
}

echo "MUCHOCORE_CLOUDSAVE_KEY_OK\n";
