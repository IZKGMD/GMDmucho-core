<?php

declare(strict_types=1);

require __DIR__ . '/../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../src/Http/Request.php';
require __DIR__ . '/../src/Http/Response.php';
require __DIR__ . '/../src/Diagnostics/ClientTrace.php';

use MuchoCore\Diagnostics\ClientTrace;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

$path = tempnam(sys_get_temp_dir(), 'muchocore-trace-');
if ($path === false) {
    throw new RuntimeException('Unable to allocate trace test file.');
}

putenv('MUCHO_CLIENT_TRACE=1');
putenv('MUCHO_CLIENT_TRACE_FILE=' . $path);

$request = new Request(
    'POST',
    '/loginGJAccount22.php',
    [],
    [
        'gameVersion' => '22',
        'binaryVersion' => '48',
        'gjp2' => 'fixture-secret',
        'userName' => 'fixture-user',
    ],
    []
);

ClientTrace::captureRequest($request);
ClientTrace::captureResponse(Response::text('1'));

$line = trim((string) file_get_contents($path));
@unlink($path);

if ($line === '') {
    throw new RuntimeException('Client trace was not written.');
}

$data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

if (($data['client_family'] ?? null) !== '2.2') {
    throw new RuntimeException('Trace did not record the 2.2 client family.');
}

if ((string) ($data['game_version'] ?? '') !== '22') {
    throw new RuntimeException('Trace did not record gameVersion=22.');
}

if ((string) ($data['binary_version'] ?? '') !== '48') {
    throw new RuntimeException('Trace did not record binaryVersion=48.');
}

if (in_array('fixture-secret', $data['post_keys'] ?? [], true)) {
    throw new RuntimeException('Trace must store POST key names, not credential values.');
}

if (!in_array('gjp2', $data['post_keys'] ?? [], true)) {
    throw new RuntimeException('Trace did not record the gjp2 field name.');
}

echo "CLIENT_TRACE_TEST_OK\n";
