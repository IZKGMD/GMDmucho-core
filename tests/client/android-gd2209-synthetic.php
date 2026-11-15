<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/AndroidGd2209Binary.php';
require dirname(__DIR__, 2) . '/src/Client/AndroidPackageRelocator.php';
require dirname(__DIR__, 2) . '/src/Client/WindowsClientPatcher.php';
require dirname(__DIR__, 2) . '/src/Client/AndroidClientPatcher.php';

use MuchoCore\Client\AndroidGd2209Binary;
use MuchoCore\Client\AndroidPackageRelocator as Relocator;

function check2209(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

// Synthetic ELF string block. The real build is checked by exact library
// SHA-256; this fixture only exercises the independent URL patching logic.
$one = "https://www.boomlings.com/database/getGJNotifications.php";
$two = "https://www.boomlings.com/databas/checkIfServerOnline.php";
$native = "\x7fELF\0" . $one . "\0" . $two . "\0https://example.com/other\0";
[$patched, $count, $paths] = AndroidGd2209Binary::patchBytes(
    $native, 'https://muchogdps.space', 2
);
check2209($count === 2, 'Two API URLs should have been replaced');
check2209(strlen($native) === strlen($patched), 'ELF size changed');
check2209(
    str_contains($patched, "https://muchogdps.space/database/getGJNotifications.php\0"),
    'Notification endpoint incorrect'
);
check2209(
    str_contains($patched, "https://muchogdps.space/database/checkIfServerOnline.php\0"),
    'Malformed legacy endpoint not corrected'
);
check2209(str_contains($patched, 'https://example.com/other'), 'Non-GD link changed');
check2209(!AndroidGd2209Binary::supports($native), 'Unknown ELF should not match build hash');
check2209(AndroidGd2209Binary::resembles2209(
    "\x7fELF\0getGJNotifications.php\0getGJThreadInvites.php\0"
), 'Unknown 2.209-like binary not recognized');

// Minimal but structurally valid UTF-16 AXML string-pool chunk, with two
// strings: the exact package ID and the fully qualified Java activity name.
$strings = [
    Relocator::SOURCE_ID,
    'com.robtopx.geometryjump.GeometryJump',
];
$payload = '';
$offsets = '';
foreach ($strings as $word) {
    $offsets .= pack('V', strlen($payload));
    $utf16 = '';
    foreach (str_split($word) as $char) $utf16 .= $char . "\0";
    $payload .= pack('v', strlen($word)) . $utf16 . "\0\0";
}
$stringsStart = 28 + strlen($offsets);
$poolSize = $stringsStart + strlen($payload);
$pool = pack('vvVVVVVV', 0x0001, 28, $poolSize, count($strings), 0, 0,
    $stringsStart, 0) . $offsets . $payload;
$xml = pack('vvV', 0x0003, 8, 8 + strlen($pool)) . $pool;
check2209(Relocator::manifestPackage($xml) === Relocator::SOURCE_ID,
    'Original manifest ID not recognized');
$relocated = Relocator::relocateManifest($xml);
check2209(Relocator::manifestPackage($relocated) === Relocator::SIDE_BY_SIDE_ID,
    'Relocated APK ID not recognized');
check2209(strlen($xml) === strlen($relocated), 'AXML length changed');
check2209(str_contains($relocated, 'G' . "\0" . 'e' . "\0"), 'Activity missing');
check2209(str_contains($relocated,
    // The activity's original Java class must not be renamed.
    implode("\0", str_split('com.robtopx.geometryjump.GeometryJump')) . "\0"),
    'Activity class incorrectly renamed');

$encode = static function (string $value): string {
    $bytes = '';
    foreach (str_split($value) as $char) $bytes .= $char . "\0";
    return $bytes;
};
$name = str_pad($encode(Relocator::SOURCE_ID), 256, "\0");
$packageChunk = pack('vvVV', 0x0200, 288, 288, 127) . $name .
    str_repeat("\0", 288 - 12 - 256);
$arsc = pack('vvVV', 0x0002, 12, 12 + strlen($packageChunk), 1) . $packageChunk;
$fixed = Relocator::relocateResources($arsc);
check2209(strlen($fixed) === strlen($arsc), 'Resource table size changed');
check2209(str_contains($fixed, $encode(Relocator::SIDE_BY_SIDE_ID) . "\0\0"),
    'Resource table package ID unchanged');

// A differently hashed yet layout-compatible ARM64 or ARMv7 GD 2.209 ELF
// can be patched without requiring the one pre-recorded APK SHA-256.
$library = str_repeat("\0", 1024 * 1024 + 128);
$library = substr_replace($library, "\x7fELF\x02\x01", 0, 6);
$library = substr_replace($library, pack('v', 183), 18, 2);
$endpointNames = ['getGJNotifications', 'getGJThreadInvites'];
for ($i = 0; $i < 111; $i++) {
    $endpointNames[] = 'getGJEndpoint' . $i;
}
$urls = array_map(
    static fn(string $name): string =>
        'https://www.boomlings.com/database/' . $name . '.php',
    $endpointNames
);
$library .= "\0" . implode("\0", $urls) . "\0";
check2209(!AndroidGd2209Binary::supports($library), 'Variant must not match exact SHA');
check2209(AndroidGd2209Binary::isStructurallyCompatible($library),
    'Valid complete alternate ARM64 endpoint layout rejected');
[$patchedVariant, $variantCount, $usedNative] =
    \MuchoCore\Client\AndroidClientPatcher::patchEntryBytes(
        'lib/arm64-v8a/libcocos2dcpp.so', $library, 'https://muchogdps.space'
    );
check2209($usedNative && $variantCount === 113, 'Variant patcher must rewrite all 113 endpoints');
check2209(strlen($patchedVariant) === strlen($library), 'Variant ELF must retain byte length');
check2209(str_contains($patchedVariant,
    "https://muchogdps.space/database/getGJThreadInvites.php\0"),
    'Alternate ARM64 thread endpoint not redirected');
check2209(!AndroidGd2209Binary::isStructurallyCompatible($patchedVariant),
    'Already redirected GD binary must not be patchable twice');

$armv7 = substr_replace($library, "\x01", 4, 1);
$armv7 = substr_replace($armv7, pack('v', 40), 18, 2);
check2209(AndroidGd2209Binary::isStructurallyCompatible($armv7),
    'Valid ARMv7 endpoint layout rejected');
[$armv7Patched, $armv7Count, $usedArmv7] =
    \MuchoCore\Client\AndroidClientPatcher::patchEntryBytes(
        'lib/armeabi-v7a/libcocos2dcpp.so', $armv7, 'https://muchogdps.space'
    );
check2209($usedArmv7 && $armv7Count === 113 &&
    strlen($armv7Patched) === strlen($armv7), 'ARMv7 variant not fully patched');

$incompatible = substr_replace($library, pack('v', 3), 18, 2);
check2209(!AndroidGd2209Binary::isStructurallyCompatible($incompatible),
    'x86 disguised as ARM must be rejected');
$incomplete = substr_replace($library, '\x00', strpos($library, $urls[10]), strlen($urls[10]));
check2209(!AndroidGd2209Binary::isStructurallyCompatible($incomplete),
    'Incomplete endpoint table must be rejected');

echo "android-gd2209-synthetic: OK\n";
