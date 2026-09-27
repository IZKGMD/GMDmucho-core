#!/usr/bin/env php
<?php

declare(strict_types=1);

$command = $argv[1] ?? '';
$file = $argv[2] ?? '';

$read = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }

    $rows = [];
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return [];
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $row = json_decode($line, true);

        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    fclose($handle);
    return $rows;
};

if ($file === '' || !is_file($file)) {
    fwrite(
        STDERR,
        "Usage: mucho-trace inspect TRACE_FILE\n" .
        "       mucho-trace diff LEFT_TRACE RIGHT_TRACE\n"
    );
    exit(2);
}

if ($command === 'inspect') {
    $rows = $read($file);
    $summary = [
        'entries' => count($rows),
        'statuses' => [],
        'families' => [],
        'paths' => [],
        'response_bytes' => 0,
    ];

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '?');
        $family = (string)($row['client_family'] ?? '?');
        $path = (string)($row['path'] ?? '?');

        $summary['statuses'][$status] = ($summary['statuses'][$status] ?? 0) + 1;
        $summary['families'][$family] = ($summary['families'][$family] ?? 0) + 1;
        $summary['paths'][$path] = ($summary['paths'][$path] ?? 0) + 1;
        $summary['response_bytes'] += (int)($row['response_length'] ?? 0);
    }

    arsort($summary['paths']);

    echo json_encode(
        $summary,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

if ($command === 'diff') {
    $rightFile = $argv[3] ?? '';

    if ($rightFile === '' || !is_file($rightFile)) {
        fwrite(STDERR, "A second trace file is required.\n");
        exit(2);
    }

    $left = $read($file);
    $right = $read($rightFile);

    $signature = static function (array $row): string {
        return implode('|', [
            (string)($row['method'] ?? ''),
            (string)($row['path'] ?? ''),
            (string)($row['client_family'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['response_length'] ?? ''),
            (string)($row['response_sha256'] ?? ''),
        ]);
    };

    $count = static function (array $rows) use ($signature): array {
        $set = [];

        foreach ($rows as $row) {
            $key = $signature($row);
            $set[$key] = ($set[$key] ?? 0) + 1;
        }

        return $set;
    };

    $leftSet = $count($left);
    $rightSet = $count($right);

    $keys = array_values(array_unique(array_merge(
        array_keys($leftSet),
        array_keys($rightSet)
    )));

    $changes = [];

    foreach ($keys as $key) {
        $a = $leftSet[$key] ?? 0;
        $b = $rightSet[$key] ?? 0;

        if ($a !== $b) {
            $changes[] = [
                'signature' => $key,
                'left' => $a,
                'right' => $b,
            ];
        }
    }

    echo json_encode(
        [
            'left_entries' => count($left),
            'right_entries' => count($right),
            'changes' => $changes,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;

    exit($changes === [] ? 0 : 1);
}

fwrite(STDERR, "Unknown trace command.\n");
exit(2);
