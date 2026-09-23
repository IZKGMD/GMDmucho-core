<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdMessageEncoder
{
    public function encodeMessage(array $message, bool $isSender = false): string
    {
        // Ключи GD для сообщений:
        // 1: ID сообщения
        // 2: ID аккаунта (отправителя/получателя в зависимости от контекста)
        // 3: ID пользователя (отправителя/получателя)
        // 4: Тема сообщения
        // 5: Текст сообщения (только при чтении полного сообщения)
        // 6: Имя пользователя (отправителя/получателя)
        // 7: Возраст сообщения (строка, напр. "2 days")
        // 8: Прочитано ли (0/1)
        // 9: Является ли отправителем (0/1)

        $username = ProtocolText::username(
            $isSender
                ? ($message['to_username'] ?? 'Player')
                : ($message['username'] ?? 'Player')
        );

        /*
         * Keep the legacy Geometry Dash field ordering used by the
         * Cvolton reference endpoint while retaining Mucho's
         * stronger authorization and storage model.
         */
        $data = [
            6, $username,
            3, $isSender ? $message['to_user_id'] : $message['user_id'],
            2, $isSender ? $message['to_account_id'] : $message['account_id'],
            1, $message['id'],
            4, ProtocolText::message($message['subject'] ?? ''),
            8, $message['is_read'],
            9, $isSender ? 1 : 0,
        ];

        if (isset($message['body'])) {
            $data[] = 5;
            $data[] = ProtocolText::message($message['body']);
        }

        $data[] = 7;
        $data[] = $this->formatDate((string)$message['created_at']);

        return implode(':', $data);
    }

    public function encodeList(array $messages, int $total, int $offset, int $limit, bool $isSender = false): string
    {
        if ($messages === []) {
            return '-2'; // Протокол GD для пустых списков сообщений
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
        if ($timestamp === false) return 'Unknown';
        
        $diff = time() - $timestamp;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' mins';
        if ($diff < 86400) return floor($diff / 3600) . ' hours';
        if ($diff < 2592000) return floor($diff / 86400) . ' days';
        if ($diff < 31536000) return floor($diff / 2592000) . ' months';
        return floor($diff / 31536000) . ' years';
    }
}
