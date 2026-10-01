<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Database\Migrator;
use MuchoCore\Migration\CvoltonDatabaseImporter;
use MuchoCore\Migration\SourceDetector;
use PDO;
use RuntimeException;
use Throwable;

if (PHP_SAPI !== "cli") {
    exit(1);
}

require dirname(__DIR__) . "/vendor/autoload.php";

const MAX_DUMP_BYTES = 67108864;

function failSql(string $message, int $code = 10): never
{
    fwrite(STDERR, "SQL migration failed: " . $message . PHP_EOL);
    exit($code);
}

function migrationSecret(): string
{
    $path = (string)(getenv("MUCHO_MIGRATION_DB_PASSWORD_FILE") ?: "/run/secrets/migration_db_password");
    $value = @file_get_contents($path);

    if ($value === false || trim($value) === "") {
        throw new RuntimeException("Migration database password secret is unavailable.");
    }

    return trim($value);
}

function migrationEnv(string $name, string $default): string
{
    $value = getenv($name);
    return is_string($value) && $value !== "" ? $value : $default;
}

function connectMigrationSource(bool $readOnly): PDO
{
    $host = migrationEnv("MUCHO_MIGRATION_DB_HOST", "db");
    $port = (int)migrationEnv("MUCHO_MIGRATION_DB_PORT", "3306");
    $database = migrationEnv("MUCHO_MIGRATION_DB_NAME", "muchocore_migration");
    $user = migrationEnv("MUCHO_MIGRATION_DB_USER", "muchocore_migration");

    if (!preg_match("/^[A-Za-z0-9._:-]+$/", $host) ||
        $port < 1 || $port > 65535 ||
        !preg_match("/^[A-Za-z0-9_$.-]{1,128}$/", $database) ||
        !preg_match("/^[A-Za-z0-9_.-]{1,128}$/", $user)) {
        throw new RuntimeException("Invalid migration database configuration.");
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($readOnly) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION TRANSACTION READ ONLY";
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        migrationSecret(),
        $options
    );
}

function resetMigrationDatabase(PDO $db): void
{
    $tables = $db->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);
    $db->exec("SET FOREIGN_KEY_CHECKS=0");

    try {
        foreach ($tables as $row) {
            $table = (string)($row[0] ?? "");
            if ($table !== "" && preg_match("/^[A-Za-z0-9_$.-]+$/", $table)) {
                $db->exec("DROP TABLE IF EXISTS " . $table);
            }
        }
    } finally {
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
    }
}

function validateDump(string $path): string
{
    $real = realpath($path);

    if ($real === false || !is_file($real) || !is_readable($real)) {
        throw new RuntimeException("Uploaded SQL dump was not found or is not readable.");
    }

    $size = filesize($real);
    if ($size === false || $size < 1) {
        throw new RuntimeException("Uploaded SQL dump is empty.");
    }

    if ($size > MAX_DUMP_BYTES) {
        throw new RuntimeException("Uploaded SQL dump is larger than 64 MiB.");
    }

    return $real;
}

function prepareDump(string $source): array
{
    $clean = tempnam(sys_get_temp_dir(), "muchocore-sql-");
    $in = fopen($source, "rb");
    $out = $clean === false ? false : fopen($clean, "wb");

    if ($clean === false || !is_resource($in) || !is_resource($out)) {
        @fclose($in);
        @fclose($out);
        if ($clean !== false) {
            @unlink($clean);
        }
        throw new RuntimeException("Unable to prepare the SQL dump.");
    }

    $removed = 0;

    try {
        while (($line = fgets($in)) !== false) {
            if (str_contains($line, "\0")) {
                throw new RuntimeException("The uploaded SQL dump contains binary data.");
            }

            $trim = ltrim($line);

            if (
                preg_match("/^(?:CREATE|DROP)\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?DATABASE\\b/i", $trim) ||
                preg_match("/^USE\\s+/i", $trim) ||
                preg_match("/^(?:GRANT|REVOKE|CREATE\\s+USER|ALTER\\s+USER|SET\\s+(?:GLOBAL|PERSIST))\\b/i", $trim)
            ) {
                $removed++;
                continue;
            }

            if (preg_match("/^(?:SOURCE|\\\\.|\\\\!)\\b/i", $trim)) {
                throw new RuntimeException("The SQL dump contains an unsupported client command.");
            }

            fwrite($out, $line);
        }
    } finally {
        fclose($in);
        fclose($out);
    }

    if ((int)filesize($clean) === 0) {
        @unlink($clean);
        throw new RuntimeException("The SQL dump contained no importable SQL statements.");
    }

    return [$clean, $removed];
}

function importDump(string $path): int
{
    [$clean, $removed] = prepareDump($path);

    try {
        $command = [
            "mariadb",
            "--binary-mode",
            "--max-allowed-packet=256M",
            "--host=" . migrationEnv("MUCHO_MIGRATION_DB_HOST", "db"),
            "--port=" . migrationEnv("MUCHO_MIGRATION_DB_PORT", "3306"),
            "--user=" . migrationEnv("MUCHO_MIGRATION_DB_USER", "muchocore_migration"),
            "--database=" . migrationEnv("MUCHO_MIGRATION_DB_NAME", "muchocore_migration"),
        ];

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env["MYSQL_PWD"] = migrationSecret();

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes,
            dirname(__DIR__),
            $env
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Unable to start the MariaDB importer.");
        }

        $input = fopen($clean, "rb");
        if (!is_resource($input)) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException("Unable to open the prepared SQL dump.");
        }

        stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            $detail = trim((string)$stderr);
            if ($detail === "") {
                $detail = trim((string)$stdout);
            }
            throw new RuntimeException(
                "MariaDB rejected the SQL dump" .
                ($detail !== "" ? ": " . $detail : ".")
            );
        }

        return $removed;
    } finally {
        @unlink($clean);
    }
}

