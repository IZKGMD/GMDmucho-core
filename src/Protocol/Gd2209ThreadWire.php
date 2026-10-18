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
            // Native 2.209 Thread Invite User Object: seventeen positional
            // fields, unlike the distinct getGJThreadInvites invite records.
            $result[] = implode(':', [
                (int)$row['invitee_account_id'], // account ID
                (int)($row['user_id'] ?? 0),     // user ID
                base64_encode((string)$row['username']),
                (int)$row['inviter_account_id'],
                (int)($row['cube'] ?? 1),
                (int)($row['color1'] ?? 0),
                (int)($row['color2'] ?? 3),
                0, // glow color not persisted
                0, // icon type unavailable in this query
                (int)($row['special'] ?? 0),
                0, 0, 0, 0, 0, 0, 0, // unavailable profile counters/date
            ]);
        }
        return implode('|', $result);
    }

    /** Thread object is fixed positional, not a key/value encoder. */
    public static function thread(array $row): string
    {
        // GD Docs: 26 fields, including the 4th (group title), 8th
        // (member count), 10th (owner) and 24th (last message time).
        return implode(':', [
            (int)$row['id'],         // 1 threadID
            0,                       // 2 peer account (DM only)
            '',                      // 3 DM username
            base64_encode((string)$row['title']), // 4 group title
            0,                       // 5 unread count (not yet stored)
            (int)($row['last_sender'] ?? 0), // 6 sender
            '',                      // 7 relative time (not stored)
            (int)($row['member_count'] ?? 0), // 8 actual group size
            '',                      // 9 sender username not joined
            (int)$row['owner_account_id'], // 10 group owner
            0, 0, 0, 0, 0,           // 11-15 mute/type/pin
            0, 0, 0, 0, 0, 0,        // 16-21 DM-only appearance
            '', '',                  // 22/23 group description/emote
            (int)($row['last_timestamp'] ?? 0), // 24 real UNIX time
            0, 0,                   // 25/26 badge/status
        ]);
    }

    /**
     * Native thread-list pagination: list#total:offset:amount:invites:interval.
     * GD Docs says 10/page; RobTop examples may return 20 items per page.
     */
    public static function threads(array $page): string
    {
        $items = array_map(self::thread(...), $page['rows']);
        return implode('|', $items) . '#' . implode(':', [
            (int)$page['total'], (int)$page['offset'], 10,
            (int)$page['invites'], 60
        ]);
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
