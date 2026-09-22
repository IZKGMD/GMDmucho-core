<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use PDO;
use RuntimeException;

final readonly class LevelDownloadTracker
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function record(
        int $levelId,
        string $clientIp
    ): bool {
        if ($levelId <= 0 || $clientIp === '') {
            return false;
        }

        $hashKey = $_ENV['MUCHO_DOWNLOAD_HMAC_KEY']
            ?? getenv('MUCHO_DOWNLOAD_HMAC_KEY')
            ?: 'muchocore-level-download-v1';

        $clientHash = hash_hmac(
            'sha256',
            $clientIp,
            $hashKey
        );

        $this->pdo->beginTransaction();

        try {
            $slots = $this->pdo->prepare(
                'SELECT slot
                 FROM mucho_level_downloads
                 WHERE level_id=:level_id
                   AND client_hash=:client_hash
                 ORDER BY slot
                 FOR UPDATE'
            );

            $slots->execute([
                'level_id' => $levelId,
                'client_hash' => $clientHash,
            ]);

            $used = array_map(
                'intval',
                $slots->fetchAll(PDO::FETCH_COLUMN)
            );

            for ($slot = 1; $slot <= 2; $slot++) {
                if (in_array($slot, $used, true)) {
                    continue;
                }

                $insert = $this->pdo->prepare(
                    'INSERT INTO mucho_level_downloads
                     (level_id, client_hash, slot)
                     VALUES (:level_id,:client_hash,:slot)'
                );

                $insert->execute([
                    'level_id' => $levelId,
                    'client_hash' => $clientHash,
                    'slot' => $slot,
                ]);

                $update = $this->pdo->prepare(
                    'UPDATE levels
                     SET downloads=downloads+1
                     WHERE level_id=:level_id
                       AND is_deleted=0'
                );

                $update->execute([
                    'level_id' => $levelId,
                ]);

                $this->pdo->commit();

                return $update->rowCount() > 0;
            }

            $this->pdo->commit();

            return false;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                'Download counter transaction failed.',
                0,
                $e
            );
        }
    }
}
