<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;
use RuntimeException;
use Throwable;

final class CvoltonDatabaseImporter
{
    private const BATCH = 250;

    /**
     * Columns consumed by the importer are checked before any destination
     * changes are allowed. This turns fork/schema mismatches into a clean
     * preflight failure instead of a mid-import failure.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'accounts' => [
            'accountID', 'userName', 'password', 'gjp2', 'email', 'isActive',
        ],
        'users' => [
            'extID', 'stars', 'moons', 'diamonds', 'coins', 'userCoins',
            'demons', 'creatorPoints', 'icon', 'iconType', 'color1', 'color2',
            'accGlow', 'isBanned',
        ],
        'levels' => [
            // The remaining level fields are optional in older Cvolton forks;
            // levelParams() supplies deterministic defaults for them.
            'levelID', 'levelName', 'levelDesc', 'levelString', 'levelVersion',
            'gameVersion', 'levelLength', 'audioTrack', 'extID',
        ],
        'levelscores' => [
            'scoreID', 'accountID', 'levelID',
        ],
        'platscores' => [
            'ID', 'accountID', 'levelID',
        ],
    ];

    public function __construct(
        private readonly PDO $target,
        private readonly string $sourcePrefix = ''
    ) {
        if (
            $this->sourcePrefix !== '' &&
            !preg_match('/^mci_[a-f0-9]{16}_$/', $this->sourcePrefix)
        ) {
            throw new \InvalidArgumentException('Invalid source table prefix.');
        }
    }

    public function preflight(PDO $source): array
    {
        $this->requireSourceTables(
            $source,
            ['accounts', 'users', 'levels']
        );
        $this->requireSourceSchema($source);

        return [
            'accounts' => $this->count($source, 'accounts'),
            'users' => $this->count($source, 'users'),
            'levels' => $this->count($source, 'levels'),
            'levelscores' => $this->sourceTableExists($source, 'levelscores')
                ? $this->count($source, 'levelscores')
                : 0,
            'platscores' => $this->sourceTableExists($source, 'platscores')
                ? $this->count($source, 'platscores')
                : 0,
        ];
    }

    public function apply(PDO $source): array
    {
        $this->requireSourceTables(
            $source,
            ['accounts', 'users', 'levels']
        );
        $this->requireSourceSchema($source);

        $this->requireTargetMaps();

        $stats = [
            'accounts_created' => 0,
            'accounts_reused' => 0,
            'profiles_upserted' => 0,
            'levels_created' => 0,
            'levels_updated' => 0,
            'regular_scores_upserted' => 0,
            'platformer_scores_upserted' => 0,
            'comments_imported' => 0,
            'account_comments_imported' => 0,
            'friends_imported' => 0,
            'friend_requests_imported' => 0,
            'blocks_imported' => 0,
            'messages_imported' => 0,
            'songs_imported' => 0,
            'password_resets_required' => 0,
        ];

        $this->importAccounts($source, $stats);
        $this->importLevels($source, $stats);

        if ($this->sourceTableExists($source, 'levelscores')) {
            $this->importRegularScores($source, $stats);
        }

        if ($this->sourceTableExists($source, 'platscores')) {
            $this->importPlatformerScores($source, $stats);
        }

        if ($this->sourceTableExists($source, 'comments')) {
            $this->importComments($source, $stats);
        }

        if ($this->sourceTableExists($source, 'acccomments')) {
            $this->importAccountComments($source, $stats);
        }

        if ($this->sourceTableExists($source, 'friendships')) {
            $this->importFriendships($source, $stats);
        }

        if ($this->sourceTableExists($source, 'friendreqs')) {
            $this->importFriendRequests($source, $stats);
        }

        if ($this->sourceTableExists($source, 'blocks')) {
            $this->importBlocks($source, $stats);
        }

        if ($this->sourceTableExists($source, 'messages')) {
            $this->importMessages($source, $stats);
        }

        if ($this->sourceTableExists($source, 'songs')) {
            $this->importSongs($source, $stats);
        }

        return $stats;
    }

    private function importAccounts(PDO $source, array &$stats): void
    {
        $last = 0;

        $sql = '
            SELECT
                a.accountID,
                a.userName,
                a.password,
                a.gjp2,
                a.email,
                a.isActive,
                COALESCE(u.stars,0) AS stars,
                COALESCE(u.moons,0) AS moons,
                COALESCE(u.diamonds,0) AS diamonds,
                COALESCE(u.coins,0) AS secretCoins,
                COALESCE(u.userCoins,0) AS userCoins,
                COALESCE(u.demons,0) AS demons,
                COALESCE(u.creatorPoints,0) AS creatorPoints,
                COALESCE(u.icon,1) AS icon,
                COALESCE(u.iconType,0) AS iconType,
                COALESCE(u.color1,0) AS color1,
                COALESCE(u.color2,3) AS color2,
                COALESCE(u.accGlow,0) AS glow,
                COALESCE(u.isBanned,0) AS isBanned
            FROM ' . $this->sourceTable('accounts') . ' a
            LEFT JOIN ' . $this->sourceTable('users') . ' u
                ON u.extID = CAST(a.accountID AS CHAR)
            WHERE a.accountID > :last
            ORDER BY a.accountID ASC
            LIMIT 250
        ';

        $q = $source->prepare($sql);

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $sourceId = (int)$row['accountID'];
                $username = $this->username((string)$row['userName']);
                $email = $this->email((string)$row['email'], $sourceId);

                $existing = $this->mappedAccount($sourceId);

                if ($existing === null) {
                    /*
                     * Legacy GDPS databases often contain several accounts
                     * sharing one email address. MuchoCore keeps email unique,
                     * so preserve the first real address and assign later
                     * colliding source accounts deterministic local fallback
                     * addresses instead of aborting the entire migration.
                     */
                    if (
                        $this->emailBelongsToDifferentAccount(
                            $email,
                            $username
                        )
                    ) {
                        $email = $this->fallbackEmail($sourceId);
                    }

