<?php

declare(strict_types=1);

/**
 * Inspect an opt-in MuchoCore client trace without exposing request payloads.
 *
 * Usage: php bin/mucho-gd2209-trace-report.php [--json] [--min-binary=N] [trace.ndjson]
 * Captures only the GD 2.2 protocol family (gameVersion=22). Binary-version
 * boundaries for 2.209 are intentionally not guessed: set --min-binary from
 * the real client capture to exclude earlier 2.2 traffic.
 */
$traceFile = getenv('MUCHO_CLIENT_TRACE_FILE')
    ?: dirname(__DIR__) . '/storage/client-trace.ndjson';
$jsonMode = false;
$minBinary = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $jsonMode = true;
    } elseif (preg_match('/^--min-binary=(\\d{1,5})$/D', $arg, $m) === 1) {
        $minBinary = (int)$m[1];
    } elseif (!str_starts_with($arg, '-')) {
        $traceFile = $arg;
    } else {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(2);
    }
}

if (!is_file($traceFile) || !is_readable($traceFile)) {
    fwrite(STDERR, "No readable trace file: $traceFile\n");
    exit(2);
}

$summary = [
    'game_version' => 22,
    'min_binary_version' => $minBinary,
    'included' => 0,
    'invalid_lines' => 0,
    'endpoints' => [],
];
$failureHash = hash('sha256', '-1');
$emptyHash = hash('sha256', '-2');
$file = new SplFileObject($traceFile, 'r');
while (!$file->eof()) {
    $line = trim((string)$file->fgets());
    if ($line === '') {
        continue;
    }
    $row = json_decode($line, true);
    if (!is_array($row)) {
        $summary['invalid_lines']++;
        continue;
    }
    if ((int)($row['game_version'] ?? 0) !== 22
        || (int)($row['binary_version'] ?? 0) < $minBinary) {
        continue;
    }
    $path = (string)($row['path'] ?? '');
    if ($path === '' || strlen($path) > 256) {
        $summary['invalid_lines']++;
        continue;
    }

    $kind = (string)($row['result_kind'] ?? '');
    if (!in_array($kind, ['http_error', 'rejected', 'no_results', 'empty', 'data'], true)) {
        $hash = (string)($row['response_sha256'] ?? '');
        $kind = $hash === $failureHash ? 'rejected'
            : ($hash === $emptyHash ? 'no_results'
                : ((int)($row['status'] ?? 200) >= 400 ? 'http_error' : 'data'));
    }
    $summary['included']++;
    if (!isset($summary['endpoints'][$path])) {
        $summary['endpoints'][$path] = [
            'total' => 0, 'rejected' => 0, 'http_error' => 0,
            'no_results' => 0, 'empty' => 0, 'data' => 0,
            'post_keys' => [],
        ];
    }
    $group = &$summary['endpoints'][$path];
    $group['total']++;
    $group[$kind]++;
    foreach (($row['post_keys'] ?? []) as $key) {
        if (is_string($key)
            && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $key) === 1) {
            $group['post_keys'][$key] = true;
        }
    }
    unset($group);
}
foreach ($summary['endpoints'] as &$group) {
    $group['post_keys'] = array_slice(array_keys($group['post_keys']), 0, 64);
    sort($group['post_keys']);
}
unset($group);
uksort($summary['endpoints'], static function (string $a, string $b) use ($summary): int {
    $left = $summary['endpoints'][$a];
    $right = $summary['endpoints'][$b];
    $failLeft = $left['rejected'] + $left['http_error'];
    $failRight = $right['rejected'] + $right['http_error'];
    return ($failRight <=> $failLeft) ?: strcmp($a, $b);
});

if ($jsonMode) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

echo "GD 2.2 / 2.209 candidate trace: {$summary['included']} requests";
echo $minBinary > 0 ? " (binaryVersion >= $minBinary)\n" : " (all binary versions; use --min-binary to filter)\n";
if ($summary['invalid_lines'] > 0) {
    echo "Skipped malformed lines: {$summary['invalid_lines']}\n";
}
foreach ($summary['endpoints'] as $path => $group) {
    printf("%-48s total=%d rejected=%d HTTP-errors=%d no-results=%d\n",
        $path, $group['total'], $group['rejected'],
        $group['http_error'], $group['no_results']);
    echo "    Request keys: ", implode(', ', $group['post_keys']), "\n";
}
