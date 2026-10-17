<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Client/Gd2209Binary.php';

use MuchoCore\Client\Gd2209Binary;

function verify2209(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Synthetic x64 PE with just enough headers for the .rdata-only URL patcher.
$original = str_repeat("\0", 0x400);
$original = substr_replace($original, 'MZ', 0, 2);
$original = substr_replace($original, pack('V', 0x80), 0x3c, 4);
$original = substr_replace($original, "PE\0\0", 0x80, 4);
$original = substr_replace($original, pack('v', 0x8664), 0x84, 2);
$original = substr_replace($original, pack('v', 1), 0x86, 2);
$original = substr_replace($original, pack('v', 0xf0), 0x94, 2);
$original = substr_replace($original, ".rdata\0\0", 0x188, 8);
$original = substr_replace($original, pack('V', 0x200), 0x188 + 16, 4);
$original = substr_replace($original, pack('V', 0x400), 0x188 + 20, 4);
$original .= "https://www.boomlings.com/database/getGJNotifications.php\0";
$original .= "https://www.boomlings.com/databas/checkIfServerOnline.php\0";
$original .= str_repeat("\0", 0x200 - (strlen($original) - 0x400));
$original .= 'EXTRA-UNTOUCHED';
[$patched, $count] = Gd2209Binary::patchBytes($original, 'muchogdps.space');
verify2209($count === 2, 'Expected 2 endpoint replacements');
verify2209(strlen($patched) === strlen($original), 'Executable size must not change');
verify2209(str_contains($patched, "https://muchogdps.space/database/getGJNotifications.php\0"), 'Notification endpoint missing');
verify2209(str_contains($patched, "https://muchogdps.space/database/checkIfServerOnline.php\0"), 'Malformed legacy check endpoint not corrected');
verify2209(substr($patched, -15) === 'EXTRA-UNTOUCHED', 'Data after .rdata was modified');
verify2209(substr($patched, 0, 0x400) === substr($original, 0, 0x400), 'PE code/header modified');
try {
    Gd2209Binary::patchBytes($original, str_repeat('a', 40) . '.test');
    throw new RuntimeException('Long host accepted, should reject');
} catch (RuntimeException $e) {
    verify2209(str_contains($e->getMessage(), 'too long'), 'Wrong long-host error');
}
echo "MUCHOCORE_GD_2209_SYNTHETIC_OK\n";
