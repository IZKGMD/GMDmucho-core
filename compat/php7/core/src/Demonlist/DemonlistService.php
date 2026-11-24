<?php

declare (strict_types=1);
namespace MuchoCore\Demonlist;

use InvalidArgumentException;
use PDO;
use PDOException;
final class DemonlistService
{
    const PUBLIC_LEVEL = 'l.demon=1 AND l.is_deleted=0 AND l.is_unlisted=0 AND l.unlisted2=0';
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    public function levels(bool $publishedOnly = true): array
    {
        $filter = $publishedOnly ? 'WHERE d.is_published=1 AND ' . self::PUBLIC_LEVEL : '';
        return $this->db->query("SELECT d.level_id,d.position,d.is_published,l.name,a.username AS creator\n            FROM mucho_demonlist_levels d JOIN levels l ON l.level_id=d.level_id\n            LEFT JOIN accounts a ON a.account_id=l.account_id\n            {$filter} ORDER BY d.is_published DESC,d.position,d.level_id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function records(int $levelId): array
    {
        $q = $this->db->prepare("SELECT a.username,r.video_id,r.submitted_at\n            FROM mucho_demonlist_records r JOIN mucho_demonlist_levels d ON d.level_id=r.level_id\n            JOIN levels l ON l.level_id=r.level_id JOIN accounts a ON a.account_id=r.account_id\n            WHERE r.level_id=? AND r.status='approved' AND d.is_published=1\n            AND a.is_active=1 AND a.is_banned=0 AND " . self::PUBLIC_LEVEL . '
            ORDER BY r.submitted_at,r.id LIMIT 100');
        $q->execute([$levelId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function pending(): array
    {
        return $this->db->query("SELECT r.id,r.level_id,l.name,a.username,r.video_id,r.submitted_at\n            FROM mucho_demonlist_records r LEFT JOIN accounts a ON a.account_id=r.account_id\n            LEFT JOIN levels l ON l.level_id=r.level_id\n            WHERE r.status='pending' ORDER BY r.id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function recordStatus(int $accountId, int $levelId)
    {
        $q = $this->db->prepare('SELECT status FROM mucho_demonlist_records WHERE account_id=? AND level_id=?');
        $q->execute([$accountId, $levelId]);
        $status = $q->fetchColumn();
        return is_string($status) ? $status : null;
    }
    public function saveLevel(int $levelId, int $position, bool $published)
    {
        if ($levelId <= 0 || $position < 1 || $position > 500) {
            throw new InvalidArgumentException('Enter a level ID and position from 1 to 500.');
        }
        $valid = $this->db->prepare('SELECT d.level_id IS NOT NULL FROM levels l
            LEFT JOIN mucho_demonlist_levels d ON d.level_id=l.level_id WHERE l.level_id=?' . ($published ? ' AND ' . self::PUBLIC_LEVEL : ''));
        $valid->execute([$levelId]);
        $exists = $valid->fetchColumn();
        if ($exists === false) {
            throw new InvalidArgumentException($published ? 'Choose a public, rated Demon level.' : 'Level not found.');
        }
        $q = $this->db->prepare((int) $exists ? 'UPDATE mucho_demonlist_levels SET position=?,is_published=? WHERE level_id=?' : 'INSERT INTO mucho_demonlist_levels (position,is_published,level_id) VALUES (?,?,?)');
        try {
            // Only a published level occupies a numbered slot.
            $q->execute([$published ? $position : null, (int) $published, $levelId]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            throw new InvalidArgumentException('The position is in use or the list was edited. Reload the list and try again.', 0, $e);
        }
    }
    public function submit(int $accountId, int $levelId, string $proof): bool
    {
        $videoId = self::videoId($proof);
        if ($accountId <= 0 || $levelId <= 0 || $videoId === null) {
            throw new InvalidArgumentException('Choose a listed Demon and enter a YouTube recording of the 100% run.');
        }
        $q = $this->db->prepare("INSERT INTO mucho_demonlist_records (account_id,level_id,video_id)\n            SELECT a.account_id,d.level_id,? FROM accounts a\n            JOIN mucho_demonlist_levels d ON d.level_id=? JOIN levels l ON l.level_id=d.level_id\n            WHERE a.account_id=? AND a.is_active=1 AND a.is_banned=0 AND d.is_published=1\n                AND " . self::PUBLIC_LEVEL . "\n            ON DUPLICATE KEY UPDATE\n                video_id=IF(status='rejected',VALUES(video_id),video_id),\n                submitted_at=IF(status='rejected',CURRENT_TIMESTAMP,submitted_at),\n                reviewed_at=IF(status='rejected',NULL,reviewed_at),\n                reviewed_by=IF(status='rejected',NULL,reviewed_by),\n                status=IF(status='rejected','pending',status)");
        $q->execute([$videoId, $levelId, $accountId]);
        return $q->rowCount() > 0;
    }
    public function review(int $recordId, int $adminId, bool $approve): bool
    {
        $filter = $approve ? ' AND d.is_published=1 AND a.is_active=1 AND a.is_banned=0 AND ' . self::PUBLIC_LEVEL : '';
        $q = $this->db->prepare("UPDATE mucho_demonlist_records r\n            LEFT JOIN mucho_demonlist_levels d ON d.level_id=r.level_id\n            LEFT JOIN levels l ON l.level_id=r.level_id\n            LEFT JOIN accounts a ON a.account_id=r.account_id\n            SET r.status=?,r.reviewed_by=?,r.reviewed_at=CURRENT_TIMESTAMP\n            WHERE r.id=? AND r.status='pending'{$filter}");
        $q->execute([$approve ? 'approved' : 'rejected', $adminId, $recordId]);
        return $q->rowCount() === 1;
    }
    public static function videoId(string $proof)
    {
        $parts = strlen($proof) <= 512 ? parse_url(trim($proof)) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = '';
        if ($host === 'youtu.be') {
            $id = substr($path, 1);
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? '';
            } elseif (preg_match('~^/(?:shorts|live)/([A-Za-z0-9_-]{11})$~D', $path, $match)) {
                $id = $match[1];
            }
        }
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : null;
    }
    private $db;
}
