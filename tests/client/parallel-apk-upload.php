<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/ParallelApkUpload.php';

use MuchoCore\Client\ParallelApkUpload;

function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

verify(ParallelApkUpload::recommendedPartBytes('8M', '8M') === 4 * 1048576,
    '8M PHP upload limit must leave multipart overhead');
verify(ParallelApkUpload::recommendedPartBytes('64M', '128M') === 8 * 1048576,
    'Large post limit should use 8 MiB parts');
verify(ParallelApkUpload::recommendedPartBytes('2M', '2M') === 1024 * 1024,
    '2M PHP upload limit must leave safe overhead');

$dir = sys_get_temp_dir() . '/mucho-parallel-apk-' . bin2hex(random_bytes(5));
verify(mkdir($dir, 0700), 'Cannot create test storage');
$file = $dir . '/staging.apk';
$partSize = 4 * 1048576;
$data = str_repeat('A', $partSize) . str_repeat('B', $partSize) .
    str_repeat('C', 12345);
$size = strlen($data);
try {
    ParallelApkUpload::prepare($file, $size);
    verify(filesize($file) === $size, 'Sparse staging length mismatch');
    verify(ParallelApkUpload::partCount($size, $partSize) === 3,
        'Expected 3 parts');
    verify(!ParallelApkUpload::isComplete($file, $size, $partSize),
        'Sparse file alone cannot mean upload complete');
    // Out-of-order writes and an idempotent retry must preserve byte layout.
    foreach ([2, 0, 2, 1] as $index) {
        $payload = substr($data, $index * $partSize, $partSize);
        $input = $dir . '/input-' . $index;
        file_put_contents($input, $payload);
        $fresh = ParallelApkUpload::store(
            $file, $size, $partSize, $index, $input, strlen($payload)
        );
        verify($fresh === ($index !== 2 || !in_array(2, $processed ?? [], true)),
            'Retry incorrectly treated as a new write');
        $processed[] = $index;
    }
    verify(ParallelApkUpload::completed($file, $size, $partSize) === [0, 1, 2],
        'Commit marker manifest must be sorted');
    verify(ParallelApkUpload::isComplete($file, $size, $partSize),
        'Complete part set must pass');
    verify(hash_file('sha256', $file) === hash('sha256', $data),
        'Assembled APK checksum differs from source');

    $wrong = $dir . '/wrong-input';
    file_put_contents($wrong, 'BAD');
    try {
        ParallelApkUpload::store($file, $size, $partSize, 1, $wrong, 3);
        throw new RuntimeException('Short part was accepted');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'length mismatch'),
            'Wrong short-part rejection');
    }

    ParallelApkUpload::cleanup($file, $size, $partSize);
    verify(!ParallelApkUpload::isComplete($file, $size, $partSize),
        'Cleanup must invalidate commit state');
    echo "MUCHOCORE_PARALLEL_APK_UPLOAD_OK\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $entry) @unlink($entry);
    @rmdir($dir);
}
