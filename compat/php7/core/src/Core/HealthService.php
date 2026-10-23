<?php

declare (strict_types=1);
namespace MuchoCore\Core;

use PDO;
use Throwable;
final class HealthService
{
    public function __construct(PDO $pdo, SystemRepository $system)
    {
        $this->pdo = $pdo;
        $this->system = $system;
    }
    public function check(): array
    {
        $database = false;
        // The installed source is authoritative; a database can retain the
        // version seeded by the first installation after years of updates.
        $version = trim((string) @file_get_contents(dirname(\MUCHO7_PROJECT_ROOT . '/src/Core', 2) . '/VERSION'));
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            $version = 'unknown';
        }
        try {
            $database = $this->pdo->query('SELECT 1')->fetchColumn() == 1;
        } catch (Throwable $__mcCaught) {
            $database = false;
        }
        return ['status' => $database ? 'ok' : 'degraded', 'core' => 'MuchoCore', 'version' => $version, 'database' => $database ? 'ok' : 'error', 'php' => PHP_VERSION];
    }
    private $pdo;
    private $system;
}
