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

    $manifestXml = $dir . '/AndroidManifest.xml';
    if (
        file_put_contents(
            $manifestXml,
            '<?xml version="1.0" encoding="utf-8"?>' .
            '<manifest xmlns:android="http://schemas.android.com/apk/res/android" ' .
            'package="test.geometrydash"/>'
        ) === false
    ) {
        throw new RuntimeException('Cannot create manifest fixture.');
    }

    $aaptOutput = [];
    $aaptCode = 0;
    exec(
        'aapt package -f -M ' . escapeshellarg($manifestXml) .
        ' -F ' . escapeshellarg($input) . ' 2>&1',
        $aaptOutput,
        $aaptCode
    );
    if ($aaptCode !== 0 || !is_file($input)) {
        throw new RuntimeException(
            'Cannot build binary AndroidManifest.xml fixture: ' .
            implode("\n", $aaptOutput)
        );
    }

    $zip = new ZipArchive();
    if ($zip->open($input) !== true) {
        throw new RuntimeException('Cannot reopen fixture APK after aapt packaging.');
    }

    $oldUrl = 'http://www.boomlings.com/database';
    $oldHttpsUrl = 'https://www.boomlings.com/database';
    $zip->addFromString(
        'lib/arm64-v8a/libcocos2dcpp.so',
        "prefix\0" . $oldUrl . "\0" .
        base64_encode($oldUrl) . "\0" .
        $oldHttpsUrl . "\0" .
        base64_encode($oldHttpsUrl) . "\0" .
        'LEVELS_B64:' . base64_encode('http://www.boomlings.com/database/getGJLevels21.php') . "\0" .
        "https://geometrydash.com/database/getGJLevels21.php\0" .
        "https://geometrydash.com\0" .
        base64_encode('https://geometrydash.com/accounts/getGJAccount.php') . "\0" .
        "https://not-a-url.example/geometrydash.com\0suffix"
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

    @unlink($manifestXml);

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

    $expectedHttps = 'https://gdps-example.com/a/api/api';

    if (
        !is_string($native) ||
        !str_contains($native, $expectedHttps)
    ) {
        throw new RuntimeException('Patched HTTPS server URL not found.');
    }

    // Real Geometry Dash 2.2081 includes a Base64-encoded *complete* URL.
    // A replacement of only its Base64 prefix with a padded, shorter value
    // corrupts the string (an '=' would appear in the middle).
    if (
        preg_match('/LEVELS_B64:([A-Za-z0-9+\\/=]+)\\x00/', $native, $levelUrlMatch) !== 1 ||
        !isset($levelUrlMatch[1])
    ) {
        throw new RuntimeException('Patched 2.2081 Base64 level URL was not preserved.');
    }
    $decodedLevelUrl = base64_decode($levelUrlMatch[1], true);
    $parsedLevelUrl = is_string($decodedLevelUrl) ? parse_url($decodedLevelUrl) : false;
    if (
        !is_string($decodedLevelUrl) ||
        !is_array($parsedLevelUrl) ||
        ($parsedLevelUrl['scheme'] ?? '') !== 'https' ||
        ($parsedLevelUrl['host'] ?? '') !== 'gdps-example.com' ||
        !str_ends_with((string)($parsedLevelUrl['path'] ?? ''), '/getGJLevels21.php')
    ) {
        throw new RuntimeException(
            'Patched 2.2081 Base64 level URL must decode to a complete HTTPS GDPS endpoint.'
        );
    }

    if (str_contains($native, 'http://gdps-example.com')) {
        throw new RuntimeException('Cleartext HTTP server URL remained in the Android client.');
    }

    if (
        str_contains($native, $oldUrl) ||
        str_contains($native, $oldHttpsUrl)
    ) {
        throw new RuntimeException('Old server URL remained in the patched native library.');
    }

    if (
        str_contains($native, 'https://geometrydash.com/database/getGJLevels21.php') ||
        str_contains($native, 'https://geometrydash.com/accounts/getGJAccount.php')
    ) {
        throw new RuntimeException('Known embedded Geometry Dash host remained after patch.');
    }

    if (!str_contains($native, 'https://not-a-url.example/geometrydash.com')) {
        throw new RuntimeException('Non-URL host-like text was incorrectly modified.');
    }

    if (!str_contains($native, 'https://gdps-example.com' . "\0")) {
        throw new RuntimeException('NUL-terminated embedded host was not patched correctly.');
    }

    $signatureManifest = $check->getFromName('META-INF/MANIFEST.MF');
    $signatureFiles = [
        'META-INF/MANIFEST.MF',
        'META-INF/TEST.SF',
        'META-INF/TEST.RSA',
    ];

    if (!is_string($signatureManifest) || $signatureManifest === 'old signature manifest') {
        throw new RuntimeException('APK signing did not create a fresh signature manifest.');
    }

    foreach ($signatureFiles as $signatureFile) {
        $contents = $check->getFromName($signatureFile);
        if ($contents === 'old signature' || $contents === 'old certificate' || $contents === 'old signature manifest') {
            throw new RuntimeException('Stale signature content remained in ' . $signatureFile . '.');
        }
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
