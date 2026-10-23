<?php

declare (strict_types=1);
namespace MuchoCore\Artist;

use PDO;
use MuchoCore\V71\SchemaInspector;
use RuntimeException;
final class TopArtistRepository
{
    private $schema;
    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->schema = new SchemaInspector($db);
    }
    public function page(int $offset, int $limit): array
    {
        if (!$this->schema->tableExists('songs') || !$this->schema->columnExists('songs', 'author_name')) {
            throw new RuntimeException('songs.author_name missing');
        }
        $stmt = $this->db->prepare("SELECT\n                author_name AS authorName,\n                MIN(download_url) AS download,\n                COUNT(*) AS song_count\n\n             FROM songs\n\n             WHERE author_name IS NOT NULL\n               AND author_name <> ''\n               AND author_name NOT LIKE '%Reupload%'\n               AND LOWER(author_name) <> 'unknown'\n\n             GROUP BY author_name\n\n             ORDER BY\n                song_count DESC,\n                author_name ASC\n\n             LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $count = $this->db->query("SELECT COUNT(DISTINCT author_name)\n             FROM songs\n             WHERE author_name IS NOT NULL\n               AND author_name <> ''\n               AND author_name NOT LIKE '%Reupload%'\n               AND LOWER(author_name) <> 'unknown'");
        return ['rows' => $stmt->fetchAll() ?: [], 'total' => (int) $count->fetchColumn()];
    }
    private $db;
}
