<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/WindowsClientPatcher.php';

use MuchoCore\Client\WindowsClientPatcher;

$dir = sys_get_temp_dir() . '/muchocore-web-patcher-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$input = $dir . '/GeometryDash.exe';
$output = $dir . '/GeometryDash-MuchoCore.exe';

try {
    $server = 'https://gdps.example.com';

    $source = str_repeat("A", 4 * 1024 * 1024 - 8);
    $source .= 'https://www.boomlings.com/database';
    $source .= str_repeat("B", 123);
    $source .= base64_encode('https://www.boomlings.com/database');
    $source .= str_repeat("C", 257);

    $source = "MZ" . str_repeat("\0", 58)
        . pack('V', 0x80)
        . str_repeat("\0", 0x80 - 64);
    $source .= "PE\0\0";
    $source .= str_repeat("D", 4 * 1024 * 1024 - strlen($source) - 8);
    $source .= 'https://www.boomlings.com/database';
    $source .= str_repeat("B", 123);
    $source .= base64_encode('https://www.boomlings.com/database');
    $source .= str_repeat("C", 257);

    file_put_contents($input, $source);

    $report = WindowsClientPatcher::patchFile(
        $input,
        $output,
        $server
    );

    if (($report['replacement_count'] ?? 0) < 2) {
        throw new RuntimeException('Expected direct and Base64 replacements.');
    }

    if (filesize($input) !== filesize($output)) {
        throw new RuntimeException('Patched file size changed.');
    }

    $patched = file_get_contents($output);
    $targets = array_keys(
        array_filter(
            $report['replacements'],
            static fn (int $count): bool => $count > 0
        )
    );

    $directTargets = array_values(array_filter(
        $targets,
        static fn (string $value): bool => str_starts_with(
            $value,
            'https://gdps.example.com/'
        )
    ));

    if (!is_string($patched) || $directTargets === []) {
        throw new RuntimeException('No generated target server URL was reported.');
    }

    $target = $directTargets[0];

    if (!str_contains($patched, $target)) {
        throw new RuntimeException('Generated target server URL not found after patch.');
    }

    if (str_contains($patched, 'https://www.boomlings.com/database')) {
        throw new RuntimeException('Old server URL still present after patch.');
    }

    if (!str_contains($patched, base64_encode($target))) {
        throw new RuntimeException('Generated Base64 server URL not found after patch.');
    }

    try {
        WindowsClientPatcher::validateServerUrl('https://example.com/not-root');
        throw new RuntimeException('Path-bearing server URL was accepted.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'server root')) {
            throw $expected;
        }
    }

    echo "Web client patcher tests passed\n";
} finally {
    foreach ([$input, $output] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}
