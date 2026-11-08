<?php

declare (strict_types=1);
namespace MuchoCore\Protocol;

final class GdMessageEncoder
{
    private function displayUsername(string $username, string $tag): string
    {
        $tag = strtoupper(trim($tag));
        if ($tag === '') {
            return ProtocolText::username($username);
        }
        $prefix = '[' . $tag . ']';
        return ProtocolText::username($prefix . substr($username, 0, max(1, 20 - strlen($prefix))));
    }
    const MESSAGE_KEY = '14251';
    private function decodeBase64($value)
    {
        $value = strtr($value, '-_', '+/');
        if (strlen($value) % 4 === 1) {
            return false;
        }
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return is_string($decoded) && preg_match('//u', $decoded) === 1 ? $decoded : false;
    }
    private function xorMessage($value)
    {
        $key = self::MESSAGE_KEY;
        $keyLength = strlen($key);
        $output = '';
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $output .= $value[$i] ^ $key[$i % $keyLength];
        }
        return $output;
    }
    public function decodeSubject($value)
    {
        return $this->decodeBase64($value);
    }
    public function decodeBody($value)
    {
        $decoded = $this->decodeBase64($value);
        if ($decoded === false) {
            return false;
        }
        $plain = $this->xorMessage($decoded);
        return preg_match('//u', $plain) === 1 ? $plain : false;
    }
    public function encodeSubject($value)
    {
        return strtr(base64_encode($value), '+/', '-_');
    }
    public function encodeBody($value)
    {
        return strtr(base64_encode($this->xorMessage($value)), '+/', '-_');
    }
    public function encodeMessage(array $message, bool $isSender = false): string
    {
        $username = ProtocolText::username($isSender ? $this->displayUsername((string) ($message['to_username'] ?? 'Player'), (string) ($message['to_clan_tag'] ?? '')) : $this->displayUsername((string) ($message['username'] ?? 'Player'), (string) ($message['clan_tag'] ?? '')));
        $data = [6, $username, 3, $isSender ? $message['to_user_id'] : $message['user_id'], 2, $isSender ? $message['to_account_id'] : $message['account_id'], 1, $message['id'], 4, $this->encodeSubject((string) $message['subject']), 8, (int) ($message['is_read'] ?? 0), 9, $isSender ? 1 : 0];
        if (isset($message['body'])) {
            $data[] = 5;
            $data[] = $this->encodeBody((string) $message['body']);
        }
        $data[] = 7;
        $data[] = $this->formatDate((string) $message['created_at']);
        return implode(':', $data);
    }
    public function encodeList(array $messages, int $total, int $offset, int $limit, bool $isSender = false): string
    {
        if ($messages === []) {
            return '-2';
            // GD protocol response for an empty message list
        }
        $encoded = array_map(function (array $m) use ($isSender): string {
            return $this->encodeMessage($m, $isSender);
        }, $messages);
        return implode('|', $encoded) . '#' . $total . ':' . $offset . ':' . $limit;
    }
    private function formatDate(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp === false ? '' : date('d/m/Y G.i', $timestamp);
    }
}
