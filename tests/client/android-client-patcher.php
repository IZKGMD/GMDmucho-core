<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/WindowsClientPatcher.php';
require dirname(__DIR__, 2) . '/src/Client/AndroidClientPatcher.php';

use MuchoCore\Client\AndroidClientPatcher;

$dir = sys_get_temp_dir() . '/muchocore-android-patcher-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create test directory.');
}

$input = $dir . '/GeometryDash.apk';
$output = $dir . '/GeometryDash-MuchoCore.apk';

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive extension is required.');
    }

    $zip = new ZipArchive();
    if ($zip->open($input, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create fixture APK.');
    }

    $zip->addFromString(
        'AndroidManifest.xml',
        '<manifest package="test.geometrydash"/>'
    );

    $oldUrl = 'http://www.boomlings.com/database';
    $oldHttpsUrl = 'https://www.boomlings.com/database';
    $zip->addFromString(
        'lib/arm64-v8a/libcocos2dcpp.so',
        "prefix\0" . $oldUrl . "\0" .
        base64_encode($oldUrl) . "\0" .
        $oldHttpsUrl . "\0" .
        base64_encode($oldHttpsUrl) . "\0suffix"
    );

    // Keep the synthetic APK above the production minimum-size guard.
    $zip->addFromString(
        'assets/test-padding.bin',
        random_bytes(4096)
    );

    $zip->addFromString(
        'META-INF/MANIFEST.MF',
        'old signature manifest'
    );
    $zip->addFromString(
        'META-INF/TEST.SF',
        'old signature'
    );
    $zip->addFromString(
        'META-INF/TEST.RSA',
        'old certificate'
    );

    if (!$zip->close()) {
        throw new RuntimeException('Cannot finalize fixture APK.');
    }

    $report = AndroidClientPatcher::patchFile(
        $input,
        $output,
        'https://gdps-example.com'
    );

    if (($report['replacement_count'] ?? 0) < 4) {
        throw new RuntimeException('Expected four fixed-length URL replacements.');
    }

    $check = new ZipArchive();
    if ($check->open($output) !== true) {
        throw new RuntimeException('Patched APK is not a valid ZIP archive.');
    }

    $native = $check->getFromName('lib/arm64-v8a/libcocos2dcpp.so');

    $expectedHttp = 'http://gdps-example.com/a/api/api';
    $expectedHttps = 'https://gdps-example.com/a/api/api';

    if (
        !is_string($native) ||
        !str_contains($native, $expectedHttp) ||
        !str_contains($native, $expectedHttps)
    ) {
        throw new RuntimeException('Patched server URL not found.');
    }

    if (
        str_contains($native, $oldUrl) ||
        str_contains($native, $oldHttpsUrl) ||
        $check->locateName('META-INF/MANIFEST.MF') !== false ||
        $check->locateName('META-INF/TEST.SF') !== false ||
        $check->locateName('META-INF/TEST.RSA') !== false
    ) {
        throw new RuntimeException('Old URL or signature files remained.');
    }

    $check->close();

    echo "Android client patcher test passed\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}
