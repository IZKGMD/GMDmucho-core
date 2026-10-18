<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

/**
 * GD 2.209 thread-message response serialization.
 *
 * Verified framing: messages#members#formerMembers#invites#metadata#
 *                   lastMessageID#3,1,10,50
 * Verified message object: id:sender:base64(text):timestamp:deleted.
 * Metadata and invite slots are provisional for MuchoCore group threads,
 * pending full, redacted native-client fixtures.
 */
final class Gd2209ThreadWire
{
    private static function timestamp(string $date): int
    {
        $time = strtotime($date);
        return $time === false ? 0 : $time;
    }

    public static function message(array $row): string
    {
        return implode(':', [
            (int)$row['id'],
            (int)$row['sender_account_id'],
            base64_encode((string)$row['body']),
            self::timestamp((string)$row['created_at']),
            (int)($row['is_deleted'] ?? 0) === 1 ? 1 : 0,
        ]);
    }

    public static function messages(array $rows): string
    {
        return $rows === [] ? 'none' : implode('|', array_map(self::message(...), $rows));
    }

    /**
     * Positional GD 2.209 group metadata. Only owner/title are populated
     * from DB facts; currently unavailable description/emote data stay empty.
     * More fields must be mapped before declaring verified native parity.
     */
    public static function metadata(array $thread): string
    {
        return implode(':', [
            (int)$thread['owner_account_id'],
            0, 2, '', 0, 0, 0, 0,
            base64_encode((string)$thread['title']),
            0, 0,
        ]);
    }

    /**
     * Inviters and targets are actual stored accounts, but optional native
     * ThreadInviteUser trailing fields remain unconfirmed.
     */
    public static function invites(array $rows): string
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = implode(':', [
                (int)$row['id'],
                (int)$row['invitee_account_id'],
                base64_encode((string)$row['username']),
                (int)$row['inviter_account_id'],
                3,
            ]);
        }
        return implode('|', $result);
    }

    public static function snapshot(
        array $data, GdRelationshipEncoder $users
    ): string {
        $members = $data['members'] === []
            ? '' : $users->users($data['members'], true);
        return implode('#', [
            self::messages($data['messages']),
            $members,
            '', // No departed-user archive exists in MuchoCore yet.
            self::invites($data['invites']),
            self::metadata($data['thread']),
            '0', // Snapshot visibility is restricted to active members.
            '3,1,10,50',
        ]);
    }
}
