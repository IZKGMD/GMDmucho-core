<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = tempnam(sys_get_temp_dir(), 'mc-probe-');
if ($file === false) {
    throw new RuntimeException('Cannot create test report.');
}
try {
    $audit = static function (array $endpoints) use ($root, $file): array {
        file_put_contents($file, json_encode([
            'schema_version' => 1,
            'endpoints' => array_map(static fn(string $path): array => ['path' => $path], $endpoints),
        ], JSON_THROW_ON_ERROR));
        $lines = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' .
            escapeshellarg($root . '/bin/mucho-client-audit.php') . ' ' .
            escapeshellarg($file), $lines, $status);
        return [$status, json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR)];
    };
    [$status, $report] = $audit(['/getGJLevels21.php', '/loginGJAccount22.php', '/checkIfServerOnline.php']);
    if ($status !== 0 || !$report['static_routes_mapped'] ||
        $report['mapped_endpoints']['/loginGJAccount22.php'] !== '/logingjaccount' ||
        $report['runtime_verified'] !== false || $report['native_client_verified'] !== false) {
        throw new RuntimeException('Known aliases must resolve without claiming runtime/native compatibility.');
    }
    [$status, $report] = $audit(['/getGJNewFeature2209.php']);
    if ($status !== 1 || $report['static_routes_mapped'] ||
        !isset($report['unmapped_endpoints']['/getGJNewFeature2209.php'])) {
        throw new RuntimeException('An unknown 2.209 endpoint must be reported rather than certified.');
    }
    echo "CLIENT_BINARY_AUDIT_OK\n";
} finally {
    @unlink($file);
}
