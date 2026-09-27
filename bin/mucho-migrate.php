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

function ask(string $prompt, bool $secret = false): string
{
    fwrite(STDOUT, $prompt);

    if (!$secret) {
        $value = fgets(STDIN);
        return trim($value === false ? "" : $value);
    }

    $tty = trim((string)shell_exec("stty -g 2>/dev/null"));
    if ($tty !== "") {
        shell_exec("stty -echo");
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if ($tty !== "") {
            shell_exec("stty " . escapeshellarg($tty));
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return trim($value === false ? "" : $value);
}

function connectSource(
    string $host,
    int $port,
    string $database,
    string $user,
    string $password
): PDO {
    if (!preg_match("/^[A-Za-z0-9._:-]+$/", $host)) {
        throw new RuntimeException(
            "Invalid source host. Enter the MySQL/MariaDB host, not https:// and not the GDPS website URL."
        );
    }

    if (!preg_match("/^[A-Za-z0-9_-]{1,64}$/", $database)) {
        throw new RuntimeException("Invalid source database name.");
    }

    if ($user === "" || strlen($user) > 128) {
        throw new RuntimeException("Invalid source database user.");
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException("Invalid source port.");
    }

    return new PDO(
        "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $database . ";charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION TRANSACTION READ ONLY",
        ]
    );
}

function printConnectionHelp(): void
{
    echo PHP_EOL;
    echo "WHAT TO ENTER" . PHP_EOL;
    echo "-------------" . PHP_EOL;
    echo "Old DB host = MySQL/MariaDB server of the OLD GDPS." . PHP_EOL;
    echo "              NOT the website URL. Example: 127.0.0.1" . PHP_EOL;
    echo "Old DB port = MySQL port, normally 3306." . PHP_EOL;
    echo "Old DB name  = exact database name of the OLD GDPS." . PHP_EOL;
    echo "              Find it in the old host's database/phpMyAdmin panel." . PHP_EOL;
    echo "Old DB user  = MySQL/MariaDB user with SELECT access to that database." . PHP_EOL;
    echo "Old DB pass  = password for that MySQL/MariaDB user." . PHP_EOL;
    echo PHP_EOL;
    echo "For a MegaSa1nt/Cvolton-style server, database.sql is the schema file." . PHP_EOL;
    echo "For a live server, use the actual database credentials instead of the file." . PHP_EOL;
}

function printDatasetMap(array $datasets): void
{
    echo PHP_EOL . "WHAT IS WHERE" . PHP_EOL;
    echo "==============" . PHP_EOL;

    foreach ($datasets as $dataset) {
        if (!($dataset["available"] ?? false)) {
            continue;
        }

        $status = match ($dataset["status"] ?? "") {
            "imported_now" => "READY",
            "filesystem" => "FILES",
            default => "DETECTED",
        };

        echo PHP_EOL;
        echo "[" . $status . "] " . $dataset["label"] . PHP_EOL;
        echo "  Tables: " . implode(", ", $dataset["present_tables"] ?? []) . PHP_EOL;
        echo "  Where:  " . $dataset["where"] . PHP_EOL;
        echo "  Note:   " . $dataset["notes"] . PHP_EOL;
    }
}

function printCounts(array $preflight): void
{
    echo PHP_EOL . "CORE MIGRATION PREVIEW" . PHP_EOL;
    echo "======================" . PHP_EOL;

    $labels = [
        "accounts" => "Accounts",
        "users" => "Profiles",
        "levels" => "Levels",
        "levelscores" => "Classic scores",
        "platscores" => "Platformer scores",
    ];

    foreach ($labels as $key => $label) {
        echo sprintf(
            "  %-20s %10d rows" . PHP_EOL,
            $label . ":",
            (int)($preflight[$key] ?? 0)
        );
    }
}

function printHeader(string $mode): void
{
    echo PHP_EOL;
    echo "==============================================" . PHP_EOL;
    echo "            MUCHOCORE MIGRATION CENTER" . PHP_EOL;
    echo "==============================================" . PHP_EOL;
    echo PHP_EOL;
    echo "Mode:              " . $mode . PHP_EOL;
    echo "Source connection: READ ONLY" . PHP_EOL;
    echo "Destination:       MuchoCore" . PHP_EOL;
}

$options = getopt("", [
    "source-host:",
    "source-port::",
    "source-db:",
    "source-user:",
    "source-pass::",
    "apply",
    "confirm:",
    "json",
    "help",
]);

if (isset($options["help"])) {
    echo "MuchoCore Migration Center" . PHP_EOL;
    printConnectionHelp();
    echo PHP_EOL;
    echo "Interactive: php bin/mucho-migrate.php" . PHP_EOL;
    echo "Dry-run is the default and does not write to the destination." . PHP_EOL;
    echo "Apply requires --confirm=MIGRATE or interactive MIGRATE confirmation." . PHP_EOL;
    exit(0);
}

try {
    $requestedApply = isset($options["apply"]);
    printHeader($requestedApply ? "APPLY REQUESTED" : "DRY RUN");

    if (!$requestedApply) {
        echo "The destination will not be changed during this scan." . PHP_EOL;
    }

    printConnectionHelp();

    $host = (string)($options["source-host"] ?? "");
    $port = (int)($options["source-port"] ?? 3306);
    $database = (string)($options["source-db"] ?? "");
    $user = (string)($options["source-user"] ?? "");
    $password = array_key_exists("source-pass", $options)
        ? (string)$options["source-pass"]
        : (string)(getenv("MUCHO_MIGRATION_SOURCE_PASS") ?: "");

    if ($host === "") {
        $host = ask("Old DB host: ");
    }

    if (!isset($options["source-port"])) {
        $entered = ask("Old DB port [3306]: ");
        if ($entered !== "") {
            $port = (int)$entered;
        }
    }

    if ($database === "") {
        $database = ask("Old DB name: ");
    }

    if ($user === "") {
        $user = ask("Old DB user: ");
    }

    if ($password === "") {
        $password = ask("Old DB password: ", true);
    }

    echo PHP_EOL . "Connecting to old database..." . PHP_EOL;
    $source = connectSource($host, $port, $database, $user, $password);
    $inspection = (new SourceDetector())->inspect($source);

    if ($inspection["engine"] === "unknown") {
        echo PHP_EOL . "UNSUPPORTED SOURCE SCHEMA" . PHP_EOL;
        echo "Nothing was changed in MuchoCore." . PHP_EOL;
        echo "Detected tables: " . (implode(", ", $inspection["present_tables"]) ?: "none") . PHP_EOL;
        exit(3);
    }

    $target = (new Database())->connection();
    $importer = new CvoltonDatabaseImporter($target);
    $preflight = $importer->preflight($source);

    echo PHP_EOL . "SOURCE DETECTED" . PHP_EOL;
    echo "Family:          " . $inspection["label"] . PHP_EOL;
    echo "Confidence:      " . $inspection["confidence"] . PHP_EOL;
    echo "Required tables: " . implode(", ", $inspection["required_tables"]) . PHP_EOL;
    echo "Optional tables: " . (implode(", ", $inspection["optional_tables"]) ?: "none") . PHP_EOL;

    printCounts($preflight);
    printDatasetMap($inspection["datasets"]);

    if (isset($options["json"])) {
        echo json_encode(
            [
                "mode" => $requestedApply ? "apply-requested" : "dry-run",
                "source" => $inspection,
                "preflight" => $preflight,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    }

    if (!$requestedApply) {
        echo PHP_EOL . "DRY-RUN COMPLETE" . PHP_EOL;
        echo "The old database was read only." . PHP_EOL;
        echo "The destination database was not changed by the import." . PHP_EOL;
        echo "READY = imported automatically. DETECTED = found and reported, but not silently copied. FILES = database plus old-server filesystem data." . PHP_EOL;
        echo PHP_EOL . "To apply, run this wizard again with --apply and confirm MIGRATE." . PHP_EOL;
        exit(0);
    }

    if (($options["confirm"] ?? "") !== "MIGRATE") {
        $confirm = ask(PHP_EOL . "Type MIGRATE to apply this migration: ");
        if ($confirm !== "MIGRATE") {
            echo "Migration cancelled." . PHP_EOL;
            exit(4);
        }
    }

    echo PHP_EOL . "Preparing MuchoCore schema..." . PHP_EOL;
    (new Migrator(
        $target,
        dirname(__DIR__) . "/database/migrations"
    ))->migrate();

    echo "Importing READY datasets..." . PHP_EOL;

    $target->beginTransaction();
    try {
        $stats = $importer->apply($source);
        $target->commit();
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $e;
    }

    echo PHP_EOL . "==============================================" . PHP_EOL;
    echo "            MIGRATION COMPLETE" . PHP_EOL;
    echo "==============================================" . PHP_EOL;

    foreach ($stats as $name => $value) {
        echo sprintf("  %-30s %d" . PHP_EOL, $name . ":", (int)$value);
    }

    if (($stats["password_resets_required"] ?? 0) > 0) {
        echo PHP_EOL;
        echo "Password resets required: " . $stats["password_resets_required"] . PHP_EOL;
    }

    $detectedNotImported = array_values(array_filter(
        $inspection["datasets"],
        static fn(array $dataset): bool =>
            ($dataset["available"] ?? false) &&
            ($dataset["status"] ?? "") === "detected_only"
    ));

    if ($detectedNotImported !== []) {
        echo PHP_EOL . "ADDITIONAL DATA DETECTED BUT NOT IMPORTED" . PHP_EOL;
        foreach ($detectedNotImported as $dataset) {
            echo "  - " . $dataset["label"] . ": " .
                implode(", ", $dataset["present_tables"]) . PHP_EOL;
        }
        echo "The old database remains untouched for the next migration stage." . PHP_EOL;
    }

    $filesystem = array_values(array_filter(
        $inspection["datasets"],
        static fn(array $dataset): bool =>
            ($dataset["available"] ?? false) &&
            ($dataset["status"] ?? "") === "filesystem"
    ));

    if ($filesystem !== []) {
        echo PHP_EOL . "FILESYSTEM DATA STILL REQUIRES ACCESS TO THE OLD SERVER" . PHP_EOL;
        foreach ($filesystem as $dataset) {
            echo "  - " . $dataset["label"] . ": copy the old music/ and sfx/ directories separately." . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(10);
}
