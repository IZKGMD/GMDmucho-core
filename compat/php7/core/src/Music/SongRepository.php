<?php

declare (strict_types=1);
namespace MuchoCore\Music;

use PDO;
final class SongRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function findSong(int $songId)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM songs
            WHERE id = :id
              AND is_verified = 1
            LIMIT 1');
        $stmt->execute(['id' => $songId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    private $pdo;
}
