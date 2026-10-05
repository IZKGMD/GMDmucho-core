<?php

declare(strict_types=1);

namespace MuchoCore\Database;

use MuchoCore\Core\Environment;
use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct()
    {
        // Environment is the single configuration source for database values.
        // On Docker/VPS installs it transparently reads the protected runtime
        // environment first and never reads credentials from the public web root.
        $host = (string)(Environment::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1');
        $port = (string)(Environment::get('DB_PORT', '3306') ?? '3306');
        $name = (string)(Environment::get('DB_NAME', '') ?? '');
        $user = (string)(Environment::get('DB_USER', '') ?? '');
        $pass = (string)(Environment::get('DB_PASS', '') ?? '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // MuchoCore stores and compares scheduler/job timestamps as UTC.
        // Pin the DB session to the same timezone so NOW()/CURRENT_TIMESTAMP
        // cannot drift when the server's MariaDB timezone differs from UTC.
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }
}
