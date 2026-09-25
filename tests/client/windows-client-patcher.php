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

    /*
     * Build a synthetic PE containing both HTTPS and HTTP legacy URL forms
     * plus their Base64 variants. Keep the exact source data inside the final
     * PE payload so the test exercises the bytes actually passed to the patcher.
     */
    $source = "MZ" . str_repeat("\0", 58)
        . pack('V', 0x80)
        . str_repeat("\0", 0x80 - 64);

    $source .= "PE\0\0";

    $payload = str_repeat("D", 4 * 1024 * 1024 - strlen($source) - 8);
    $payload .= 'https://www.boomlings.com/database';
    $payload .= str_repeat("B", 123);
    $payload .= base64_encode('https://www.boomlings.com/database');
    $payload .= str_repeat("E", 191);
    $payload .= 'http://www.boomlings.com/database';
    $payload .= str_repeat("F", 113);
    $payload .= base64_encode('http://www.boomlings.com/database');
    $payload .= str_repeat("C", 257);

    $source .= $payload;

    file_put_contents($input, $source);

    $report = WindowsClientPatcher::patchFile(
        $input,
        $output,
        $server
    );

    if (($report['replacement_count'] ?? 0) < 4) {
        throw new RuntimeException('Expected HTTPS and HTTP direct/Base64 replacements.');
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

    $httpsTargets = array_values(array_filter(
        $targets,
        static fn (string $value): bool => str_starts_with(
            $value,
            'https://gdps.example.com/'
        )
    ));

    $httpTargets = array_values(array_filter(
        $targets,
        static fn (string $value): bool => str_starts_with(
            $value,
            'http://gdps.example.com/'
        )
    ));

    if (!is_string($patched) || $httpsTargets === [] || $httpTargets === []) {
        throw new RuntimeException('Expected generated HTTPS and HTTP target URLs.');
    }

    $httpsTarget = $httpsTargets[0];
    $httpTarget = $httpTargets[0];

    if (!str_contains($patched, $httpsTarget)) {
        throw new RuntimeException('Generated HTTPS target URL not found after patch.');
    }

    if (!str_contains($patched, $httpTarget)) {
        throw new RuntimeException('Generated HTTP target URL not found after patch.');
    }

    if (str_contains($patched, 'https://www.boomlings.com/database')) {
        throw new RuntimeException('Old HTTPS server URL still present after patch.');
    }

    if (str_contains($patched, 'http://www.boomlings.com/database')) {
        throw new RuntimeException('Old HTTP server URL still present after patch.');
    }

    if (!str_contains($patched, base64_encode($httpsTarget))) {
        throw new RuntimeException('Generated HTTPS Base64 server URL not found after patch.');
    }

    if (!str_contains($patched, base64_encode($httpTarget))) {
        throw new RuntimeException('Generated HTTP Base64 server URL not found after patch.');
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
