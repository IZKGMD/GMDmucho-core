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

    putenv('MUCHO_ANDROID_SIGNER_DIR=' . $dir . '/android-signer');

    $report = AndroidClientPatcher::patchFile(
        $input,
        $output,
        'https://gdps-example.com'
    );

    if (
        ($report['replacement_count'] ?? 0) < 4 ||
        ($report['signed'] ?? false) !== true
    ) {
        throw new RuntimeException('Expected four fixed-length URL replacements.');
    }

    $signerKey = $dir . '/android-signer/muchocore-android.key.pk8';
    if (!is_file($signerKey)) {
        throw new RuntimeException('Android signer PKCS#8 key was not created.');
    }

    $pkcs8Output = [];
    $pkcs8Code = 0;
    exec(
        'openssl pkcs8 -inform DER -nocrypt -in ' . escapeshellarg($signerKey) .
        ' -out /dev/null 2>&1',
        $pkcs8Output,
        $pkcs8Code
    );
    if ($pkcs8Code !== 0) {
        throw new RuntimeException(
            'Android signer key is not valid DER PKCS#8: ' . implode("\n", $pkcs8Output)
        );
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

    $legacyKey = $dir . '/android-signer/muchocore-android.key.pem';
    $legacyOutput = [];
    $legacyCode = 0;
    exec(
        'openssl pkcs8 -inform DER -nocrypt -in ' . escapeshellarg($signerKey) .
        ' -out ' . escapeshellarg($legacyKey) . ' 2>&1',
        $legacyOutput,
        $legacyCode
    );
    if ($legacyCode !== 0 || !is_file($legacyKey)) {
        throw new RuntimeException(
            'Could not create a legacy signer fixture: ' . implode("\n", $legacyOutput)
        );
    }

    $legacyOutput = [];
    $legacyCode = 0;
    exec(
        'openssl rsa -traditional -in ' . escapeshellarg($legacyKey) .
        ' -out ' . escapeshellarg($legacyKey . '.pkcs1') . ' 2>&1',
        $legacyOutput,
        $legacyCode
    );
    if ($legacyCode !== 0 || !is_file($legacyKey . '.pkcs1')) {
        throw new RuntimeException(
            'Could not create a legacy PKCS#1 signer fixture: ' .
            implode("\n", $legacyOutput)
        );
    }
    @unlink($legacyKey);
    if (!rename($legacyKey . '.pkcs1', $legacyKey)) {
        throw new RuntimeException('Could not install legacy PKCS#1 signer fixture.');
    }

    $reportLegacy = AndroidClientPatcher::patchFile(
        $input,
        $output,
        'https://gdps-example.com'
    );
    if (($reportLegacy['signed'] ?? false) !== true) {
        throw new RuntimeException('Legacy PKCS#1 signer migration did not produce a signed APK.');
    }

    if (!is_file($signerKey)) {
        throw new RuntimeException('Legacy signer migration did not recreate the PKCS#8 key.');
    }
    $normalizedCheck = [];
    $normalizedCode = 0;
    exec(
        'openssl pkcs8 -inform DER -nocrypt -in ' . escapeshellarg($signerKey) .
        ' -out /dev/null 2>&1',
        $normalizedCheck,
        $normalizedCode
    );
    if ($normalizedCode !== 0) {
        throw new RuntimeException(
            'Legacy PKCS#1 signer was not normalized back to DER PKCS#8: ' .
            implode("\n", $normalizedCheck)
        );
    }

    $verifyOutput = [];
    $verifyCode = 0;
    exec(
        'apksigner verify --verbose ' . escapeshellarg($output) . ' 2>&1',
        $verifyOutput,
        $verifyCode
    );
    if ($verifyCode !== 0) {
        throw new RuntimeException(
            'apksigner verification failed: ' . implode("\n", $verifyOutput)
        );
    }

    putenv('MUCHO_ANDROID_SIGNER_DIR');
    echo "Android client patcher test passed\n";
} finally {
    putenv('MUCHO_ANDROID_SIGNER_DIR');
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_dir($file)) {
            foreach (glob($file . '/*') ?: [] as $nested) {
                @unlink($nested);
            }
            @rmdir($file);
            continue;
        }
        @unlink($file);
    }
    @rmdir($dir);
}
