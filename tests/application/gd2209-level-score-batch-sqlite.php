<?php

declare(strict_types=1);

/**
 * Real SQL proof that the 2.209 level-score leaderboard returns 100 users
 * with one prepared query, preserving wire format, bans and quarantine.
 * This intentionally invokes the read path without creating score fixtures
 * via a forged live game request.
 */
require_once dirname(__DIR__, 2) . '/src/Score/ScoreIntegrity.php';
require_once dirname(__DIR__, 2) . '/src/Protocol/ProtocolText.php';
require_once dirname(__DIR__, 2) . '/src/Account/AccountAuthenticator.php';
require_once dirname(__DIR__, 2) . '/src/Score/LevelScoreController.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Score\LevelScoreController;

final class CountingScorePDO extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->prepares;
        return parent::prepare($query, $options);
    }
}

function leaderboardCheck(bool $value, string $reason): void
{
    if (!$value) {
        throw new RuntimeException('GD 2.209 level score batch: ' . $reason);
    }
}

putenv('MUCHO_ANTICHEAT_QUARANTINE=0');
$_ENV['MUCHO_ANTICHEAT_QUARANTINE'] = '0';

$db = new CountingScorePDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE accounts (
    account_id INTEGER PRIMARY KEY,
    username TEXT NOT NULL,
    is_active INTEGER NOT NULL,
    is_banned INTEGER NOT NULL
)');
$db->exec('CREATE TABLE profiles (
    user_id INTEGER PRIMARY KEY,
    account_id INTEGER UNIQUE,
    cube INTEGER,
    color1 INTEGER,
    color2 INTEGER,
    color3 INTEGER,
    icon_type INTEGER,
    special INTEGER
)');
$db->exec('CREATE TABLE mucho_level_scores (
    score_id INTEGER PRIMARY KEY,
    account_id INTEGER NOT NULL,
    level_id INTEGER NOT NULL,
    is_daily INTEGER NOT NULL,
    percent INTEGER NOT NULL,
    coins INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
)');
$db->exec('CREATE TABLE mucho_score_integrity_events (
    score_type TEXT, score_id INTEGER, status TEXT, risk_score INTEGER
)');

$addAccount = $db->prepare('INSERT INTO accounts VALUES(?,?,?,?)');
$addProfile = $db->prepare('INSERT INTO profiles VALUES(?,?,?,?,?,?,?,?)');
$addScore = $db->prepare('INSERT INTO mucho_level_scores VALUES(?,?,?,?,?,?,?)');
$start = time() - 3600;
for ($id = 1; $id <= 107; ++$id) {
    $username = $id === 1 ? 'A:|#lice' : 'Player' . $id;
    $addAccount->execute([$id,$username,$id === 107 ? 0 : 1,$id === 106 ? 1 : 0]);
    if ($id !== 3) {
        $addProfile->execute([1000+$id,$id,6,11,12,13,2,1]);
    }
    $percent = $id === 1 ? 100 : ($id === 2 ? 88 : ($id === 3 ? 77 : 50));
    if ($id >= 106) { $percent = 100; }
    $addScore->execute([$id,$id,77,0,$percent,2,$start+$id]);
}

$controller = new LevelScoreController($db, new AccountAuthenticator($db));
$method = new ReflectionMethod(LevelScoreController::class, 'leaderboard');
$method->setAccessible(true);
$db->prepares = 0;
$body = $method->invoke($controller, 1, 77, 0, 1);
leaderboardCheck($db->prepares === 1, '100 players must require exactly one prepared SELECT');
leaderboardCheck(count(explode('|', $body)) === 100, 'GD response must contain at most 100 entries');
leaderboardCheck(str_starts_with($body, '1:Alice:2:1001:9:6:10:11:11:12:51:13:14:2:15:1:16:1:3:100:6:1:13:2:42:'),
    'first entry metadata and native field order');
leaderboardCheck(str_contains($body, '1:Player3:2:3:9:1:10:0:11:3:51:0:14:0:15:0:16:3:3:77:6:2:13:2:42:'),
    'missing profile falls back to correct GD defaults without a write');
leaderboardCheck(!str_contains($body, ':16:106:') && !str_contains($body, ':16:107:'),
    'banned or inactive players are excluded before LIMIT');
leaderboardCheck(!str_contains($body, ':16:105:'), 'page is limited to first 100 valid players');
leaderboardCheck((int)$db->query('SELECT COUNT(*) FROM profiles WHERE account_id=3')->fetchColumn() === 0,
    'read-only leaderboard must not create profiles');

$db->exec("INSERT INTO mucho_score_integrity_events VALUES('regular',1,'suspicious',95)");
$_ENV['MUCHO_ANTICHEAT_QUARANTINE'] = '1';
$db->prepares = 0;
$quarantined = $method->invoke($controller, 1, 77, 0, 1);
leaderboardCheck($db->prepares === 1 && !str_contains($quarantined, ':16:1:'),
    'quarantine filters suspected scores before LIMIT');
leaderboardCheck(str_starts_with($quarantined, '1:Player2:'), 'next clean player moves to first rank');

$_ENV['MUCHO_ANTICHEAT_QUARANTINE'] = '0';
$db->exec('UPDATE mucho_level_scores SET updated_at=' . (time() - 700000) . ' WHERE score_id=2');
$db->prepares = 0;
$recent = $method->invoke($controller, 1, 77, 0, 2);
leaderboardCheck($db->prepares === 1 && !str_contains($recent, ':16:2:'),
    'recent-only scores exclude records older than seven days');

echo "MUCHOCORE_GD2209_LEVEL_SCORE_BATCH_SQLITE_OK\n";
