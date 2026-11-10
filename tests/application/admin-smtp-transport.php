<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminSmtp.php';

use MuchoCore\Admin\AdminSmtp;

function smtpCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL admin SMTP transport: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

function smtpSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Local socket pair unavailable for SMTP transport test.');
    }
    stream_set_timeout($pair[0], 1);
    stream_set_timeout($pair[1], 1);
    return $pair;
}

function smtpMustReject(ReflectionMethod $expect, string $reply, string $description): void
{
    [$writer, $reader] = smtpSocketPair();
    try {
        fwrite($writer, $reply);
        fclose($writer);
        $writer = null;
        $rejected = false;
        try {
            $expect->invoke(null, $reader, [250]);
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        smtpCheck($rejected, $description);
    } finally {
        if (is_resource($writer)) {
            fclose($writer);
        }
        fclose($reader);
    }
}

$expect = new ReflectionMethod(AdminSmtp::class, 'expect');
$writeAll = new ReflectionMethod(AdminSmtp::class, 'writeAll');

[$writer, $reader] = smtpSocketPair();
try {
    fwrite($writer, "250-mx.example.net\r\n250-AUTH PLAIN\r\n250 STARTTLS\r\n");
    $expect->invoke(null, $reader, [250]);
    smtpCheck(true, 'valid multiline SMTP response is accepted');
} finally {
    fclose($writer);
    fclose($reader);
}

smtpMustReject($expect, "550-Mailbox unavailable\r\n250 false success\r\n",
    'multiline reply cannot change an error code into success');
smtpMustReject($expect, "250-partial reply\r\n550 unexpected code\r\n",
    'multiline SMTP status must remain consistent');
smtpMustReject($expect, "250 reply without newline",
    'unterminated SMTP response cannot be accepted');

[$writer, $reader] = smtpSocketPair();
try {
    $payload = str_repeat("MuchoCore secure SMTP body\r\n", 100);
    $writeAll->invoke(null, $writer, $payload);
    smtpCheck(stream_get_contents($reader, strlen($payload)) === $payload,
        'SMTP writer transmits every byte of the payload');
} finally {
    fclose($writer);
    fclose($reader);
}

echo "ADMIN_SMTP_TRANSPORT_PASS\n";
