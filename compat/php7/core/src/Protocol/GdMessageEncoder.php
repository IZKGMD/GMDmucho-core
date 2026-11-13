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
    public function encodeMessage(array $message, bool $isSender = false): string
    {
        $username = ProtocolText::username($isSender ? $this->displayUsername((string) ($message['to_username'] ?? 'Player'), (string) ($message['to_clan_tag'] ?? '')) : $this->displayUsername((string) ($message['username'] ?? 'Player'), (string) ($message['clan_tag'] ?? '')));
        $data = [6, $username, 3, $isSender ? $message['to_user_id'] : $message['user_id'], 2, $isSender ? $message['to_account_id'] : $message['account_id'], 1, $message['id'], 4, $message['subject'], 8, (int) ($message['is_read'] ?? 0), 9, $isSender ? 1 : 0];
        if (isset($message['body'])) {
            $data[] = 5;
            $data[] = $message['body'];
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
        if ($timestamp === false) {
            return '0 seconds';
        }
        $elapsed = max(0, time() - $timestamp);
        foreach (array(
            array(31536000, 'year'), array(2592000, 'month'),
            array(604800, 'week'), array(86400, 'day'),
            array(3600, 'hour'), array(60, 'minute')
        ) as $item) {
            if ($elapsed < $item[0]) {
                continue;
            }
            $count = intdiv($elapsed, $item[0]);
            return $count . ' ' . $item[1] . ($count === 1 ? '' : 's');
        }
        return $elapsed . ' second' . ($elapsed === 1 ? '' : 's');
    }
}
