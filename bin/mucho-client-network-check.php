<?php

declare(strict_types=1);

/**
 * Non-destructive network diagnostics for a GDPS that uses a patched client.
 * This is not a proof of native game compatibility. Never sends credentials.
 *
 * php bin/mucho-client-network-check.php https://gdps.example.com
 * php bin/mucho-client-network-check.php --self-test
 */

function classifyResponse(string $kind, int $status, string $body): array
{
    $trimmed = trim($body);
    if ($status >= 300 && $status < 400) {
        return ['result' => 'FAIL', 'reason' => 'HTTP redirect instead of game wire response'];
    }
    if ($status !== 200) {
        return ['result' => 'FAIL', 'reason' => 'HTTP ' . $status];
    }
    if (preg_match('~^\\s*(?:<!doctype\\s+html|<html\\b|<head\\b)~i', $body) === 1) {
        return ['result' => 'FAIL', 'reason' => 'HTML/WAF/login page instead of game wire response'];
    }
    if ($trimmed === '') {
        return ['result' => 'FAIL', 'reason' => 'Empty game endpoint response'];
    }
    if ($kind === 'liveness') {
        return $trimmed === '1'
            ? ['result' => 'OK', 'reason' => 'Exact game liveness response']
            : ['result' => 'FAIL', 'reason' => 'Expected literal 1; endpoint returned something else'];
    }
    if ($trimmed === '-1' || $trimmed === '-2') {
        return ['result' => 'INCONCLUSIVE', 'reason' => 'Game-style error/empty marker; may mean empty data or backend failure'];
    }
    if (preg_match('~^[0-9]+:(?:[0-9]+:)?[0-9]+#~', $trimmed) === 1
        || str_contains($trimmed, '#')) {
        return ['result' => 'OK', 'reason' => 'Protocol-shaped response, not proof of native client acceptance'];
    }
    return ['result' => 'INCONCLUSIVE', 'reason' => 'HTTP succeeded but game wire payload is unrecognized'];
}

if (in_array('--self-test', $argv, true)) {
    foreach ([
        ['liveness', 200, '1', 'OK'],
        ['liveness', 200, "1\n", 'OK'],
        ['liveness', 200, '<html><title>Checking your browser</title>', 'FAIL'],
        ['liveness', 301, '1', 'FAIL'],
        ['liveness', 403, '1', 'FAIL'],
        ['liveness', 200, '-1', 'FAIL'],
        ['levels', 200, '-1', 'INCONCLUSIVE'],
        ['levels', 200, '0:0:10#', 'OK'],
        ['levels', 200, '<!doctype html>', 'FAIL'],
    ] as [$kind, $status, $body, $expected]) {
        if (classifyResponse($kind, $status, $body)['result'] !== $expected) {
            throw new RuntimeException("Network doctor failed: {$kind}/{$status}");
        }
    }
    echo "mucho-client-network-check: OK\n";
    exit(0);
}

$base = (string)($argv[1] ?? '');
$parts = parse_url($base);
if (!is_array($parts)
    || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
    || empty($parts['host'])
    || isset($parts['user']) || isset($parts['pass'])
    || !in_array(($parts['path'] ?? ''), ['', '/'], true)
    || isset($parts['query'])
    || isset($parts['fragment'])) {
    fwrite(STDERR, "Usage: php bin/mucho-client-network-check.php https://your-gdps-domain\n");
    exit(2);
}

$origin = 'https://' . $parts['host']
    . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');

function probe(string $url, string $method, array $post = []): array
{
    $body = $method === 'POST' ? http_build_query($post) : '';
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                . "Accept: text/plain\r\n"
                . "Accept-Encoding: identity\r\n"
                . "User-Agent: MuchoCore-Network-Preflight/1.0\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 12,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx, 0, 32768);
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $line) {
        if (preg_match('~^HTTP/\\S+\\s+(\\d{3})~i', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    return ['status' => $status, 'body' => is_string($response) ? $response : '',
        'connected' => $response !== false];
}

$checks = [
    ['GET', '/health', 'liveness', []],
    ['POST', '/database/checkIfServerOnline.php', 'liveness', []],
    ['POST', '/database/getGJLevels21.php', 'levels',
        ['gameVersion' => '22', 'binaryVersion' => '41', 'type' => '0', 'str' => '', 'page' => '0']],
];

echo "MuchoCore GDPS HTTP network preflight — {$origin}\n";
$failed = false;
foreach ($checks as [$method, $path, $kind, $data]) {
    $test = probe($origin . $path, $method, $data);
    $classification = $test['connected']
        ? classifyResponse($kind, $test['status'], $test['body'])
        : ['result' => 'FAIL', 'reason' => 'Connection/TLS failure or response unavailable'];
    if ($classification['result'] === 'FAIL') {
        $failed = true;
    }
    printf(
        "%s %-5s %-43s HTTP=%d: %s\n",
        $classification['result'], $method, $path,
        $test['status'], $classification['reason']
    );
}
echo "NOTE: Server network checks cannot prove an APK/EXE connects. A native client capture is required.\n";
exit($failed ? 1 : 0);
