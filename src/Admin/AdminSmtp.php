<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use MuchoCore\Core\Environment;
use RuntimeException;

/**
 * SMTP transport for administrator security messages.
 * TLS and SMTP AUTH are mandatory. No mail() or plaintext fallback.
 */
final class AdminSmtp
{
    public static function configured(): bool
    {
        foreach (['MUCHO_ADMIN_SMTP_HOST', 'MUCHO_ADMIN_SMTP_USER',
                  'MUCHO_ADMIN_SMTP_PASSWORD', 'MUCHO_ADMIN_MAIL_FROM',
                  'MUCHO_ADMIN_MAIL_ORIGIN'] as $key) {
            if (trim((string) Environment::get($key, '')) === '') {
                return false;
            }
        }
        return self::origin() !== null;
    }

    public static function origin(): ?string
    {
        $url = trim((string) Environment::get('MUCHO_ADMIN_MAIL_ORIGIN', ''));
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) || (isset($parts['user']) || isset($parts['pass'])) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            isset($parts['path']) || isset($parts['port']) ||
            !preg_match('/^[a-z0-9.-]+$/iD', (string) $parts['host'])) {
            return null;
        }
        return 'https://' . strtolower((string) $parts['host']);
    }

    public static function send(string $to, string $subject, string $message): bool
    {
        if (!self::configured() || !filter_var($to, FILTER_VALIDATE_EMAIL) ||
            preg_match('/[\r\n]/', $to . $subject) ||
            !preg_match('/^[\x20-\x7E]+$/D', $subject)) {
            return false;
        }

        $host = trim((string) Environment::get('MUCHO_ADMIN_SMTP_HOST', ''));
        $user = (string) Environment::get('MUCHO_ADMIN_SMTP_USER', '');
        $password = (string) Environment::get('MUCHO_ADMIN_SMTP_PASSWORD', '');
        $from = trim((string) Environment::get('MUCHO_ADMIN_MAIL_FROM', ''));
        $mode = strtolower((string) Environment::get('MUCHO_ADMIN_SMTP_SECURITY', 'starttls'));
        $port = (int) Environment::get('MUCHO_ADMIN_SMTP_PORT', $mode === 'tls' ? '465' : '587');

        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535 ||
            !filter_var($from, FILTER_VALIDATE_EMAIL) ||
            preg_match('/[\r\n]/', $from . $user) ||
            !in_array($mode, ['starttls', 'tls'], true)) {
            return false;
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'allow_self_signed' => false, 'peer_name' => $host,
        ]]);
        $endpoint = ($mode === 'tls' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($endpoint, $errno, $errstr, 7,
            STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            error_log('[MuchoCore Admin] SMTP connection unavailable.');
            return false;
        }

        stream_set_timeout($socket, 7);
        try {
            self::expect($socket, [220]);
            self::command($socket, "EHLO muchocore\r\n", [250]);
            if ($mode === 'starttls') {
                self::command($socket, "STARTTLS\r\n", [220]);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                self::command($socket, "EHLO muchocore\r\n", [250]);
            }

            self::command($socket, "AUTH PLAIN " . base64_encode("\0" . $user . "\0" . $password) . "\r\n", [235]);
            self::command($socket, "MAIL FROM:<{$from}>\r\n", [250]);
            self::command($socket, "RCPT TO:<{$to}>\r\n", [250, 251]);
            self::command($socket, "DATA\r\n", [354]);
            $headers = [
                'From: MuchoCore <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $subject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'Auto-Submitted: auto-generated',
            ];
            $content = implode("\r\n", $headers) . "\r\n\r\n" .
                preg_replace('/(?<!\r)\n/', "\r\n", str_replace("\r\n", "\n", $message));
            $content = preg_replace('/(?m)^\./', '..', $content);
            self::writeAll($socket, rtrim($content, "\r\n") . "\r\n.\r\n");
            self::expect($socket, [250]);

            // A 250 response to DATA commits acceptance. QUIT may fail even
            // though the message has already been accepted by the relay.
            try {
                self::command($socket, "QUIT\r\n", [221]);
            } catch (\Throwable $e) {
                // Do not invalidate an already accepted password-reset link.
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[MuchoCore Admin] SMTP delivery failed: ' . get_class($e));
            return false;
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $line, array $expected): void
    {
        self::writeAll($socket, $line);
        self::expect($socket, $expected);
    }

    private static function writeAll($socket, string $payload): void
    {
        $length = strlen($payload);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($socket, substr($payload, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('SMTP write failed');
            }
            $offset += $written;
        }
    }

    private static function expect($socket, array $expected): void
    {
        $responseCode = null;
        for ($i = 0; $i < 32; $i++) {
            $line = fgets($socket, 2048);
            if (!is_string($line) || !str_ends_with($line, "\n") ||
                !preg_match('/^([0-9]{3})([ -])/', $line, $match)) {
                throw new RuntimeException('SMTP response unavailable');
            }
            $code = (int) $match[1];
            if ($responseCode === null) {
                if (!in_array($code, $expected, true)) {
                    throw new RuntimeException('SMTP rejected request');
                }
                $responseCode = $code;
            } elseif ($code !== $responseCode) {
                throw new RuntimeException('SMTP inconsistent multiline response');
            }
            if ($match[2] === ' ') {
                return;
            }
        }
        throw new RuntimeException('SMTP response too long');
    }
}
