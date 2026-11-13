<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Social\MessageTrace;

$path = tempnam(sys_get_temp_dir(), 'mucho-message-trace-');
if (!is_string($path)) {
    throw new RuntimeException('Cannot create disposable log fixture.');
}
chmod($path, 0600);
$oldLog = ini_get('error_log');
$oldErrors = ini_get('log_errors');
$oldEnv = getenv('MUCHO_MESSAGE_DIAGNOSTICS');
$oldServer = $_SERVER['MUCHO_REQUEST_ID'] ?? null;

try {
    putenv('MUCHO_MESSAGE_DIAGNOSTICS=1');
    $_ENV['MUCHO_MESSAGE_DIAGNOSTICS'] = '1';
    ini_set('error_log', $path);
    ini_set('log_errors', '1');

    $_SERVER['MUCHO_REQUEST_ID'] = '0123456789abcdef';
    MessageTrace::emit('entry', 'seen');
    MessageTrace::emit('decoding', 'invalid_body');
    MessageTrace::emit('decoding', 'secret-password-or-message'); // not allowlisted
    MessageTrace::emit('user-controlled-phase', 'seen'); // not allowlisted
    $_SERVER['MUCHO_REQUEST_ID'] = "unsafe-id\ncredential=secret";
    MessageTrace::emit('storage', 'stored');

    $log = file_get_contents($path);
    if (!is_string($log)) {
        throw new RuntimeException('Unable to read metadata-only trace.');
    }
    $lines = array_values(array_filter(explode("\n", trim($log))));
    $expected = [
        '[MuchoCore MessageTrace] request_id=0123456789abcdef phase=entry reason=seen',
        '[MuchoCore MessageTrace] request_id=0123456789abcdef phase=decoding reason=invalid_body',
        '[MuchoCore MessageTrace] request_id=0123456789abcdef phase=decoding reason=other',
        '[MuchoCore MessageTrace] request_id=- phase=storage reason=stored',
    ];
    if (count($lines) !== count($expected)) {
        throw new RuntimeException('Trace length mismatch: ' . count($lines));
    }
    foreach ($expected as $index => $line) {
        if (!str_contains($lines[$index], $line)) {
            throw new RuntimeException('Missing expected metadata trace: ' . $index);
        }
    }
    if (str_contains($log, 'secret-password-or-message') ||
        str_contains($log, 'unsafe-id') ||
        str_contains($log, 'credential=secret')) {
        throw new RuntimeException('Untrusted content leaked into trace.');
    }
    echo "MUCHOCORE_GD2209_MESSAGE_TRACE_OK\n";
} finally {
    if ($oldEnv === false) {
        putenv('MUCHO_MESSAGE_DIAGNOSTICS');
    } else {
        putenv('MUCHO_MESSAGE_DIAGNOSTICS=' . $oldEnv);
    }
    unset($_ENV['MUCHO_MESSAGE_DIAGNOSTICS']);
    if ($oldServer === null) {
        unset($_SERVER['MUCHO_REQUEST_ID']);
    } else {
        $_SERVER['MUCHO_REQUEST_ID'] = $oldServer;
    }
    ini_set('error_log', (string)$oldLog);
    ini_set('log_errors', (string)$oldErrors);
    unlink($path);
}
