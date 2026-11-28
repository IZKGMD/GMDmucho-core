<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use InvalidArgumentException;
use MuchoCoreLegacySongs\NetworkCatalog;
use PDO;

final readonly class MusicLibrary
{
    public function __construct(private PDO $db, private NetworkCatalog $catalog)
    {
    }

    public function handle(string $operation, array $query): array
    {
        if ($operation === 'newgrounds') {
            return $this->fromNewgrounds($query['id'] ?? null);
        }

        $localOnly = $operation === 'muchocore';
        [$status, $body] = $this->catalog->handle($localOnly ? 'track' : $operation, $query);
        if (isset($body['tracks']) && $body['tracks'] !== []) {
            $ids = array_column($body['tracks'], 'id');
            $parameters = implode(',', array_fill(0, count($ids), '?'));
            $statement = $this->db->prepare(
                "SELECT id, newgrounds_id FROM songs
                 WHERE id IN ($parameters) AND is_verified = 1 AND newgrounds_id IS NOT NULL"
            );
            $statement->execute($ids);
            $sources = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($body['tracks'] as $index => $track) {
                $body['tracks'][$index]['source'] = $this->source($sources[$track['id']] ?? null);
            }
        } elseif (isset($body['track'])) {
            $statement = $this->db->prepare('SELECT newgrounds_id FROM songs WHERE id = ? AND is_verified = 1');
            $statement->execute([$body['track']['id']]);
            $sourceId = $statement->fetchColumn();
            $source = $this->source($sourceId);
            if ($localOnly && ($sourceId === false || $source !== null)) {
                return [404, ['error' => 'track_unavailable']];
            }
            $body['track']['source'] = $source;
        }
        return [$status, $body];
    }

    private function fromNewgrounds(mixed $value): array
    {
        try {
            $id = NewgroundsSource::parse($value);
        } catch (InvalidArgumentException) {
            $id = null;
        }
        if ($id === null) {
            return [400, ['error' => 'invalid_newgrounds_id']];
        }

        $statement = $this->db->prepare(
            'SELECT id FROM songs WHERE newgrounds_id = ? AND is_verified = 1
             AND id <= 2147483647 ORDER BY id ASC'
        );
        $statement->execute([$id]);
        while (($songId = $statement->fetchColumn()) !== false) {
            [$status, $body] = $this->catalog->handle('track', ['id' => (string) $songId]);
            if ($status === 200) {
                $body['track']['source'] = $this->source($id);
                return [$status, $body];
            }
        }
        return [404, ['error' => 'track_unavailable']];
    }

    private function source(mixed $id): ?array
    {
        return $id ? ['provider' => 'newgrounds', 'id' => (int) $id, 'url' => NewgroundsSource::url((int) $id)] : null;
    }
}
