<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Http/Request.php';
require dirname(__DIR__, 2) . '/src/Http/Response.php';
require dirname(__DIR__, 2) . '/src/Compatibility/ClientVersion.php';
require dirname(__DIR__, 2) . '/src/Diagnostics/ClientTrace.php';

use MuchoCore\Diagnostics\ClientTrace;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

$trace = tempnam(sys_get_temp_dir(), 'mucho-trace-');
if ($trace === false) {
    throw new RuntimeException('Could not allocate trace file.');
}

try {
    putenv('MUCHO_CLIENT_TRACE=1');
    putenv('MUCHO_CLIENT_TRACE_FILE=' . $trace);
    $request = new Request(
        'POST',
        '/database/getGJUserSearch.php',
        ['token' => 'secret', 'another secret=abc' => 'value'],
        [
            'gameVersion' => '22',
            'binaryVersion' => '49',
            'gjp2' => 'do-not-log-me',
            'accountID' => '123',
            'fake=credential' => 'hidden',
        ],
        [],
    );
    ClientTrace::captureRequest($request);
    ClientTrace::captureResponse(Response::text('-1'));

    $line = file_get_contents($trace);
    if (!is_string($line) || str_contains($line, 'do-not-log-me')
        || str_contains($line, 'another secret') || str_contains($line, 'fake=credential')
        || str_contains($line, '"post_keys":["gameVersion","binaryVersion","gjp2"') === false) {
        throw new RuntimeException('Trace secret filtering failed.');
    }
    $entry = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
    if ($entry['result_kind'] !== 'rejected'
        || $entry['binary_version'] !== 49
        || $entry['query_keys'] !== ['token']
        || in_array('fake=credential', $entry['post_keys'], true)) {
        throw new RuntimeException('Incorrect GD 2.209 trace fields.');
    }

    ClientTrace::captureRequest($request);
    ClientTrace::captureResponse(Response::text('-2'));
    ClientTrace::captureRequest($request);
    ClientTrace::captureResponse(Response::text('1:OK'));
    ClientTrace::captureRequest($request);
    ClientTrace::captureResponse(Response::text('-1', 500));
    $rows = array_values(array_filter(array_map(
        static fn(string $line): mixed => json_decode($line, true),
        explode("\n", trim((string)file_get_contents($trace))),
    )));
    $kinds = array_column($rows, 'result_kind');
    if ($kinds !== ['rejected','no_results','data','http_error']) {
        throw new RuntimeException('Wire classification regression: ' . json_encode($kinds));
    }

    $levelRequest = new Request(
        'POST', '/database/getGJLevels21.php', [], [
            'type' => '8',
            'gameVersion' => '22',
            'binaryVersion' => '51',
            'accountID' => '123',
            'gjp2' => 'do-not-log-me',
        ], []
    );
    ClientTrace::captureRequest($levelRequest);
    ClientTrace::captureResponse(Response::text('-2'));
    $last = array_values(array_filter(explode("\n", trim((string)file_get_contents($trace)))));
    $levelTrace = json_decode((string)end($last), true, 512, JSON_THROW_ON_ERROR);
    if ($levelTrace['level_list_type'] !== 8 || $levelTrace['result_kind'] !== 'no_results'
        || str_contains((string)file_get_contents($trace), 'do-not-log-me')) {
        throw new RuntimeException('GD 2.209 queue selector classification failed.');
    }

    echo "MUCHOCORE_GD_2209_TRACE_OK\n";
} finally {
    putenv('MUCHO_CLIENT_TRACE');
    putenv('MUCHO_CLIENT_TRACE_FILE');
    @unlink($trace);
}
