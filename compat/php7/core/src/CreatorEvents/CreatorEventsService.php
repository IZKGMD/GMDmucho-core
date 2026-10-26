<?php

declare (strict_types=1);
namespace MuchoCore\CreatorEvents;

use PDO;
use RuntimeException;
use Throwable;
final class CreatorEventsService
{
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    public function installed(): bool
    {
        try {
            return $this->db->query("SELECT enabled FROM mucho_creator_plugin WHERE id=1")->fetchColumn() !== false;
        } catch (Throwable $__mcCaught) {
            return false;
        }
    }
    public function enabled(): bool
    {
        try {
            return (int) $this->db->query('SELECT enabled FROM mucho_creator_plugin WHERE id=1')->fetchColumn() === 1;
        } catch (Throwable $__mcCaught) {
            return false;
        }
    }
    public function install()
    {
        foreach (['CREATE TABLE IF NOT EXISTS mucho_creator_plugin (id INT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 1)', "CREATE TABLE IF NOT EXISTS mucho_creator_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(100) NOT NULL,rules TEXT NOT NULL,deadline DATETIME NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'draft',min_judges INT NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)", 'CREATE TABLE IF NOT EXISTS mucho_creator_judges (event_id BIGINT UNSIGNED NOT NULL,account_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(event_id,account_id))', 'CREATE TABLE IF NOT EXISTS mucho_creator_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id BIGINT UNSIGNED NOT NULL,account_id BIGINT UNSIGNED NOT NULL,level_id BIGINT UNSIGNED NOT NULL,level_name VARCHAR(255) NOT NULL,content_hash CHAR(64) NOT NULL,submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,result_score DECIMAL(8,4) NULL,result_rank INT NULL,disqualified TINYINT NOT NULL DEFAULT 0,disqualification_note VARCHAR(1000) NOT NULL DEFAULT \'\',UNIQUE KEY creator_author(event_id,account_id),UNIQUE KEY creator_level(event_id,level_id))', 'CREATE TABLE IF NOT EXISTS mucho_creator_scores (entry_id BIGINT UNSIGNED NOT NULL,judge_id BIGINT UNSIGNED NOT NULL,design INT NOT NULL,gameplay INT NOT NULL,originality INT NOT NULL,note VARCHAR(1000) NOT NULL,PRIMARY KEY(entry_id,judge_id))'] as $sql) {
            $this->db->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        $this->db->exec('INSERT IGNORE INTO mucho_creator_plugin(id,enabled) VALUES(1,1)');
    }
    public function toggle(bool $enabled)
    {
        $this->db->prepare('UPDATE mucho_creator_plugin SET enabled=? WHERE id=1')->execute([$enabled ? 1 : 0]);
    }
    private function active()
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Creator Events is disabled.');
        }
    }
    public function events(bool $admin = false): array
    {
        return $this->db->query('SELECT * FROM mucho_creator_events' . ($admin ? '' : " WHERE state IN ('open','judging','published')") . ' ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function event(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM mucho_creator_events WHERE id=?');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Event not found.');
        }
        return $row;
    }
    public function entries(int $id): array
    {
        $s = $this->db->prepare('SELECT e.*,a.username,(SELECT COUNT(*) FROM mucho_creator_scores s WHERE s.entry_id=e.id) AS reviews FROM mucho_creator_entries e JOIN accounts a ON a.account_id=e.account_id WHERE e.event_id=? ORDER BY COALESCE(e.result_rank,2147483647),e.id LIMIT 200');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function judges(int $id): array
    {
        $s = $this->db->prepare('SELECT j.account_id,a.username FROM mucho_creator_judges j JOIN accounts a ON a.account_id=j.account_id WHERE j.event_id=? ORDER BY j.account_id');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function reviews(int $id): array
    {
        $s = $this->db->prepare('SELECT s.*,a.username FROM mucho_creator_scores s JOIN mucho_creator_entries e ON e.id=s.entry_id JOIN accounts a ON a.account_id=s.judge_id WHERE e.event_id=? ORDER BY s.entry_id,s.judge_id LIMIT 4000');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function create(string $title, string $rules, string $deadline, int $minimum): int
    {
        $this->active();
        $values = $this->settings($title, $rules, $deadline, $minimum);
        $s = $this->db->prepare('INSERT INTO mucho_creator_events(title,rules,deadline,min_judges) VALUES(?,?,?,?)');
        $s->execute($values);
        return (int) $this->db->lastInsertId();
    }
    private function settings(string $title, string $rules, string $deadline, int $minimum): array
    {
        $title = trim($title);
        $rules = trim($rules);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $deadline, new \DateTimeZone('UTC'));
        if ($title === '' || strlen($title) > 100 || $rules === '' || strlen($rules) > 10000 || !$date || $date->format('Y-m-d\TH:i') !== $deadline || $date->getTimestamp() <= time() || $minimum < 1 || $minimum > 20) {
            throw new RuntimeException('Use a title, rules, a future UTC deadline and 1–20 required reviews.');
        }
        return [$title, $rules, $date->format('Y-m-d H:i:s'), $minimum];
    }
    public function edit(int $id, string $title, string $rules, string $deadline, int $minimum)
    {
        $values = $this->settings($title, $rules, $deadline, $minimum);
        $this->transaction($id, function (array $e) use ($id, $values) {
            if ($e['state'] !== 'draft') {
                throw new RuntimeException('Rules and deadlines are locked after opening.');
            }
            $this->db->prepare('UPDATE mucho_creator_events SET title=?,rules=?,deadline=?,min_judges=? WHERE id=?')->execute(array_merge($values, [$id]));
        });
    }
    public function removeJudge(int $id, int $account)
    {
        $this->transaction($id, function (array $e) use ($id, $account) {
            if ($e['state'] !== 'draft') {
                throw new RuntimeException('The jury is locked after opening.');
            }
            $this->db->prepare('DELETE FROM mucho_creator_judges WHERE event_id=? AND account_id=?')->execute([$id, $account]);
        });
    }
    private function transaction(int $id, callable $fn)
    {
        $this->db->beginTransaction();
        try {
            // Lock the plugin first: disable waits for in-flight mutations.
            $p = $this->db->query('SELECT enabled FROM mucho_creator_plugin WHERE id=1 FOR UPDATE')->fetchColumn();
            if ((int) $p !== 1) {
                throw new RuntimeException('Creator Events is disabled.');
            }
            $s = $this->db->prepare('SELECT *,deadline>UTC_TIMESTAMP() AS accepting FROM mucho_creator_events WHERE id=? FOR UPDATE');
            $s->execute([$id]);
            $event = $s->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                throw new RuntimeException('Event not found.');
            }
            $result = $fn($event);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function addJudge(int $id, int $account)
    {
        $this->transaction($id, function (array $event) use ($id, $account) {
            if ($event['state'] !== 'draft') {
                throw new RuntimeException('Assign the jury before opening submissions.');
            }
            $s = $this->db->prepare('SELECT account_id FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0');
            $s->execute([$account]);
            if (!$s->fetchColumn()) {
                throw new RuntimeException('Choose an active player account.');
            }
            $this->db->prepare('INSERT IGNORE INTO mucho_creator_judges(event_id,account_id) VALUES(?,?)')->execute([$id, $account]);
        });
    }
    public function transition(int $id, string $next)
    {
        $this->transaction($id, function (array $event) use ($id, $next) {
            $from = $event['state'];
            $allowed = ['draft' => ['open', 'cancelled'], 'open' => ['judging', 'cancelled'], 'judging' => ['published', 'cancelled']];
            if (!in_array($next, $allowed[$from] ?? [], true)) {
                throw new RuntimeException('Invalid event transition.');
            }
            if ($next === 'open') {
                if (!(int) $event['accepting'] || count($this->judges($id)) < (int) $event['min_judges']) {
                    throw new RuntimeException('Set a future deadline and enough judges first.');
                }
            }
            if ($next === 'judging' && (int) $event['accepting']) {
                throw new RuntimeException('Submissions close at the deadline.');
            }
            if ($next === 'published') {
                $this->publish($event);
            }
            $this->db->prepare('UPDATE mucho_creator_events SET state=? WHERE id=?')->execute([$next, $id]);
        });
    }
    public function submit(int $event, int $account, int $level)
    {
        $this->transaction($event, function (array $e) use ($event, $account, $level) {
            if ($e['state'] !== 'open' || !(int) $e['accepting']) {
                throw new RuntimeException('Submissions are closed.');
            }
            $s = $this->db->prepare('SELECT account_id FROM mucho_creator_judges WHERE event_id=? AND account_id=?');
            $s->execute([$event, $account]);
            if ($s->fetchColumn()) {
                throw new RuntimeException('Judges cannot enter their own contest.');
            }
            $s = $this->db->prepare('SELECT level_id,name,level_data FROM levels WHERE level_id=? AND account_id=? AND is_deleted=0 AND unlisted=0 AND unlisted2=0 FOR UPDATE');
            $s->execute([$level, $account]);
            $l = $s->fetch(PDO::FETCH_ASSOC);
            if (!$l) {
                throw new RuntimeException('Submit a public level that belongs to your account.');
            }
            $count = $this->db->prepare('SELECT COUNT(*) FROM mucho_creator_entries WHERE event_id=? AND account_id<>?');
            $count->execute([$event, $account]);
            if ((int) $count->fetchColumn() >= 200) {
                throw new RuntimeException('This event has reached its 200-entry limit.');
            }
            $s = $this->db->prepare('SELECT id FROM mucho_creator_entries WHERE event_id=? AND account_id=?');
            $s->execute([$event, $account]);
            $existing = $s->fetchColumn();
            if ($existing) {
                $this->db->prepare('UPDATE mucho_creator_entries SET level_id=?,level_name=?,content_hash=?,submitted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$level, $l['name'], hash('sha256', (string) $l['level_data']), $existing]);
            } else {
                $this->db->prepare('INSERT INTO mucho_creator_entries(event_id,account_id,level_id,level_name,content_hash) VALUES(?,?,?,?,?)')->execute([$event, $account, $level, $l['name'], hash('sha256', (string) $l['level_data'])]);
            }
        });
    }
    public function withdraw(int $event, int $account)
    {
        $this->transaction($event, function (array $e) use ($event, $account) {
            if ($e['state'] !== 'open' || !(int) $e['accepting']) {
                throw new RuntimeException('Submissions are closed.');
            }
            $this->db->prepare('DELETE FROM mucho_creator_entries WHERE event_id=? AND account_id=?')->execute([$event, $account]);
        });
    }
    public function score(int $event, int $entry, int $judge, int $design, int $gameplay, int $originality, string $note)
    {
        foreach ([$design, $gameplay, $originality] as $value) {
            if ($value < 0 || $value > 10) {
                throw new RuntimeException('Scores must be integers from 0 to 10.');
            }
        }
        if (strlen($note) > 1000) {
            throw new RuntimeException('Review notes are limited to 1000 bytes.');
        }
        $this->transaction($event, function (array $e) use ($event, $entry, $judge, $design, $gameplay, $originality, $note) {
            if ($e['state'] !== 'judging') {
                throw new RuntimeException('Judging is not open.');
            }
            $s = $this->db->prepare('SELECT j.account_id FROM mucho_creator_judges j JOIN accounts a ON a.account_id=j.account_id WHERE event_id=? AND j.account_id=? AND a.is_active=1 AND a.is_banned=0');
            $s->execute([$event, $judge]);
            if (!$s->fetchColumn()) {
                throw new RuntimeException('Only assigned judges may score entries.');
            }
            $s = $this->db->prepare('SELECT e.account_id,e.content_hash,e.disqualified,l.account_id AS owner,l.unlisted,l.unlisted2,l.level_data,l.is_deleted FROM mucho_creator_entries e JOIN levels l ON l.level_id=e.level_id WHERE e.id=? AND e.event_id=? FOR UPDATE');
            $s->execute([$entry, $event]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) $row['disqualified'] || (int) $row['owner'] !== (int) $row['account_id'] || (int) $row['unlisted'] || (int) $row['unlisted2'] || (int) $row['account_id'] === $judge || (int) $row['is_deleted'] !== 0 || !hash_equals($row['content_hash'], hash('sha256', (string) $row['level_data']))) {
                throw new RuntimeException('Entry missing or level changed since submission.');
            }
            $this->db->prepare('INSERT INTO mucho_creator_scores(entry_id,judge_id,design,gameplay,originality,note) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE design=VALUES(design),gameplay=VALUES(gameplay),originality=VALUES(originality),note=VALUES(note)')->execute([$entry, $judge, $design, $gameplay, $originality, $note]);
        });
    }
    public function disqualify(int $event, int $entry, string $reason)
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 1000) {
            throw new RuntimeException('Provide a disqualification reason (up to 1000 bytes).');
        }
        $this->transaction($event, function (array $e) use ($event, $entry, $reason) {
            if ($e['state'] !== 'judging') {
                throw new RuntimeException('Disqualifications are available during judging.');
            }
            $s = $this->db->prepare('UPDATE mucho_creator_entries SET disqualified=1,disqualification_note=? WHERE id=? AND event_id=?');
            $s->execute([$reason, $entry, $event]);
            if (!$s->rowCount()) {
                throw new RuntimeException('Entry not found or already disqualified.');
            }
        });
    }
    private function publish(array $event)
    {
        $entries = $this->entries((int) $event['id']);
        if (!$entries) {
            throw new RuntimeException('An empty event cannot be published.');
        }
        $ranked = [];
        foreach ($entries as $entry) {
            if ((int) $entry['disqualified']) {
                continue;
            }
            $s = $this->db->prepare('SELECT l.account_id,l.level_data,l.is_deleted,l.unlisted,l.unlisted2 FROM levels l WHERE level_id=? FOR UPDATE');
            $s->execute([$entry['level_id']]);
            $level = $s->fetch(PDO::FETCH_ASSOC);
            if (!$level || (int) $level['account_id'] !== (int) $entry['account_id'] || (int) $level['is_deleted'] || (int) $level['unlisted'] || (int) $level['unlisted2'] || !hash_equals($entry['content_hash'], hash('sha256', (string) $level['level_data']))) {
                throw new RuntimeException('A submitted level changed or is no longer public.');
            }
            $s = $this->db->prepare('SELECT COUNT(*),AVG(s.design+s.gameplay+s.originality) FROM mucho_creator_scores s JOIN mucho_creator_judges j ON j.event_id=? AND j.account_id=s.judge_id JOIN accounts a ON a.account_id=j.account_id WHERE s.entry_id=? AND a.is_active=1 AND a.is_banned=0');
            $s->execute([$event['id'], $entry['id']]);
            $score = $s->fetch(PDO::FETCH_NUM);
            if ((int) $score[0] < (int) $event['min_judges']) {
                throw new RuntimeException('Every entry needs the required number of reviews.');
            }
            $ranked[] = ['id' => (int) $entry['id'], 'score' => (float) $score[1]];
        }
        if (!$ranked) {
            throw new RuntimeException('At least one eligible entry is required.');
        }
        usort($ranked, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id'];
        });
        $rank = 0;
        $previous = null;
        foreach ($ranked as $index => $row) {
            if ($previous === null || abs($row['score'] - $previous) > 1.0E-5) {
                $rank = $index + 1;
            }
            $previous = $row['score'];
            $this->db->prepare('UPDATE mucho_creator_entries SET result_score=?,result_rank=? WHERE id=?')->execute([$row['score'], $rank, $row['id']]);
        }
    }
    private $db;
}
