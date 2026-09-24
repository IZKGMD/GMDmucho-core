<?php

declare(strict_types=1);

namespace MuchoCore\Database;

use Dotenv\Dotenv;
use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $projectRoot = dirname(__DIR__, 2);

        // The project .env contains public deployment configuration.
        Dotenv::createImmutable($projectRoot)->safeLoad();

        // Production database credentials are generated at runtime and stored
        // outside the bind-mounted project directory.
        $runtimeEnv = '/var/lib/muchocore/runtime.env';
        if (is_file($runtimeEnv)) {
            Dotenv::createMutable(
                dirname($runtimeEnv),
                basename($runtimeEnv)
            )->safeLoad();
        }

        // Prefer process environment values so Docker service configuration
        // cannot be shadowed by stale project .env entries.
        $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
        $name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? '');
        $user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '');
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }
}
