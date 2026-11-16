<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/AndroidGd22144Binary.php';

use MuchoCore\Client\AndroidGd22144Binary as ModBinary;

$urls = [
    'https://www.boomlings.com/databas/checkIfServerOnline.php',
    'https://www.boomlings.com/database/getGJLevels21.php',
    'https://www.geometrydash.com/database/uploadMPComment.php',
];
$sample = "\x7fELF\0" . implode("\0", $urls) . "\0";
if (ModBinary::supports($sample)) {
    throw new RuntimeException('Unknown ELF was accepted as the exact uploaded build.');
}
[$changed, $count, $paths] = ModBinary::patchBytes($sample, 'https://muchogdps.space', 3);
if ($count !== 3 || strlen($changed) !== strlen($sample) ||
    !str_contains($changed, "https://muchogdps.space/database/checkIfServerOnline.php\0") ||
    !str_contains($changed, "https://muchogdps.space/database/uploadMPComment.php\0") ||
    !str_contains($changed, "https://muchogdps.space/database/getGJLevels21.php\0") ||
    str_contains($changed, 'www.boomlings.com') ||
    str_contains($changed, 'www.geometrydash.com') ||
    !in_array('/database/uploadMPComment.php', $paths, true)) {
    throw new RuntimeException('Native 2.2.144 URL patch was incomplete.');
}
try {
    ModBinary::patchBytes($sample, 'https://muchogdps.space', 64);
    throw new RuntimeException('Incomplete source was not rejected.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'expected 64 URL slots')) {
        throw $e;
    }
}
try {
    ModBinary::patchBytes($sample, 'http://muchogdps.space', 3);
    throw new RuntimeException('Insecure URL was accepted.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'HTTPS hostname')) {
        throw $e;
    }
}
if (!ModBinary::resembles22144($sample)) {
    throw new RuntimeException('Modified 2.2.144-like ELF needs a diagnostic fingerprint.');
}
echo "ANDROID_22144_SYNTHETIC_OK\n";