                    $existing = $this->existingAccount(
                        $sourceId,
                        $username,
                        $email
                    );

                    if ($existing === null) {
                        $existing = $this->createAccount(
                            $username,
                            $email,
                            $row
                        );
                        $stats['accounts_created']++;
                    } else {
                        $stats['accounts_reused']++;
                    }

                    $this->saveAccountMap(
                        $sourceId,
                        $existing,
                        $username,
                        $this->needsReset($row)
                    );
                }

                if ($this->needsReset($row)) {
                    $stats['password_resets_required']++;
                }

                $this->upsertProfile($existing, $row);
                $stats['profiles_upserted']++;
                $last = $sourceId;
            }
        }
    }

    private function importLevels(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('levels') . '
             WHERE levelID > :last
             ORDER BY levelID ASC
             LIMIT 250'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $sourceId = (int)($row['levelID'] ?? 0);
                $account = $this->levelAccount($row);

                if ($sourceId <= 0 || $account === null) {
                    $last = max($last, $sourceId);
                    continue;
                }

                $mapped = $this->mappedLevel($sourceId);

                if ($mapped === null) {
                    $preferred = $sourceId > 0 &&
                        $this->levelIdFree($sourceId)
                        ? $sourceId
                        : null;

                    if ($preferred !== null) {
                        $this->createLevelWithId(
                            $preferred,
                            $account,
                            $row
                        );
                        $mapped = $preferred;
                    } else {
                        $mapped = $this->createLevel(
                            $account,
                            $row
                        );
                    }

                    $this->saveLevelMap(
                        $sourceId,
                        $mapped,
                        (string)($row['levelName'] ?? '')
                    );
                    $stats['levels_created']++;
                } else {
                    $this->updateLevel($mapped, $account, $row);
                    $stats['levels_updated']++;
                }

                $last = $sourceId;
            }
        }
    }

    private function importRegularScores(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('levelscores') . '
             WHERE scoreID > :last
             ORDER BY scoreID ASC
             LIMIT 250'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $account = $this->mappedAccount((int)($row['accountID'] ?? 0));
                $level = $this->mappedLevel((int)($row['levelID'] ?? 0));

                if ($account !== null && $level !== null) {
                    $dailyId = max(0, (int)($row['dailyID'] ?? 0));
                    $now = max(0, (int)($row['uploadDate'] ?? time()));

                    $insert = $this->target->prepare(
                        'INSERT INTO mucho_level_scores
                            (account_id,level_id,is_daily,daily_id,percent,coins,
                             attempts,clicks,play_time,progresses,created_at,updated_at)
                         VALUES
                            (:account,:level,:daily,:daily_id,:percent,:coins,
                             :attempts,:clicks,:play_time,:progresses,:created,:updated)
                         ON DUPLICATE KEY UPDATE
                            daily_id=IF(VALUES(percent)>percent,VALUES(daily_id),daily_id),
                            coins=IF(VALUES(percent)>=percent,VALUES(coins),coins),
                            attempts=IF(VALUES(percent)>=percent,VALUES(attempts),attempts),
                            clicks=IF(VALUES(percent)>=percent,VALUES(clicks),clicks),
                            play_time=IF(VALUES(percent)>=percent,VALUES(play_time),play_time),
                            progresses=IF(VALUES(percent)>=percent,VALUES(progresses),progresses),
                            updated_at=IF(VALUES(percent)>=percent,VALUES(updated_at),updated_at),
                            percent=GREATEST(percent,VALUES(percent))'
                    );

                    $insert->execute([
                        'account' => $account,
                        'level' => $level,
                        'daily' => $dailyId > 0 ? 1 : 0,
                        'daily_id' => $dailyId,
                        'percent' => max(0, min(100, (int)($row['percent'] ?? 0))),
                        'coins' => max(0, min(3, (int)($row['coins'] ?? 0))),
                        'attempts' => max(0, (int)($row['attempts'] ?? 0)),
                        'clicks' => max(0, (int)($row['clicks'] ?? 0)),
                        'play_time' => max(0, (int)($row['time'] ?? 0)),
                        'progresses' => (string)($row['progresses'] ?? ''),
                        'created' => $now,
                        'updated' => $now,
                    ]);

                    $stats['regular_scores_upserted']++;
                }

                $last = (int)($row['scoreID'] ?? $last);
            }
        }
    }

    private function importPlatformerScores(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('platscores') . '
             WHERE ID > :last
             ORDER BY ID ASC
             LIMIT 250'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $account = $this->mappedAccount((int)($row['accountID'] ?? 0));
                $level = $this->mappedLevel((int)($row['levelID'] ?? 0));

                if ($account !== null && $level !== null) {
                    $now = max(0, (int)($row['timestamp'] ?? time()));
                    $insert = $this->target->prepare(
                        'INSERT INTO mucho_platformer_scores
                            (account_id,level_id,time_ms,points,created_at,updated_at)
                         VALUES
                            (:account,:level,:time,:points,:created,:updated)
                         ON DUPLICATE KEY UPDATE
                            time_ms=IF(
                                VALUES(time_ms)>0 AND
                                (time_ms=0 OR VALUES(time_ms)<time_ms),
                                VALUES(time_ms),
                                time_ms
                            ),
                            points=GREATEST(points,VALUES(points)),
                            updated_at=GREATEST(updated_at,VALUES(updated_at))'
                    );

                    $insert->execute([
                        'account' => $account,
                        'level' => $level,
                        'time' => max(0, (int)($row['time'] ?? 0)),
                        'points' => max(0, (int)($row['points'] ?? 0)),
                        'created' => $now,
                        'updated' => $now,
                    ]);

                    $stats['platformer_scores_upserted']++;
                }

                $last = (int)($row['ID'] ?? $last);
            }
        }
    }

    private function importComments(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('comments') . '
             WHERE commentID > :last
             ORDER BY commentID ASC
             LIMIT 250'
        );

        $insert = $source === $this->target ? null : $this->target->prepare(
            'INSERT INTO comments
             (level_id,account_id,content,percent,likes,is_spam,created_at)
             SELECT :level,:account,:content,:percent,:likes,:spam,
                    FROM_UNIXTIME(:created)
             WHERE NOT EXISTS (
                 SELECT 1 FROM comments c
                 WHERE c.level_id=:level2
                   AND c.account_id=:account2
                   AND c.content=:content2
                   AND c.created_at=FROM_UNIXTIME(:created2)
             )'
        );

        if ($insert === null) {
            return;
        }

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $account = $this->mappedAccount((int)($row['userID'] ?? 0));
                $level = $this->mappedLevel((int)($row['levelID'] ?? 0));

                if ($account === null || $level === null) {
                    $last = max($last, (int)($row['commentID'] ?? 0));
                    continue;
                }

                $created = max(0, (int)($row['timestamp'] ?? 0));
                $content = (string)($row['comment'] ?? '');

                $insert->execute([
                    'level' => $level,
                    'account' => $account,
                    'content' => $content,
                    'percent' => max(0, min(100, (int)($row['percent'] ?? 0))),
                    'likes' => (int)($row['likes'] ?? 0),
                    'spam' => (int)($row['isSpam'] ?? 0) !== 0 ? 1 : 0,
                    'created' => $created,
                    'level2' => $level,
                    'account2' => $account,
                    'content2' => $content,
                    'created2' => $created,
                ]);

                if ($insert->rowCount() > 0) {
                    $stats['comments_imported']++;
                }

                $last = (int)($row['commentID'] ?? 0);
            }
        }
    }

    private function importAccountComments(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('acccomments') . '
             WHERE commentID > :last
             ORDER BY commentID ASC
             LIMIT 250'
        );

        $insert = $this->target->prepare(
            'INSERT INTO account_comments
             (account_id,content,likes,is_spam,created_at)
             SELECT :account,:content,:likes,:spam,FROM_UNIXTIME(:created)
             WHERE NOT EXISTS (
                 SELECT 1 FROM account_comments c
                 WHERE c.account_id=:account2
                   AND c.content=:content2
                   AND c.created_at=FROM_UNIXTIME(:created2)
             )'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $account = $this->mappedAccount((int)($row['userID'] ?? 0));

                if ($account !== null) {
                    $content = (string)($row['comment'] ?? '');
                    $created = max(0, (int)($row['timestamp'] ?? 0));

                    $insert->execute([
                        'account' => $account,
                        'content' => $content,
                        'likes' => (int)($row['likes'] ?? 0),
                        'spam' => (int)($row['isSpam'] ?? 0) !== 0 ? 1 : 0,
                        'created' => $created,
                        'account2' => $account,
                        'content2' => $content,
                        'created2' => $created,
                    ]);

                    if ($insert->rowCount() > 0) {
                        $stats['account_comments_imported']++;
                    }
                }

                $last = (int)($row['commentID'] ?? 0);
            }
        }
    }

    private function importFriendships(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('friendships') . '
             WHERE ID > :last
             ORDER BY ID ASC
             LIMIT 250'
        );

        $insert = $this->target->prepare(
            'INSERT IGNORE INTO friends
             (account_id,friend_account_id,is_new)
             VALUES (:a,:b,:new1),(:b2,:a2,:new2)'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $a = $this->mappedAccount((int)($row['person1'] ?? 0));
                $b = $this->mappedAccount((int)($row['person2'] ?? 0));

                if ($a !== null && $b !== null && $a !== $b) {
                    $insert->execute([
                        'a' => $a,
                        'b' => $b,
                        'new1' => (int)($row['isNew1'] ?? 0) !== 0 ? 1 : 0,
                        'b2' => $b,
                        'a2' => $a,
                        'new2' => (int)($row['isNew2'] ?? 0) !== 0 ? 1 : 0,
                    ]);

                    $stats['friends_imported'] += $insert->rowCount();
                }

                $last = (int)($row['ID'] ?? 0);
            }
        }
    }

    private function importFriendRequests(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('friendreqs') . '
             WHERE ID > :last
             ORDER BY ID ASC
             LIMIT 250'
        );

        $insert = $this->target->prepare(
            'INSERT INTO friend_requests
             (account_id,to_account_id,comment,is_read,created_at)
             SELECT :account,:to,:comment,:read,FROM_UNIXTIME(:created)
             WHERE NOT EXISTS (
                 SELECT 1 FROM friend_requests r
                 WHERE r.account_id=:account2
                   AND r.to_account_id=:to2
                   AND r.comment=:comment2
                   AND r.created_at=FROM_UNIXTIME(:created2)
             )'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $from = $this->mappedAccount((int)($row['accountID'] ?? 0));
                $to = $this->mappedAccount((int)($row['toAccountID'] ?? 0));

                if ($from !== null && $to !== null && $from !== $to) {
                    $comment = (string)($row['comment'] ?? '');
                    $created = max(0, (int)($row['uploadDate'] ?? 0));

                    $insert->execute([
                        'account' => $from,
                        'to' => $to,
                        'comment' => $comment,
                        'read' => (int)($row['isNew'] ?? 0) !== 0 ? 0 : 1,
                        'created' => $created,
                        'account2' => $from,
                        'to2' => $to,
                        'comment2' => $comment,
                        'created2' => $created,
                    ]);

                    if ($insert->rowCount() > 0) {
                        $stats['friend_requests_imported']++;
                    }
                }

                $last = (int)($row['ID'] ?? 0);
            }
        }
    }

    private function importBlocks(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('blocks') . '
             WHERE ID > :last
             ORDER BY ID ASC
             LIMIT 250'
        );

        $insert = $this->target->prepare(
            'INSERT IGNORE INTO blocks
             (account_id,blocked_account_id)
             VALUES (:a,:b)'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $a = $this->mappedAccount((int)($row['person1'] ?? 0));
                $b = $this->mappedAccount((int)($row['person2'] ?? 0));

                if ($a !== null && $b !== null && $a !== $b) {
                    $insert->execute(['a' => $a, 'b' => $b]);
                    $stats['blocks_imported'] += $insert->rowCount();
                }

                $last = (int)($row['ID'] ?? 0);
            }
        }
    }

    private function importMessages(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('messages') . '
             WHERE messageID > :last
             ORDER BY messageID ASC
             LIMIT 250'
        );

        $insert = $this->target->prepare(
            'INSERT INTO messages
             (account_id,to_account_id,subject,body,is_read,is_sender_deleted,is_receiver_deleted,created_at)
             SELECT :account,:to,:subject,:body,:read,0,0,FROM_UNIXTIME(:created)
             WHERE NOT EXISTS (
                 SELECT 1 FROM messages m
                 WHERE m.account_id=:account2
                   AND m.to_account_id=:to2
                   AND m.subject=:subject2
                   AND m.body=:body2
                   AND m.created_at=FROM_UNIXTIME(:created2)
             )'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $from = $this->mappedAccount((int)($row['userID'] ?? 0));
                $to = $this->mappedAccount((int)($row['toAccountID'] ?? 0));

                if ($from !== null && $to !== null && $from !== $to) {
                    $subject = (string)($row['subject'] ?? '');
                    $body = (string)($row['body'] ?? '');
                    $created = max(0, (int)($row['timestamp'] ?? 0));

                    $insert->execute([
                        'account' => $from,
                        'to' => $to,
                        'subject' => $subject,
                        'body' => $body,
                        'read' => (int)($row['isNew'] ?? 0) !== 0 ? 0 : 1,
                        'created' => $created,
                        'account2' => $from,
                        'to2' => $to,
                        'subject2' => $subject,
                        'body2' => $body,
                        'created2' => $created,
                    ]);

                    if ($insert->rowCount() > 0) {
                        $stats['messages_imported']++;
                    }
                }

                $last = (int)($row['messageID'] ?? 0);
            }
        }
    }

    private function importSongs(PDO $source, array &$stats): void
    {
        $last = 0;
        $q = $source->prepare(
            'SELECT *
             FROM ' . $this->sourceTable('songs') . '
             WHERE ID > :last
             ORDER BY ID ASC
             LIMIT 250'
        );

        $upsert = $this->target->prepare(
            'INSERT INTO songs
             (id,name,author_id,author_name,size,download_url,youtube_video_id,youtube_channel_id,is_verified)
             VALUES (:id,:name,:author,:authorName,:size,:download,:youtubeVideo,:youtubeChannel,:verified)
             ON DUPLICATE KEY UPDATE
                name=VALUES(name),
                author_id=VALUES(author_id),
                author_name=VALUES(author_name),
                size=VALUES(size),
                download_url=VALUES(download_url),
                youtube_video_id=VALUES(youtube_video_id),
                youtube_channel_id=VALUES(youtube_channel_id),
                is_verified=VALUES(is_verified)'
        );

        while (true) {
            $q->execute(['last' => $last]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $id = (int)($row['ID'] ?? 0);
                if ($id <= 0) {
                    $last = max($last, $id);
                    continue;
                }

                $upsert->execute([
                    'id' => $id,
                    'name' => (string)($row['name'] ?? ''),
                    'author' => (int)($row['authorID'] ?? 0),
                    'authorName' => (string)($row['authorName'] ?? ''),
                    'size' => (float)($row['size'] ?? 0),
                    'download' => urldecode((string)($row['download'] ?? '')),
                    'youtubeVideo' => '',
                    'youtubeChannel' => '',
                    'verified' => (int)($row['isDisabled'] ?? 0) !== 0 ? 0 : 1,
                ]);

                $stats['songs_imported']++;
                $last = $id;
            }
        }
    }

    private function createAccount(
        string $username,
        string $email,
        array $row
    ): int {
        $q = $this->target->prepare(
            'INSERT INTO accounts
                (username,email,password_hash,gjp2_hash,is_active,is_banned)
             VALUES
                (:username,:email,:password,:gjp2,:active,:banned)'
        );

        $q->execute([
            'username' => $username,
            'email' => $email,
            'password' => $this->passwordHash($row),
            'gjp2' => $this->gjp2Hash($row),
            'active' => (int)($row['isActive'] ?? 1) === 1 ? 1 : 0,
            'banned' => (int)($row['isBanned'] ?? 0) === 1 ? 1 : 0,
        ]);

        return (int)$this->target->lastInsertId();
    }

    private function upsertProfile(int $accountId, array $row): void
    {
        $q = $this->target->prepare(
            'INSERT INTO profiles
                (account_id,stars,moons,diamonds,secret_coins,user_coins,demons,
                 creator_points,icon_id,icon_type,color1,color2,glow)
             VALUES
                (:account,:stars,:moons,:diamonds,:secret,:user_coins,:demons,
                 :creator,:icon,:icon_type,:color1,:color2,:glow)
             ON DUPLICATE KEY UPDATE
                stars=VALUES(stars),
                moons=VALUES(moons),
                diamonds=VALUES(diamonds),
                secret_coins=VALUES(secret_coins),
                user_coins=VALUES(user_coins),
                demons=VALUES(demons),
                creator_points=VALUES(creator_points),
                icon_id=VALUES(icon_id),
                icon_type=VALUES(icon_type),
                color1=VALUES(color1),
                color2=VALUES(color2),
                glow=VALUES(glow)'
        );

        $q->execute([
            'account' => $accountId,
            'stars' => max(0, (int)($row['stars'] ?? 0)),
            'moons' => max(0, (int)($row['moons'] ?? 0)),
            'diamonds' => max(0, (int)($row['diamonds'] ?? 0)),
            'secret' => max(0, (int)($row['secretCoins'] ?? 0)),
            'user_coins' => max(0, (int)($row['userCoins'] ?? 0)),
            'demons' => max(0, (int)($row['demons'] ?? 0)),
            'creator' => max(0, (int)round((float)($row['creatorPoints'] ?? 0))),
            'icon' => max(1, (int)($row['icon'] ?? 1)),
            'icon_type' => max(0, (int)($row['iconType'] ?? 0)),
            'color1' => max(0, min(65535, (int)($row['color1'] ?? 0))),
            'color2' => max(0, min(65535, (int)($row['color2'] ?? 3))),
            'glow' => max(0, min(1, (int)($row['glow'] ?? 0))),
        ]);
    }

    private function emailBelongsToDifferentAccount(
        string $email,
        string $username
    ): bool {
        $q = $this->target->prepare(
            'SELECT username
             FROM accounts
             WHERE email=:email
             ORDER BY account_id ASC
             LIMIT 1'
        );
        $q->execute(['email' => $email]);

        $owner = $q->fetchColumn();

        return $owner !== false && (string)$owner !== $username;
    }

    private function fallbackEmail(int $sourceId): string
    {
        return 'cvolton.' . $sourceId . '@local.invalid';
    }

    private function existingAccount(
        int $sourceId,
        string $username,
        string $email
    ): ?int {
        $q = $this->target->prepare(
            'SELECT account_id, username, email
             FROM accounts
             WHERE username=:username OR email=:email
             ORDER BY account_id ASC'
        );
        $q->execute([
            'username' => $username,
            'email' => $email,
        ]);

        $byUsername = null;
        $byEmail = null;

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['account_id'];
            if ((string)$row['username'] === $username) {
                $byUsername = $id;
            }
            if ((string)$row['email'] === $email) {
                $byEmail = $id;
            }
        }

        if ($byUsername !== null && $byEmail !== null) {
            if ($byUsername !== $byEmail) {
                throw new RuntimeException(
                    sprintf(
                        'Source account %d matches two different target accounts ' .
                        'by username and email. Resolve the conflict before migration.',
                        $sourceId
                    )
                );
            }

            return $byUsername;
        }

        if ($byUsername !== null || $byEmail !== null) {
            throw new RuntimeException(
                sprintf(
                    'Source account %d conflicts with an existing target account ' .
                    '(username/email). Refusing to merge it implicitly.',
                    $sourceId
                )
            );
        }

        return null;
    }

    private function levelAccount(array $row): ?int
    {
        $source = (int)($row['extID'] ?? 0);
        $mapped = $this->mappedAccount($source);

        if ($mapped !== null) {
            return $mapped;
        }

        return null;
    }

    private function createLevel(int $accountId, array $row): int
    {
        $q = $this->target->prepare(
            'INSERT INTO levels
                (account_id,name,description,level_data,level_version,game_version,
                 binary_version,length,audio_track,difficulty,demon,demon_difficulty,
                 auto_level,featured,epic,object_count,original_level_id,two_player,
                 coins,coins_verified,requested_stars,ldm,song_id,song_ids,sfx_ids,
                 wt,wt2,ts,downloads,likes,stars,is_unlisted,is_deleted)
             VALUES
                (:account,:name,:description,:data,:version,:game,:binary,:length,
                 :audio,:difficulty,:demon,:demon_diff,:auto,:featured,:epic,:objects,
                 :original,:two_player,:coins,:coins_verified,:requested,:ldm,:song,
                 :song_ids,:sfx_ids,:wt,:wt2,:ts,:downloads,:likes,:stars,:unlisted,:deleted)'
        );

        $q->execute($this->levelParams($accountId, $row));
        return (int)$this->target->lastInsertId();
    }

    private function createLevelWithId(
        int $levelId,
        int $accountId,
        array $row
    ): void {
        $params = $this->levelParams($accountId, $row);
        $params['level_id'] = $levelId;

        $q = $this->target->prepare(
            'INSERT INTO levels
                (level_id,account_id,name,description,level_data,level_version,game_version,
                 binary_version,length,audio_track,difficulty,demon,demon_difficulty,
                 auto_level,featured,epic,object_count,original_level_id,two_player,
                 coins,coins_verified,requested_stars,ldm,song_id,song_ids,sfx_ids,
                 wt,wt2,ts,downloads,likes,stars,is_unlisted,is_deleted)
             VALUES
                (:level_id,:account,:name,:description,:data,:version,:game,:binary,:length,
                 :audio,:difficulty,:demon,:demon_diff,:auto,:featured,:epic,:objects,
                 :original,:two_player,:coins,:coins_verified,:requested,:ldm,:song,
                 :song_ids,:sfx_ids,:wt,:wt2,:ts,:downloads,:likes,:stars,:unlisted,:deleted)'
        );

        $q->execute($params);
    }

    private function updateLevel(
        int $levelId,
        int $accountId,
        array $row
    ): void {
        $params = $this->levelParams($accountId, $row);
        $params['level_id'] = $levelId;

        $q = $this->target->prepare(
            'UPDATE levels
             SET account_id=:account,name=:name,description=:description,level_data=:data,
                 level_version=:version,game_version=:game,binary_version=:binary,
                 length=:length,audio_track=:audio,difficulty=:difficulty,demon=:demon,
                 demon_difficulty=:demon_diff,auto_level=:auto,featured=:featured,epic=:epic,
                 object_count=:objects,original_level_id=:original,two_player=:two_player,
                 coins=:coins,coins_verified=:coins_verified,requested_stars=:requested,
                 ldm=:ldm,song_id=:song,song_ids=:song_ids,sfx_ids=:sfx_ids,
                 wt=:wt,wt2=:wt2,ts=:ts,downloads=:downloads,likes=:likes,stars=:stars,
                 is_unlisted=:unlisted,is_deleted=:deleted
             WHERE level_id=:level_id'
        );

        $q->execute($params);
    }

    private function levelParams(int $accountId, array $row): array
    {
        $starDifficulty = max(0, (int)($row['starDifficulty'] ?? 0));
        $auto = (int)($row['starAuto'] ?? 0) === 1;
        $demon = (int)($row['starDemon'] ?? 0) === 1;

        $difficulty = $auto
            ? 1
            : max(0, min(6, intdiv($starDifficulty, 10)));

        $epic = max(0, min(4, (int)($row['starEpic'] ?? 0)));

        return [
            'account' => $accountId,
            'name' => mb_substr(
                trim((string)($row['levelName'] ?? 'Unnamed level')),
                0,
                64
            ),
            'description' => (string)($row['levelDesc'] ?? ''),
            'data' => (string)($row['levelString'] ?? ''),
            'version' => max(1, (int)($row['levelVersion'] ?? 1)),
            'game' => max(0, (int)($row['gameVersion'] ?? 22)),
            'binary' => max(0, (int)($row['binaryVersion'] ?? 0)),
            'length' => max(0, min(65535, (int)($row['levelLength'] ?? 0))),
            'audio' => max(0, min(65535, (int)($row['audioTrack'] ?? 0))),
            'difficulty' => $difficulty,
            'demon' => $demon ? 1 : 0,
            'demon_diff' => $demon
                ? max(0, min(5, (int)($row['starDemonDiff'] ?? 0)))
                : 0,
            'auto' => $auto ? 1 : 0,
            'featured' => $epic > 0
                ? 0
                : ((int)($row['starFeatured'] ?? 0) > 0 ? 1 : 0),
            'epic' => $epic,
            'objects' => max(0, (int)($row['objects'] ?? 0)),
            'original' => max(0, (int)($row['original'] ?? 0)),
            'two_player' => max(0, min(1, (int)($row['twoPlayer'] ?? 0))),
            'coins' => max(0, min(65535, (int)($row['coins'] ?? 0))),
            'coins_verified' => 0,
            'requested' => max(0, min(65535, (int)($row['requestedStars'] ?? 0))),
            'ldm' => max(0, min(1, (int)($row['isLDM'] ?? 0))),
            'song' => max(0, (int)($row['songID'] ?? 0)),
            'song_ids' => (string)($row['songIDs'] ?? ''),
            'sfx_ids' => (string)($row['sfxIDs'] ?? ''),
            'wt' => (int)($row['wt'] ?? 0),
            'wt2' => (int)($row['wt2'] ?? 0),
            'ts' => (int)($row['ts'] ?? 0),
            'downloads' => max(0, (int)($row['downloads'] ?? 0)),
            'likes' => (int)($row['likes'] ?? 0),
            'stars' => max(0, min(65535, (int)($row['starStars'] ?? 0))),
            'unlisted' => max(0, min(1, (int)($row['unlisted'] ?? 0))),
            'deleted' => max(0, min(1, (int)($row['isDeleted'] ?? 0))),
        ];
    }

    private function passwordHash(array $row): string
    {
        $hash = (string)($row['password'] ?? '');
        $info = password_get_info($hash);

        return $hash !== '' && (int)($info['algo'] ?? 0) !== 0
            ? $hash
            : password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    private function needsReset(array $row): bool
    {
        $hash = (string)($row['password'] ?? '');
        return $hash === '' ||
            (int)(password_get_info($hash)['algo'] ?? 0) === 0;
    }

    private function gjp2Hash(array $row): ?string
    {
        $gjp2 = trim((string)($row['gjp2'] ?? ''));

        return preg_match('/^[a-f0-9]{40}$/i', $gjp2) === 1
            ? password_hash($gjp2, PASSWORD_DEFAULT)
            : null;
    }

    private function username(string $value): string
    {
        $value = preg_replace(
            '/[^A-Za-z0-9_.-]/',
            '_',
            trim($value)
        ) ?? '';

        $value = substr($value, 0, 20);
        return $value !== '' ? $value : 'Player';
    }

    private function email(string $value, int $sourceId): string
    {
        return filter_var(
            trim($value),
            FILTER_VALIDATE_EMAIL
        )
            ? trim($value)
            : 'cvolton.' . $sourceId . '@local.invalid';
    }

    private function mappedAccount(int $sourceId): ?int
    {
        if ($sourceId <= 0) {
            return null;
        }

        $q = $this->target->prepare(
            'SELECT target_id
             FROM mucho_cvolton_account_map
             WHERE source_id=:source
             LIMIT 1'
        );
        $q->execute(['source' => $sourceId]);

        $id = $q->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function mappedLevel(int $sourceId): ?int
    {
        if ($sourceId <= 0) {
            return null;
        }

        $q = $this->target->prepare(
            'SELECT target_id
             FROM mucho_cvolton_level_map
             WHERE source_id=:source
             LIMIT 1'
        );
        $q->execute(['source' => $sourceId]);

        $id = $q->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function saveAccountMap(
        int $sourceId,
        int $targetId,
        string $username,
        bool $needsReset
    ): void {
        $q = $this->target->prepare(
            'INSERT INTO mucho_cvolton_account_map
                (source_id,target_id,source_username,needs_password_reset)
             VALUES (:source,:target,:username,:needs)
             ON DUPLICATE KEY UPDATE
                target_id=VALUES(target_id),
                source_username=VALUES(source_username),
                needs_password_reset=VALUES(needs_password_reset),
                updated_at=CURRENT_TIMESTAMP'
        );

        $q->execute([
            'source' => $sourceId,
            'target' => $targetId,
            'username' => $username,
            'needs' => $needsReset ? 1 : 0,
        ]);
    }

    private function saveLevelMap(
        int $sourceId,
        int $targetId,
        string $name
    ): void {
        $q = $this->target->prepare(
            'INSERT INTO mucho_cvolton_level_map
                (source_id,target_id,source_name)
             VALUES (:source,:target,:name)
             ON DUPLICATE KEY UPDATE
                target_id=VALUES(target_id),
                source_name=VALUES(source_name),
                updated_at=CURRENT_TIMESTAMP'
        );

        $q->execute([
            'source' => $sourceId,
            'target' => $targetId,
            'name' => mb_substr($name, 0, 255),
        ]);
    }

    private function requireTargetMaps(): void
    {
        foreach ([
            'mucho_cvolton_account_map',
            'mucho_cvolton_level_map',
        ] as $table) {
            if (!$this->targetTableExists($table)) {
                throw new RuntimeException(
                    'Missing migration table ' . $table .
                    '. Run php bin/migrate.php migrate first.'
                );
            }
        }
    }

    private function targetTableExists(string $table): bool
    {
        $q = $this->target->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $q->execute(['table' => $table]);
        return (int)$q->fetchColumn() === 1;
    }

    private function requireSourceTables(PDO $source, array $tables): void
    {
        $missing = [];

        foreach ($tables as $table) {
            if (!$this->sourceTableExists($source, $table)) {
                $missing[] = $table;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'Source database is missing: ' . implode(', ', $missing)
            );
        }
    }

    private function sourceTableExists(PDO $source, string $table): bool
    {
        $physical = $this->physicalSourceTable($table);

        $q = $source->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=:table
             LIMIT 1'
        );
        $q->execute(['table' => $physical]);

        return $q->fetchColumn() !== false;
    }

    private function sourceTable(string $table): string
    {
        return chr(96) .
            str_replace(
                chr(96),
                chr(96) . chr(96),
                $this->physicalSourceTable($table)
            ) .
            chr(96);
    }

    private function physicalSourceTable(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $table)) {
            throw new RuntimeException('Invalid migration source table.');
        }

        return $this->sourcePrefix . $table;
    }

    private function requireSourceSchema(PDO $source): void
    {
        foreach (['accounts', 'users', 'levels'] as $table) {
            $this->requireSourceColumns(
                $source,
                $table,
                self::REQUIRED_COLUMNS[$table]
            );
        }

        foreach (['levelscores', 'platscores'] as $table) {
            if ($this->sourceTableExists($source, $table)) {
                $this->requireSourceColumns(
                    $source,
                    $table,
                    self::REQUIRED_COLUMNS[$table]
                );
            }
        }
    }

    /**
     * @param list<string> $required
     */
    private function requireSourceColumns(
        PDO $source,
        string $table,
        array $required
    ): void {
        $columns = [];
        $q = $source->query('SHOW COLUMNS FROM ' . $this->sourceTable($table));

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $columns[strtolower($field)] = $field;
            }
        }

        $missing = [];
        foreach ($required as $column) {
            if (!isset($columns[strtolower($column)])) {
                $missing[] = $column;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Source table ' . $table .
                ' is missing required columns: ' . implode(', ', $missing)
            );
        }
    }

    private function count(PDO $db, string $table): int
    {
        return (int)$db->query(
            'SELECT COUNT(*) FROM ' . $this->sourceTable($table)
        )->fetchColumn();
    }

    private function levelIdFree(int $id): bool
    {
        $q = $this->target->prepare(
            'SELECT COUNT(*) FROM levels WHERE level_id=:id'
        );
        $q->execute(['id' => $id]);
        return (int)$q->fetchColumn() === 0;
    }
}
