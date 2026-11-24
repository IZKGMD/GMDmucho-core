<?php
declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

final class CvoltonContentImporter
{
    private const DATASETS = [
        'comments' => ['commentID', 'userID', 'comment', 'levelID', 'timestamp'],
        'acccomments' => ['commentID', 'userID', 'comment', 'timestamp'],
        'lists' => ['listID', 'accountID', 'listName', 'listlevels'],
        'gauntlets' => ['ID', 'level1', 'level2', 'level3', 'level4', 'level5'],
        'mappacks' => ['ID', 'name', 'levels', 'stars', 'coins', 'difficulty'],
        'dailyfeatures' => ['feaID', 'levelID', 'timestamp', 'type'],
    ];

    public function __construct(private readonly PDO $target)
    {
    }

    private function plan(PDO $source): array
    {
        $columns = $source->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
                ('users','comments','acccomments','lists','gauntlets','mappacks','dailyfeatures')");
        $available = [];
        foreach ($columns->fetchAll(PDO::FETCH_NUM) as [$table, $column]) {
            $available[$table][strtolower($column)] = true;
        }
        $plan = [];
        foreach (self::DATASETS as $table => $required) {
            if (!isset($available[$table])) {
                continue;
            }
            if (in_array($table, ['comments', 'acccomments'], true)
                && !isset($available['users']['userid'])) {
                $plan[$table] = false;
                continue;
            }
            $plan[$table] = array_diff(array_map('strtolower', $required), array_keys($available[$table])) === [];
        }
        return $plan;
    }