function createTargetBackup(): string
{
    $script = dirname(__DIR__) . "/bin/mucho-db-backup.sh";
    if (!is_file($script)) {
        throw new RuntimeException("Verified target backup is unavailable; migration was not started.");
    }

    $output = [];
    $code = 0;
    exec(
        "MUCHO_BACKUP_REQUIRED=1 /usr/bin/env bash " . escapeshellarg($script) . " 2>&1",
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Target database backup failed; migration was not started.\n" .
            implode("\n", $output)
        );
    }

    $file = null;
    $ok = false;
    foreach ($output as $line) {
        if (trim($line) === "BACKUP_OK") {
            $ok = true;
        }
        if (str_starts_with($line, "FILE=")) {
            $file = trim(substr($line, 5));
        }
    }

    if (!$ok || $file === null || !is_file($file)) {
        throw new RuntimeException("Target database backup was not verified; migration was not started.");
    }

    $hash = $file . ".sha256";
    if (!is_file($hash) || trim((string)file_get_contents($hash)) === "") {
        throw new RuntimeException("Target database backup checksum is missing; migration was not started.");
    }

    $verify = [];
    $verifyCode = 0;
    exec(
        "/usr/bin/env sha256sum -c " . escapeshellarg($hash) . " 2>&1",
        $verify,
        $verifyCode
    );

    if ($verifyCode !== 0) {
        throw new RuntimeException("Target database backup checksum verification failed; migration was not started.");
    }

    return $file;
}

$options = getopt("", ["file:", "apply", "confirm:", "json", "help"]);

if (isset($options["help"])) {
    echo "MuchoCore SQL Dump Migration\n";
    echo "Import a Cvolton-compatible database.sql through an isolated migration database.\n";
    echo "Dry-run is the default. Apply requires --apply --confirm=MIGRATE.\n";
    exit(0);
}

if (!isset($options["file"])) {
    failSql("Missing --file.");
}

$lockPath = getenv("MUCHO_MIGRATION_LOCK") ?: "/tmp/muchocore-migration.lock";
$lock = fopen($lockPath, "c");

if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    failSql("Migration is already running.", 11);
}

$source = null;

try {
    set_time_limit(0);

    $dump = validateDump((string)$options["file"]);
    echo "SQL_SOURCE=" . basename($dump) . PHP_EOL;

    $sourceLoader = connectMigrationSource(false);
    resetMigrationDatabase($sourceLoader);

    $sanitized = importDump($dump);
    $source = connectMigrationSource(true);
    if ($sanitized > 0) {
        echo "IMPORT_SANITIZED=" . $sanitized . PHP_EOL;
    }

    $inspection = (new SourceDetector())->inspect($source);

    if (($inspection["engine"] ?? "unknown") === "unknown") {
        echo "UNSUPPORTED SOURCE SCHEMA\n";
        echo "Detected tables: " .
            (implode(", ", $inspection["present_tables"] ?? []) ?: "none") .
            PHP_EOL;
        exit(3);
    }

    $target = (new Database())->connection();
    $preflight = (new CvoltonDatabaseImporter($target))->preflight($source);

    echo "SOURCE_DETECTED=" . (string)($inspection["label"] ?? "") . PHP_EOL;
    echo "CONFIDENCE=" . (string)($inspection["confidence"] ?? "") . PHP_EOL;
    echo "ACCOUNTS=" . (int)($preflight["accounts"] ?? 0) . PHP_EOL;
    echo "PROFILES=" . (int)($preflight["users"] ?? 0) . PHP_EOL;
    echo "LEVELS=" . (int)($preflight["levels"] ?? 0) . PHP_EOL;
    echo "CLASSIC_SCORES=" . (int)($preflight["levelscores"] ?? 0) . PHP_EOL;
    echo "PLATFORMER_SCORES=" . (int)($preflight["platscores"] ?? 0) . PHP_EOL;

    if (isset($options["json"])) {
        echo json_encode(
            [
                "mode" => isset($options["apply"]) ? "apply-requested" : "dry-run",
                "source" => $inspection,
                "preflight" => $preflight,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    }

    if (!isset($options["apply"])) {
        echo "DRY-RUN COMPLETE" . PHP_EOL;
        echo "The uploaded source remains isolated from the MuchoCore target database." . PHP_EOL;
        exit(0);
    }

    if (($options["confirm"] ?? "") !== "MIGRATE") {
        failSql("Apply requires --confirm=MIGRATE.");
    }

    echo "Creating verified target database backup..." . PHP_EOL;
    $backup = createTargetBackup();
    echo "TARGET_BACKUP=" . $backup . PHP_EOL;

    echo "Preparing MuchoCore schema..." . PHP_EOL;
    (new Migrator(
        $target,
        dirname(__DIR__) . "/database/migrations"
    ))->migrate();

    $target->beginTransaction();
    try {
        $stats = (new CvoltonDatabaseImporter($target))->apply($source);
        $target->commit();
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $e;
    }

    echo "MIGRATION COMPLETE" . PHP_EOL;
    foreach ($stats as $name => $value) {
        echo $name . "=" . (int)$value . PHP_EOL;
    }
} finally {
    if ($sourceLoader instanceof PDO) {
        try {
            resetMigrationDatabase($sourceLoader);
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning: migration source cleanup failed: " . $e->getMessage() . PHP_EOL);
        }
    }

    flock($lock, LOCK_UN);
    fclose($lock);
}
