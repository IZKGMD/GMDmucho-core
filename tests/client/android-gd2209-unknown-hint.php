<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/WindowsClientPatcher.php';
require dirname(__DIR__, 2) . '/src/Client/AndroidClientPatcher.php';

use MuchoCore\Client\AndroidClientPatcher;

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('ZipArchive extension needed for test.');
}

$dir = sys_get_temp_dir() . '/mucho-android-prepatched-' . bin2hex(random_bytes(5));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create test folder.');
}
$input = $dir . '/original.apk';
$output = $dir . '/patched.apk';
$elf = "\x7fELF\0" . random_bytes(2048) .
    "getGJNotifications.php\0getGJThreadInvites.php\0";
$zip = new ZipArchive();
try {
    if ($zip->open($input, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true ||
        !$zip->addFromString('lib/arm64-v8a/libcocos2dcpp.so', $elf) ||
        !$zip->addFromString('AndroidManifest.xml', 'NOT_AN_AXML_MANIFEST') ||
        !$zip->close()) {
        throw new RuntimeException('Unable to construct synthetic test APK.');
    }
    try {
        AndroidClientPatcher::patchFile(
            $input, $output, 'https://linasgdpsprivatese.5v.pl', null, true
        );
        throw new RuntimeException('Unverified 2.209 native build was patched.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'ORIGINAL') ||
            !str_contains($e->getMessage(), hash('sha256', $elf)) ||
            !str_contains($e->getMessage(), (string)strlen($elf))) {
            throw new RuntimeException('Unhelpful unsupported-build diagnostics: ' .
                $e->getMessage());
        }
    }
    if (is_file($output)) {
        throw new RuntimeException('Rejected APK must not produce an output.');
    }
    // An older or otherwise unsupported APK must not be silently relocated.
    // The user-facing message should point to the safe flag/configuration choice.
    $otherInput = $dir . '/other-build.apk';
    $otherZip = new ZipArchive();
    if ($otherZip->open($otherInput, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true ||
        !$otherZip->addFromString('AndroidManifest.xml', 'OTHER_BUILD') ||
        !$otherZip->addFromString('assets/padding.bin', random_bytes(2048)) ||
        !$otherZip->close()) {
        throw new RuntimeException('Unable to construct unsupported APK test.');
    }
    try {
        AndroidClientPatcher::patchFile(
            $otherInput, $output, 'https://muchogdps.space', null, true
        );
        throw new RuntimeException('Non-verified APK was relocated.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Side-by-side package relocation is currently supported') ||
            !str_contains($e->getMessage(), 'verified GD 2.209 ARM64 build')) {
            throw new RuntimeException('Unhelpful side-by-side diagnostics: ' .
                $e->getMessage());
        }
    }
    if (is_file($output)) {
        throw new RuntimeException('Unsupported APK must not generate an output.');
    }
    @unlink($otherInput);
    // Keep explanatory UI in the modern admin without altering the
    // SHA-pinned runtime source consumed by the PHP7 compiled port.
    $module = file_get_contents(dirname(__DIR__, 2) .
        '/public/admin/android-client-patcher-module.php');
    if (!is_string($module) ||
        !str_contains($module, 'mcAndroidPatcherAlongsideHelp') ||
        !str_contains($module, 'not interchangeable')) {
        throw new RuntimeException('Missing actionable side-by-side help in admin UI.');
    }
    echo "ANDROID_2209_UNKNOWN_BUILD_HINT_OK\n";
} finally {
    @unlink($input);
    @unlink($output);
    @rmdir($dir);
}
