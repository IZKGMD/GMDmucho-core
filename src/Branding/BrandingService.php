<?php

declare(strict_types=1);

namespace MuchoCore\Branding;

use PDO;
use Throwable;

final class BrandingService
{
    public const DEFAULT_SERVER_NAME = 'Mucho GDPS';
    public const MAX_SERVER_NAME_LENGTH = 64;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Return the public branding configuration.
     *
     * The fallback keeps the public site usable during first installation,
     * before the branding migration has been applied.
     *
     * @return array{server_name:string}
     */
    public function get(): array
    {
        try {
            $row = $this->db->query(
                'SELECT server_name
                 FROM mucho_branding
                 WHERE id=1
                 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (
                is_array($row) &&
                isset($row['server_name'])
            ) {
                $name = self::sanitize((string)$row['server_name']);

                if ($name !== '') {
                    return [
                        'server_name' => $name,
                    ];
                }
            }
        } catch (Throwable) {
            // Fall back to the built-in name when the table is not available yet.
        }

        return [
            'server_name' => self::DEFAULT_SERVER_NAME,
        ];
    }

    public function serverName(): string
    {
        return $this->get()['server_name'];
    }

    public function saveServerName(string $name): string
    {
        $name = self::sanitize($name);

        if ($name === '') {
            $name = self::DEFAULT_SERVER_NAME;
        }

        $q = $this->db->prepare(
            'INSERT INTO mucho_branding
                (id,server_name)
             VALUES
                (1,:server_name)
             ON DUPLICATE KEY UPDATE
                server_name=VALUES(server_name)'
        );

        $q->execute([
            'server_name' => $name,
        ]);

        return $name;
    }

    public static function sanitize(string $name): string
    {
        $name = trim($name);

        $name = preg_replace(
            '/[\\x00-\\x1F\\x7F]/u',
            '',
            $name
        ) ?? '';

        if (mb_strlen($name, 'UTF-8') > self::MAX_SERVER_NAME_LENGTH) {
            $name = mb_substr(
                $name,
                0,
                self::MAX_SERVER_NAME_LENGTH,
                'UTF-8'
            );
        }

        return trim($name);
    }
}