    public function preflight(PDO $source): array
    {
        $counts = [];
        foreach ($this->plan($source) as $table => $compatible) {
            $counts[$table . ($compatible ? '' : '_incompatible')] =
                (int)$source->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        return $counts;
    }

    public function apply(PDO $source): array
    {
        $stats = [];
        $mapped = $this->target->prepare(
            'SELECT target_id FROM mucho_cvolton_content_map WHERE dataset=? AND source_id=?'
        );
        $remember = $this->target->prepare(
            'INSERT INTO mucho_cvolton_content_map (dataset,source_id,target_id) VALUES (?,?,?)'
        );
        foreach ($this->plan($source) as $table => $compatible) {
            $stats[$table . '_imported'] = 0;
            $stats[$table . '_skipped'] = 0;
            $stats[$table . '_already_imported'] = 0;
            if (!$compatible) {
                $stats[$table . '_skipped'] = (int)$source->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
                continue;
            }
            $id = self::DATASETS[$table][0];
            $comments = in_array($table, ['comments', 'acccomments'], true);
            $columns = $comments ? 'x.*, u.extID AS source_account' : 'x.*';
            $join = $comments ? 'LEFT JOIN users u ON u.userID=x.userID' : '';
            $read = $source->prepare("SELECT {$columns} FROM {$table} x {$join}
                WHERE x.{$id}>? ORDER BY x.{$id} LIMIT 250");
            $last = 0;
            do {
                $read->execute([$last]);
                $rows = $read->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $row = array_change_key_case($row);
                    $last = (int)$row[strtolower($id)];
                    $mapped->execute([$table, $last]);
                    if ($mapped->fetchColumn() !== false) {
                        $stats[$table . '_already_imported']++;
                        continue;
                    }
                    $targetId = $this->insertRow($table, $row, $last);
                    if ($targetId === null) {
                        $stats[$table . '_skipped']++;
                        continue;
                    }
                    $remember->execute([$table, $last, $targetId]);
                    $stats[$table . '_imported']++;
                }
            } while (count($rows) === 250);
        }
        return $stats;
    }

    private function mappedAccount(mixed $sourceId): ?int
    {
        if (!ctype_digit((string)$sourceId)) {
            return null;
        }
        $query = $this->target->prepare('SELECT m.target_id FROM mucho_cvolton_account_map m
            JOIN accounts a ON a.account_id=m.target_id WHERE m.source_id=?');
        $query->execute([(int)$sourceId]);
        return (int)$query->fetchColumn() ?: null;
    }

    private function mappedLevels(array $sourceIds, bool $public): ?array
    {
        if (!$sourceIds || count($sourceIds) > 500) {
            return null;
        }
        $ids = array_map('intval', $sourceIds);
        if (min($ids) <= 0 || count(array_unique($ids)) !== count($ids)) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $visibility = $public ? ' AND l.is_unlisted=0 AND l.unlisted2=0' : '';
        $query = $this->target->prepare("SELECT m.source_id,m.target_id FROM mucho_cvolton_level_map m
            JOIN levels l ON l.level_id=m.target_id WHERE m.source_id IN ({$marks}) AND l.is_deleted=0{$visibility}");
        $query->execute($ids);
        $map = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($map) !== count($ids)) {
            return null;
        }
        return array_map(static fn(int $id): int => (int)$map[$id], $ids);
    }

    private static function idList(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        return array_filter($parts, 'ctype_digit') === $parts ? $parts : [];
    }

    private function insertRow(string $table, array $row, int $sourceId): ?int
    {
        if ($table === 'comments' || $table === 'acccomments') {
            $account = $this->mappedAccount($row['source_account'] ?? null);
            $text = base64_decode(strtr((string)$row['comment'], '-_', '+/'), true);
            $timestamp = (int)$row['timestamp'];
            if ($account === null || !is_string($text) || trim($text) === ''
                || !mb_check_encoding($text, 'UTF-8') || $timestamp <= 0 || $timestamp > 2147483647) {
                return null;
            }
            $values = [$account, mb_substr(str_replace("\0", '', $text), 0, 4096), (int)($row['likes'] ?? 0),
                (int)!empty($row['isspam']), $timestamp];
            if ($table === 'acccomments') {
                return $this->insert('INSERT INTO account_comments (account_id,content,likes,is_spam,created_at)
                    VALUES (?,?,?,?,FROM_UNIXTIME(?))', $values);
            }
            $levels = $this->mappedLevels([$row['levelid']], false);
            if ($levels === null) {
                return null;
            }
            return $this->insert('INSERT INTO comments (account_id,content,likes,is_spam,created_at,level_id,percent)
                VALUES (?,?,?,?,FROM_UNIXTIME(?),?,?)', array_merge($values, [$levels[0], max(0,min(100,(int)($row['percent'] ?? 0)))]));
        }
        if ($table === 'lists') {
            $account = $this->mappedAccount($row['accountid']);
            $unlisted = (int)!empty($row['unlisted']);
            $levels = $this->mappedLevels(self::idList((string)$row['listlevels']), !$unlisted);
            if ($account === null || $levels === null) {
                return null;
            }
            return $this->insert('INSERT INTO mucho_level_lists (account_id,list_name,list_desc,list_version,level_ids,
                unlisted,downloads,likes,featured,difficulty,stars,count_for_reward,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $account, mb_substr((string)$row['listname'],0,64), mb_substr((string)($row['listdesc'] ?? ''),0,500),
                    max(1,(int)($row['listversion'] ?? 1)), implode(',', $levels), $unlisted,
                    max(0,(int)($row['downloads'] ?? 0)), (int)($row['likes'] ?? 0), (int)($row['starfeatured'] ?? 0),
                    (int)($row['stardifficulty'] ?? 0), (int)($row['starstars'] ?? 0), (int)($row['countforreward'] ?? 0),
                    (int)($row['uploaddate'] ?? 0), (int)($row['updatedate'] ?? 0),
                ]);
        }
        if ($table === 'gauntlets') {
            $levels = $this->mappedLevels([$row['level1'],$row['level2'],$row['level3'],$row['level4'],$row['level5']], true);
            return $levels === null ? null : $this->insert('INSERT INTO mucho_gauntlets
                (name,sort_order,level1,level2,level3,level4,level5) VALUES (?,?,?,?,?,?,?)',
                array_merge(['Gauntlet ' . $sourceId, $sourceId], $levels));
        }
        if ($table === 'mappacks') {
            $levels = $this->mappedLevels(self::idList((string)$row['levels']), true);
            return $levels === null ? null : $this->insert('INSERT INTO mucho_map_packs
                (name,levels,stars,coins,difficulty,sort_order) VALUES (?,?,?,?,?,?)',
                [mb_substr((string)$row['name'],0,64), implode(',', $levels), max(0,min(65535,(int)$row['stars'])),
                    max(0,min(65535,(int)$row['coins'])), max(0,min(10,(int)$row['difficulty'])), $sourceId]);
        }

        $levels = $this->mappedLevels([$row['levelid']], true);
        $type = (int)$row['type'];
        $kind = [0 => 'daily', 1 => 'weekly', 2 => 'event'][$type] ?? null;
        $start = (int)$row['timestamp'];
        if ($levels === null || $kind === null || $start <= 0 || $start > 2147483647) {
            return null;
        }
        $date = new \DateTimeImmutable('@' . $start);
        $end = $date->modify($type === 1 ? 'next monday' : 'tomorrow')->setTime(0, 0);
        return $this->insert('INSERT INTO mucho_daily_rotation (kind,level_id,starts_at,ends_at) VALUES (?,?,?,?)',
            [$kind, $levels[0], $date->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
    }

    private function insert(string $sql, array $values): int
    {
        $this->target->prepare($sql)->execute($values);
        return (int)$this->target->lastInsertId();
    }
}
