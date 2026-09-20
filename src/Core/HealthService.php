<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use PDO;
use Throwable;

final readonly class HealthService
{
    public function __construct(
        private PDO $pdo,
        private SystemRepository $system,
    ) {
    }

    public function check(): array
    {
        $serverName = Settings::string(
            'MUCHO_SERVER_NAME',
            $this->system->setting('server.name', 'MuchoCore') ?? 'MuchoCore'
        );

        $serverVersion = Settings::string(
            'MUCHO_SERVER_VERSION',
            $this->system->setting('server.version', 'unknown') ?? 'unknown'
        );
        $database = false;

        try {
            $database =
                $this->pdo
                    ->query('SELECT 1')
                    ->fetchColumn() == 1;
        } catch (Throwable) {
            $database = false;
        }

        return [
            'status' => $database
                ? 'ok'
                : 'degraded',

            'core' => $serverName,

            'version' =>
                $serverVersion,

            'database' => $database
                ? 'ok'
                : 'error',

            'php' => PHP_VERSION,
        ];
    }
}
