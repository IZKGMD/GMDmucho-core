<?php

declare(strict_types=1);

namespace MuchoCoreLegacySongs;

use PDO;

final class NetworkCatalog
{
    const PAGE_SIZE = 16;
    const MAX_TRACK_BYTES = 64 * 1024 * 1024;

    private $db;
    private $libraries;

    /** Each public URL prefix points to one existing MuchoCore music directory. */
    public function __construct(PDO $db, array $libraries)
    {
        $this->db = $db;
        $this->libraries = $libraries;
    }

    public function handle(string $operation, array $query): array
    {
        switch ($operation) {
            case 'catalog': return $this->catalog($query);
            case 'track': return $this->track($query);
            case 'level': return $this->level($query);
        }
        return array(404, array('error' => 'unknown_operation'));
    }

    private function catalog(array $query): array
    {
        $after = $this->id($query['after'] ?? '0');
        if ($after === null) {
            return array(400, array('error' => 'invalid_cursor'));
        }

        $statement = $this->db->prepare(
            'SELECT id, name, author_name, download_url FROM songs
             WHERE is_verified = 1 AND id > :after AND id <= 2147483647
               AND download_url LIKE :suffix
             ORDER BY id ASC LIMIT ' . (self::PAGE_SIZE + 1)
        );
        $statement->execute(array('after' => $after, 'suffix' => '%.mp3'));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $tracks = array();
        foreach ($rows as $row) {
            $file = $this->localFile((string) $row['download_url']);
            if ($file !== null) {
                $tracks[] = $this->metadata($row, $file);
            }
        }

        return array(200, array(
            'version' => 1,
            'tracks' => $tracks,
            'next_after' => $hasMore ? (int) $rows[count($rows) - 1]['id'] : null,
        ));
    }

    private function track(array $query): array
    {
        $id = $this->id($query['id'] ?? null);
        if (!$id) {
            return array(400, array('error' => 'invalid_track_id'));
        }

        $statement = $this->db->prepare(
            'SELECT id, name, author_name, download_url FROM songs WHERE id = :id AND is_verified = 1'
        );
        $statement->execute(array('id' => $id));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $track = $row === false ? null : $this->downloadableTrack($row);
        if ($track === null) {
            return array(404, array('error' => 'track_unavailable'));
        }
        return array(200, array('version' => 1, 'track' => $track));
    }

    private function level(array $query): array
    {
        $level = $this->id($query['id'] ?? null);
        if (!$level) {
            return array(400, array('error' => 'invalid_level_id'));
        }

        $statement = $this->db->prepare(
            'SELECT l.song_id, s.id, s.name, s.author_name, s.download_url
             FROM levels l LEFT JOIN songs s ON s.id = l.song_id AND s.is_verified = 1
             WHERE l.level_id = :id AND l.is_deleted = 0 AND l.is_unlisted = 0 AND l.unlisted2 = 0'
        );
        $statement->execute(array('id' => $level));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return array(404, array('error' => 'level_unavailable'));
        }
        $track = $row['id'] === null ? null : $this->downloadableTrack($row);
        if ((string) $row['song_id'] !== '0' && $track === null) {
            return array(404, array('error' => 'track_unavailable'));
        }
        return array(200, array('version' => 1, 'level_id' => $level, 'track' => $track));
    }

    private function downloadableTrack(array $row)
    {
        $file = $this->localFile((string) $row['download_url']);
        if ($file === null) {
            return null;
        }
        $digest = @hash_file('sha256', $file['path']);
        if ($digest === false) {
            return null;
        }
        return $this->metadata($row, $file) + array('sha256' => $digest);
    }

    private function id($value)
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) !== 1) {
            return null;
        }
        return (float) $value <= 2147483647 ? (int) $value : null;
    }

    private function localFile(string $location)
    {
        $url = parse_url($location);
        if ($url === false || isset($url['query']) || isset($url['fragment'])) {
            return null;
        }
        $path = $url['path'] ?? '';
        foreach ($this->libraries as $prefix => $directory) {
            if (strpos($path, $prefix) !== 0) {
                continue;
            }
            $name = substr($path, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}\.mp3$/D', $name) !== 1) {
                continue;
            }
            $root = realpath($directory);
            $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $name);
            if ($file === false || dirname($file) !== $root || !is_file($file) || !is_readable($file)) {
                continue;
            }
            $size = @filesize($file);
            if ($size !== false && $size > 0 && $size <= self::MAX_TRACK_BYTES) {
                return array('path' => $file, 'bytes' => $size, 'url' => $prefix . $name);
            }
        }
        return null;
    }

    private function metadata(array $row, array $file): array
    {
        return array(
            'id' => (int) $row['id'],
            'title' => (string) $row['name'],
            'artist' => (string) $row['author_name'],
            'bytes' => $file['bytes'],
            'url' => $file['url'],
        );
    }
}
