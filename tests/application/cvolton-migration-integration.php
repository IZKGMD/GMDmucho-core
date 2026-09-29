<?php

declare(strict_types=1);

use MuchoCore\Migration\CvoltonDatabaseImporter;
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$rootPassword = (string)(getenv('TEST_DB_ROOT_PASSWORD') ?: '');

if ($rootPassword === '') {
    throw new RuntimeException('TEST_DB_ROOT_PASSWORD is required.');
}

$targetDb = 'muchocore_migration_it';
$sourceDb = 'cvolton_migration_it';
$fixtureRoot = sys_get_temp_dir() . '/muchocore-migration-it-' . bin2hex(random_bytes(4));
$runtimeEnv = $fixtureRoot . '/runtime.env';

function pdoRoot(string $host, string $port, string $password): PDO
{
    return new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
        'root',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function pdoDb(string $host, string $port, string $db, string $password): PDO
{
    return new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4',
        'root',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function must(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scalar(PDO $db, string $sql): int
{
    return (int)$db->query($sql)->fetchColumn();
}

function runCli(
    string $root,
    string $host,
    string $port,
    string $sourceDb,
    string $sourcePassword,
    string $targetRoot,
    string $runtimeEnv
): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' .
        escapeshellarg($root . '/bin/import-cvolton-db.php') . ' ' .
        escapeshellarg('--source-host=' . $host) . ' ' .
        escapeshellarg('--source-port=' . $port) . ' ' .
        escapeshellarg('--source-db=' . $sourceDb) . ' ' .
        escapeshellarg('--source-user=root') . ' ' .
        '--apply --confirm=COVOLTON';

    $env = $_ENV;
    $env['DB_HOST'] = $host;
    $env['DB_PORT'] = $port;
    $env['DB_NAME'] = 'muchocore_migration_it';
    $env['DB_USER'] = 'root';
    $env['DB_PASS'] = $sourcePassword;
    $env['CVOLTON_SOURCE_PASS'] = $sourcePassword;
    $env['MUCHO_CORE_ROOT'] = $targetRoot;
    $env['MUCHO_RUNTIME_ENV_FILE'] = $runtimeEnv;

    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        $env
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Cvolton migration CLI.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'code' => proc_close($process),
        'output' => (string)$stdout . (string)$stderr,
    ];
}

try {
    $server = pdoRoot($host, $port, $rootPassword);
    $server->exec('DROP DATABASE IF EXISTS ' . $targetDb);
    $server->exec('DROP DATABASE IF EXISTS ' . $sourceDb);
    $server->exec(
        'CREATE DATABASE ' . $targetDb .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $server->exec(
        'CREATE DATABASE ' . $sourceDb .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $source = pdoDb($host, $port, $sourceDb, $rootPassword);
    $target = pdoDb($host, $port, $targetDb, $rootPassword);

    $source->exec(<<<'SQL'
CREATE TABLE accounts (
    userName varchar(255) NOT NULL,
    password varchar(255) NOT NULL,
    gjp2 varchar(255) DEFAULT NULL,
    email varchar(255) NOT NULL,
    accountID int NOT NULL AUTO_INCREMENT,
    isActive tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (accountID),
    UNIQUE KEY uq_accounts_name (userName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $source->exec(<<<'SQL'
CREATE TABLE users (
    userID int NOT NULL AUTO_INCREMENT,
    extID varchar(255) NOT NULL,
    userName varchar(69) NOT NULL,
    stars int NOT NULL DEFAULT 0,
    moons int NOT NULL DEFAULT 0,
    diamonds int NOT NULL DEFAULT 0,
    coins int NOT NULL DEFAULT 0,
    userCoins int NOT NULL DEFAULT 0,
    demons int NOT NULL DEFAULT 0,
    creatorPoints double NOT NULL DEFAULT 0,
    icon int NOT NULL DEFAULT 1,
    iconType int NOT NULL DEFAULT 0,
    color1 int NOT NULL DEFAULT 0,
    color2 int NOT NULL DEFAULT 3,
    accGlow int NOT NULL DEFAULT 0,
    isBanned int NOT NULL DEFAULT 0,
    PRIMARY KEY (userID),
    KEY idx_users_extid (extID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $source->exec(<<<'SQL'
CREATE TABLE levels (
    gameVersion int NOT NULL,
    binaryVersion int NOT NULL DEFAULT 0,
    levelID int NOT NULL AUTO_INCREMENT,
    levelName varchar(255) NOT NULL,
    levelDesc mediumtext NOT NULL,
    levelVersion int NOT NULL,
    levelLength int NOT NULL DEFAULT 0,
    audioTrack int NOT NULL DEFAULT 0,
    original int NOT NULL DEFAULT 0,
    twoPlayer int NOT NULL DEFAULT 0,
    songID int NOT NULL DEFAULT 0,
    objects int NOT NULL DEFAULT 0,
    coins int NOT NULL DEFAULT 0,
    requestedStars int NOT NULL DEFAULT 0,
    levelString longtext DEFAULT NULL,
    starDifficulty int NOT NULL DEFAULT 0,
    downloads int NOT NULL DEFAULT 0,
    likes int NOT NULL DEFAULT 0,
    starDemon int NOT NULL DEFAULT 0,
    starAuto int NOT NULL DEFAULT 0,
    starStars int NOT NULL DEFAULT 0,
    starFeatured int NOT NULL DEFAULT 0,
    starEpic int NOT NULL DEFAULT 0,
    starDemonDiff int NOT NULL DEFAULT 0,
    extID varchar(255) NOT NULL,
    unlisted int NOT NULL DEFAULT 0,
    isDeleted int NOT NULL DEFAULT 0,
    isLDM int NOT NULL DEFAULT 0,
    wt int NOT NULL DEFAULT 0,
    wt2 int NOT NULL DEFAULT 0,
    ts bigint NOT NULL DEFAULT 0,
    songIDs text NOT NULL,
    sfxIDs text NOT NULL,
    PRIMARY KEY (levelID),
    KEY idx_levels_extid (extID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $source->exec(<<<'SQL'
CREATE TABLE levelscores (
    scoreID int NOT NULL AUTO_INCREMENT,
    accountID int NOT NULL,
    levelID int NOT NULL,
    percent int NOT NULL DEFAULT 0,
    uploadDate int NOT NULL DEFAULT 0,
    attempts int NOT NULL DEFAULT 0,
    coins int NOT NULL DEFAULT 0,
    clicks int NOT NULL DEFAULT 0,
    time int NOT NULL DEFAULT 0,
    progresses text NOT NULL,
    dailyID int NOT NULL DEFAULT 0,
    PRIMARY KEY (scoreID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $source->exec(<<<'SQL'
CREATE TABLE platscores (
    ID int NOT NULL AUTO_INCREMENT,
    accountID int NOT NULL,
    levelID int NOT NULL,
    time int NOT NULL DEFAULT 0,
    points int NOT NULL DEFAULT 0,
    timestamp int NOT NULL DEFAULT 0,
    PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE accounts (
    account_id bigint unsigned NOT NULL AUTO_INCREMENT,
    username varchar(20) NOT NULL,
    email varchar(254) NOT NULL,
    password_hash varchar(255) NOT NULL,
    gjp2_hash varchar(255) DEFAULT NULL,
    role_id smallint unsigned NOT NULL DEFAULT 1,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    is_banned tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id),
    UNIQUE KEY uq_accounts_username (username),
    UNIQUE KEY uq_accounts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE profiles (
    user_id bigint unsigned NOT NULL AUTO_INCREMENT,
    account_id bigint unsigned NOT NULL,
    stars int unsigned NOT NULL DEFAULT 0,
    moons int unsigned NOT NULL DEFAULT 0,
    diamonds int unsigned NOT NULL DEFAULT 0,
    secret_coins int unsigned NOT NULL DEFAULT 0,
    user_coins int unsigned NOT NULL DEFAULT 0,
    demons int unsigned NOT NULL DEFAULT 0,
    creator_points int unsigned NOT NULL DEFAULT 0,
    icon_id int unsigned NOT NULL DEFAULT 1,
    icon_type smallint unsigned NOT NULL DEFAULT 0,
    color1 smallint unsigned NOT NULL DEFAULT 0,
    color2 smallint unsigned NOT NULL DEFAULT 3,
    glow tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_profiles_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE levels (
    level_id bigint unsigned NOT NULL AUTO_INCREMENT,
    account_id bigint unsigned NOT NULL,
    name varchar(64) NOT NULL,
    description text DEFAULT NULL,
    level_data longtext NOT NULL,
    level_version int unsigned NOT NULL DEFAULT 1,
    game_version int unsigned NOT NULL DEFAULT 22,
    binary_version int unsigned NOT NULL DEFAULT 0,
    length smallint unsigned NOT NULL DEFAULT 0,
    audio_track smallint unsigned NOT NULL DEFAULT 0,
    difficulty smallint unsigned NOT NULL DEFAULT 0,
    demon tinyint(1) NOT NULL DEFAULT 0,
    demon_difficulty smallint unsigned NOT NULL DEFAULT 0,
    auto_level tinyint(1) NOT NULL DEFAULT 0,
    featured tinyint(1) NOT NULL DEFAULT 0,
    epic smallint unsigned NOT NULL DEFAULT 0,
    object_count int unsigned NOT NULL DEFAULT 0,
    original_level_id bigint unsigned NOT NULL DEFAULT 0,
    two_player tinyint(1) NOT NULL DEFAULT 0,
    coins smallint unsigned NOT NULL DEFAULT 0,
    coins_verified tinyint(1) NOT NULL DEFAULT 0,
    requested_stars smallint unsigned NOT NULL DEFAULT 0,
    ldm tinyint(1) NOT NULL DEFAULT 0,
    song_id int unsigned NOT NULL DEFAULT 0,
    song_ids text NULL,
    sfx_ids text NULL,
    wt int NOT NULL DEFAULT 0,
    wt2 int NOT NULL DEFAULT 0,
    ts bigint NOT NULL DEFAULT 0,
    downloads int unsigned NOT NULL DEFAULT 0,
    likes int NOT NULL DEFAULT 0,
    stars smallint unsigned NOT NULL DEFAULT 0,
    is_unlisted tinyint(1) NOT NULL DEFAULT 0,
    is_deleted tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE mucho_cvolton_account_map (
    source_id bigint NOT NULL,
    target_id bigint unsigned NOT NULL,
    source_username varchar(69) NOT NULL DEFAULT '',
    needs_password_reset tinyint(1) NOT NULL DEFAULT 0,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source_id),
    UNIQUE KEY uq_mcam_target (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE mucho_cvolton_level_map (
    source_id bigint NOT NULL,
    target_id bigint unsigned NOT NULL,
    source_name varchar(255) NOT NULL DEFAULT '',
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source_id),
    UNIQUE KEY uq_mclm_target (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE mucho_level_scores (
    score_id bigint unsigned NOT NULL AUTO_INCREMENT,
    account_id bigint unsigned NOT NULL,
    level_id bigint unsigned NOT NULL,
    is_daily tinyint(1) NOT NULL DEFAULT 0,
    daily_id bigint unsigned NOT NULL DEFAULT 0,
    percent tinyint unsigned NOT NULL DEFAULT 0,
    coins tinyint unsigned NOT NULL DEFAULT 0,
    attempts int unsigned NOT NULL DEFAULT 0,
    clicks int unsigned NOT NULL DEFAULT 0,
    play_time int unsigned NOT NULL DEFAULT 0,
    progresses longtext NOT NULL,
    created_at bigint NOT NULL,
    updated_at bigint NOT NULL,
    PRIMARY KEY (score_id),
    UNIQUE KEY uq_mucho_level_score_account_level_daily (account_id, level_id, is_daily)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $target->exec(<<<'SQL'
CREATE TABLE mucho_platformer_scores (
    score_id bigint unsigned NOT NULL AUTO_INCREMENT,
    account_id bigint unsigned NOT NULL,
    level_id bigint unsigned NOT NULL,
    time_ms bigint unsigned NOT NULL DEFAULT 0,
    points bigint unsigned NOT NULL DEFAULT 0,
    created_at bigint NOT NULL,
    updated_at bigint NOT NULL,
    PRIMARY KEY (score_id),
    UNIQUE KEY uq_mucho_platformer_score_account_level (account_id, level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $validPassword = password_hash('migration-test-password', PASSWORD_DEFAULT);
    $stmt = $source->prepare(
        'INSERT INTO accounts (accountID,userName,password,gjp2,email,isActive)
         VALUES (:id,:name,:password,:gjp2,:email,1)'
    );
    $stmt->execute([
        'id' => 1,
        'name' => 'Migrator',
        'password' => $validPassword,
        'gjp2' => sha1('migration-test-password' . 'GJP2'),
        'email' => 'migrator@example.test',
    ]);
    $stmt->execute([
        'id' => 2,
        'name' => 'NeedsReset',
        'password' => 'legacy-unsupported',
        'gjp2' => null,
        'email' => 'reset@example.test',
    ]);

    $user = $source->prepare(
        'INSERT INTO users
        (extID,userName,stars,moons,diamonds,coins,userCoins,demons,creatorPoints,
         icon,iconType,color1,color2,accGlow,isBanned)
        VALUES
        (:ext,:name,:stars,:moons,:diamonds,:coins,:user_coins,:demons,:cp,
         :icon,:icon_type,:color1,:color2,:glow,:banned)'
    );
    foreach ([
        [
            'ext' => '1',
            'name' => 'Migrator',
            'stars' => 123,
            'moons' => 4,
            'diamonds' => 99,
            'coins' => 12,
            'user_coins' => 42,
            'demons' => 7,
            'cp' => 3,
            'icon' => 5,
            'icon_type' => 2,
            'color1' => 6,
            'color2' => 9,
            'glow' => 1,
            'banned' => 0,
        ],
        [
            'ext' => '2',
            'name' => 'NeedsReset',
            'stars' => 1,
            'moons' => 2,
            'diamonds' => 3,
            'coins' => 4,
            'user_coins' => 5,
            'demons' => 0,
            'cp' => 0,
            'icon' => 1,
            'icon_type' => 0,
            'color1' => 0,
            'color2' => 3,
            'glow' => 0,
            'banned' => 0,
        ],
    ] as $row) {
        $user->execute($row);
    }

    $source->exec(<<<'SQL'
INSERT INTO levels
(levelID,gameVersion,binaryVersion,levelName,levelDesc,levelVersion,levelLength,
 audioTrack,original,twoPlayer,songID,objects,coins,requestedStars,levelString,
 starDifficulty,downloads,likes,starDemon,starAuto,starStars,starFeatured,starEpic,
 starDemonDiff,extID,unlisted,isDeleted,isLDM,wt,wt2,ts,songIDs,sfxIDs)
VALUES
(101,22,4,'Integration Level','Migrated level',1,2,1,0,0,0,100,3,7,'1,1,1',
 20,11,12,0,0,5,1,0,0,'1',0,0,0,10,20,30,'','')
SQL);

    $source->exec(
        "INSERT INTO levelscores
        (scoreID,accountID,levelID,percent,uploadDate,attempts,coins,clicks,time,progresses,dailyID)
        VALUES (501,1,101,87,1700000000,10,3,400,55,'87',0)"
    );
    $source->exec(
        "INSERT INTO platscores
        (ID,accountID,levelID,time,points,timestamp)
        VALUES (601,1,101,12345,77,1700000001)"
    );

    mkdir($fixtureRoot, 0700, true);

    $sharedBackupDir = $fixtureRoot . '/shared-backups';
    $sharedBackup = (new DatabaseBackupService(
        $target,
        $sharedBackupDir
    ))->create('shared-integration');

    must(is_file($sharedBackup['file']), 'Shared PHP backup file was not created.');
    must(is_file($sharedBackup['file'] . '.sha256'), 'Shared PHP backup checksum was not created.');
    must((int)$sharedBackup['size'] >= 100, 'Shared PHP backup is unexpectedly small.');
    must(
        hash_file('sha256', $sharedBackup['file']) === $sharedBackup['sha256'],
        'Shared PHP backup checksum does not match.'
    );
    $gzip = gzopen($sharedBackup['file'], 'rb');
    must($gzip !== false, 'Shared PHP backup cannot be reopened as gzip.');
    $sample = $gzip !== false ? gzread($gzip, 4096) : false;
    if ($gzip !== false) {
        gzclose($gzip);
    }
    must(is_string($sample) && str_contains($sample, 'MuchoCore database backup'), 'Shared PHP backup header is invalid.');

    file_put_contents(
        $fixtureRoot . '/.env',
        "DB_HOST=$host\nDB_PORT=$port\nDB_NAME=$targetDb\nDB_USER=root\n"
    );
    file_put_contents(
        $runtimeEnv,
        "DB_HOST=$host\nDB_PORT=$port\nDB_NAME=$targetDb\nDB_USER=root\nDB_PASS=$rootPassword\n"
    );

    $cli = runCli(
        $root,
        $host,
        $port,
        $sourceDb,
        $rootPassword,
        $fixtureRoot,
        $runtimeEnv
    );

    must($cli['code'] === 0, "CLI migration failed:\n" . $cli['output']);
    must(str_contains($cli['output'], 'TARGET_BACKUP='), 'CLI did not report TARGET_BACKUP.');
    preg_match('/TARGET_BACKUP=([^\r\n]+)/', $cli['output'], $matches);
    $backup = $matches[1] ?? '';
    must($backup !== '' && is_file($backup), 'Verified target backup file is missing.');
    must(is_file($backup . '.sha256'), 'Verified target backup checksum is missing.');

    file_put_contents(
        $runtimeEnv,
        "DB_HOST=$host\nDB_PORT=$port\nDB_NAME=$targetDb\nDB_USER=root\nDB_PASS=deliberately-wrong\n"
    );

    $backupFailure = runCli(
        $root,
        $host,
        $port,
        $sourceDb,
        $rootPassword,
        $fixtureRoot,
        $runtimeEnv
    );

    must($backupFailure['code'] !== 0, 'Migration must stop when the verified backup fails.');
    must(
        str_contains($backupFailure['output'], 'Target database backup failed') ||
        str_contains($backupFailure['output'], 'BACKUP'),
        'Backup failure should be clearly reported.'
    );
    must(
        scalar($target, 'SELECT COUNT(*) FROM accounts') === 2,
        'A failed backup must not modify the destination.'
    );

    file_put_contents(
        $runtimeEnv,
        "DB_HOST=$host\nDB_PORT=$port\nDB_NAME=$targetDb\nDB_USER=root\nDB_PASS=$rootPassword\n"
    );

    $preflight = (new CvoltonDatabaseImporter($target))->preflight($source);
    must($preflight['accounts'] === 2, 'Unexpected account preflight count.');
    must($preflight['levels'] === 1, 'Unexpected level preflight count.');
    must($preflight['levelscores'] === 1, 'Unexpected regular score preflight count.');
    must($preflight['platscores'] === 1, 'Unexpected platformer score preflight count.');

    must(scalar($target, 'SELECT COUNT(*) FROM accounts') === 2, 'Expected two migrated accounts.');
    must(scalar($target, 'SELECT COUNT(*) FROM levels') === 1, 'Expected one migrated level.');
    must(scalar($target, 'SELECT COUNT(*) FROM mucho_level_scores') === 1, 'Expected one migrated regular score.');
    must(scalar($target, 'SELECT COUNT(*) FROM mucho_platformer_scores') === 1, 'Expected one migrated platformer score.');
    must(
        scalar($target, 'SELECT COUNT(*) FROM mucho_cvolton_account_map WHERE needs_password_reset=1') === 1,
        'Expected one account requiring password recovery.'
    );

    $levelId = scalar(
        $target,
        'SELECT target_id FROM mucho_cvolton_level_map WHERE source_id=101'
    );
    must($levelId === 101, 'Expected source level ID 101 to be preserved.');

    $gJp2Hash = (string)$target->query(
        "SELECT gjp2_hash FROM accounts WHERE username='Migrator'"
    )->fetchColumn();
    must(
        $gJp2Hash !== '' &&
        password_verify(sha1('migration-test-password' . 'GJP2'), $gJp2Hash),
        'Expected GJP2 credential to be rehashed.'
    );

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

    must($stats['levels_updated'] === 1, 'Expected idempotent level update on second apply.');
    must(scalar($target, 'SELECT COUNT(*) FROM accounts') === 2, 'Idempotent apply changed account count.');

    $source->exec(
        "INSERT INTO accounts (accountID,userName,password,gjp2,email,isActive)
         VALUES (3,'ConflictUser','unsupported','', 'migrator@example.test',1)"
    );
    $source->exec(
        "INSERT INTO users (extID,userName)
         VALUES ('3','ConflictUser')"
    );

    $before = scalar($target, 'SELECT COUNT(*) FROM accounts');
    $target->beginTransaction();
    $failed = false;

    try {
        (new CvoltonDatabaseImporter($target))->apply($source);
        $target->commit();
    } catch (Throwable $e) {
        $failed = true;
        if ($target->inTransaction()) {
            $target->rollBack();
        }
    }

    must($failed, 'Expected conflicting source account to abort migration.');
    must(
        scalar($target, 'SELECT COUNT(*) FROM accounts') === $before,
        'Failed migration must roll back destination changes.'
    );

    $source->exec('ALTER TABLE levels DROP COLUMN levelString');
    $schemaFailed = false;

    try {
        (new CvoltonDatabaseImporter($target))->preflight($source);
    } catch (Throwable $e) {
        $schemaFailed = str_contains($e->getMessage(), 'levelString');
    }

    must($schemaFailed, 'Preflight must reject an incompatible source schema before apply.');

    echo "cvolton-migration-integration: OK\n";
} finally {
    @unlink($runtimeEnv);
    if (is_dir($fixtureRoot)) {
        @exec('rm -rf ' . escapeshellarg($fixtureRoot));
    }

    try {
        $server = pdoRoot($host, $port, $rootPassword);
        $server->exec('DROP DATABASE IF EXISTS ' . $targetDb);
        $server->exec('DROP DATABASE IF EXISTS ' . $sourceDb);
    } catch (Throwable) {
        // Preserve the original test failure if cleanup cannot connect.
    }
}
