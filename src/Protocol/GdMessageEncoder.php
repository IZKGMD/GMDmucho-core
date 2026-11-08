<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdMessageEncoder
{
    private function displayUsername(
        string $username,
        string $tag
    ): string {
        $tag=strtoupper(trim($tag));

        if ($tag==='') {
            return ProtocolText::username($username);
        }

        $prefix='['.$tag.']';
        return ProtocolText::username(
            $prefix.substr($username,0,max(1,20-strlen($prefix)))
        );
    }

    private const MESSAGE_KEY = '14251';
    private const MAX_SUBJECT_WIRE_LENGTH = 340; // Base64 of 255 UTF-8 bytes.
    private const MAX_BODY_WIRE_LENGTH = 87380; // Base64 of 65535 UTF-8 bytes.

    private function decodeBase64(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');

        // GD requests carry URL-safe Base64. Permit legacy standard Base64
        // too, but reject whitespace, stray delimiters and misplaced padding.
        if (preg_match('~\A[A-Za-z0-9+/]*={0,2}\z~D', $value) !== 1) {
            return null;
        }
        if (strlen($value) % 4 === 1) {
            return null;
        }

        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }

    private function xorMessage(string $value): string
    {
        $key = self::MESSAGE_KEY;
        $keyLength = strlen($key);
        $output = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $output .= $value[$i] ^ $key[$i % $keyLength];
        }

        return $output;
    }

    public function decodeSubject(string $value): ?string
    {
        if (strlen($value) > self::MAX_SUBJECT_WIRE_LENGTH) {
            return null;
        }
        $decoded = $this->decodeBase64($value);

        return $decoded !== null && preg_match('//u', $decoded) === 1
            ? $decoded
            : null;
    }

    public function decodeBody(string $value): ?string
    {
        if (strlen($value) > self::MAX_BODY_WIRE_LENGTH) {
            return null;
        }
        $decoded = $this->decodeBase64($value);

        if ($decoded === null) {
            return null;
        }

        $plain = $this->xorMessage($decoded);

        return preg_match('//u', $plain) === 1 ? $plain : null;
    }

    public function encodeSubject(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    public function encodeBody(string $value): string
    {
        return strtr(base64_encode($this->xorMessage($value)), '+/', '-_');
    }

    public function encodeMessage(array $message, bool $isSender = false): string
    {
        $username = ProtocolText::username(
            $isSender
                ? $this->displayUsername(
                    (string)($message['to_username'] ?? 'Player'),
                    (string)($message['to_clan_tag'] ?? '')
                )
                : $this->displayUsername(
                    (string)($message['username'] ?? 'Player'),
                    (string)($message['clan_tag'] ?? '')
                )
        );

        $isPlainText = (int)($message['content_format'] ?? 0) === 1;
        $subject = (string)$message['subject'];

        $data = [
            6, $username,
            3, $isSender ? $message['to_user_id'] : $message['user_id'],
            2, $isSender ? $message['to_account_id'] : $message['account_id'],
            1, $message['id'],
            4, $isPlainText ? $this->encodeSubject($subject) : $subject,
            8, (int)($message['is_read'] ?? 0),
            9, $isSender ? 1 : 0,
        ];

        if (isset($message['body'])) {
            $data[] = 5;
            $body = (string)$message['body'];
            $data[] = $isPlainText ? $this->encodeBody($body) : $body;
        }

        $data[] = 7;
        $data[] = $this->formatDate((string)$message['created_at']);

        return implode(':', $data);
    }

    public function encodeList(array $messages, int $total, int $offset, int $limit, bool $isSender = false): string
    {
        if ($messages === []) {
            return '-2'; // GD protocol response for an empty message list
        }

        $encoded = array_map(
            fn(array $m): string => $this->encodeMessage($m, $isSender),
            $messages
        );

        return implode('|', $encoded) . '#' . $total . ':' . $offset . ':' . $limit;
    }

    private function formatDate(string $date): string
    {
        $timestamp = strtotime($date);

        return $timestamp === false
            ? ''
            : date('d/m/Y G.i', $timestamp);
    }
}
