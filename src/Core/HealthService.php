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

            'core' => 'MuchoCore',

            'version' =>
                $this->system->setting(
                    'server.version',
                    'unknown'
                ),

            'database' => $database
                ? 'ok'
                : 'error',

            'php' => PHP_VERSION,
        ];
    }
}
