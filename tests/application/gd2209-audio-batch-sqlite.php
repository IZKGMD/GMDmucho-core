<?php

declare(strict_types=1);

/**
 * Isolated, real-SQL regression for GD 2.209's getGJAudioFilesize.
 *
 * NitroCore's batch music-provider design is a reference concept only.
 * This implementation and test use MuchoCore's own PDO/schema/contracts.
 */
require_once dirname(__DIR__, 2) . '/src/Account/AccountAuthenticator.php';
require_once dirname(__DIR__, 2) . '/src/Compatibility/Gd2209UtilityService.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Compatibility\Gd2209UtilityService;

final class CountingAudioPDO extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->prepares;
        return parent::prepare($query, $options);
    }
}

function audioCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('GD2209 audio batch: ' . $description);
    }
}

function expectAudioFailure(Gd2209UtilityService $service, string $songs, string $sounds): void
{
    try {
        $service->audioSizes($songs, $sounds);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Invalid audio sizes accepted: ' . $songs . '#' . $sounds);
}

$db = new CountingAudioPDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('CREATE TABLE songs (id INTEGER PRIMARY KEY, size REAL, is_verified INTEGER NOT NULL)');
$db->exec('CREATE TABLE mucho_editor_sounds (id INTEGER PRIMARY KEY, size_bytes INTEGER NOT NULL)');
$db->exec('INSERT INTO songs VALUES (7,1.5,1),(9,2,1),(10,0,1),(11,1,0),(12,10001,1)');
$db->exec('INSERT INTO mucho_editor_sounds VALUES (23,1200),(24,3400),(25,0)');

$service = new Gd2209UtilityService($db, new AccountAuthenticator($db));
$start = $db->prepares;
audioCheck(
    $service->audioSizes('9,7,9', '24,23,24') === '9:2097152,7:1572864#24:3400,23:1200',
    'deduplication, request order, MiB conversion, and exact response framing'
);
audioCheck($db->prepares - $start === 2, 'one SQL statement per nonempty family');

$start = $db->prepares;
audioCheck($service->audioSizes('7', '') === '7:1572864#', 'song-only output');
audioCheck($db->prepares - $start === 1, 'one SQL statement for song-only');
$start = $db->prepares;
audioCheck($service->audioSizes('', '23') === '#23:1200', 'editor-sound-only output');
audioCheck($db->prepares - $start === 1, 'one SQL statement for sound-only');

foreach ([['', ''], ['bad', '23'], ['7,0', ''], ['10', ''], ['11', ''],
         ['12', ''], ['9999', ''], ['', '25'], ['', '9999']] as $invalid) {
    expectAudioFailure($service, $invalid[0], $invalid[1]);
}

$songs = range(100, 163);
$sounds = range(200, 263);
$insertSong = $db->prepare('INSERT INTO songs(id,size,is_verified) VALUES(?,1,1)');
$insertSound = $db->prepare('INSERT INTO mucho_editor_sounds(id,size_bytes) VALUES(?,256)');
foreach ($songs as $id) { $insertSong->execute([$id]); }
foreach ($sounds as $id) { $insertSound->execute([$id]); }
$start = $db->prepares;
$result = $service->audioSizes(implode(',', $songs), implode(',', $sounds));
audioCheck($db->prepares - $start === 2, '128 IDs require only two SQL statements');
[$songBody, $soundBody] = explode('#', $result, 2);
audioCheck(count(explode(',', $songBody)) === 64, '64 song results preserved');
audioCheck(count(explode(',', $soundBody)) === 64, '64 sound results preserved');
audioCheck(str_starts_with($songBody, '100:1048576,101:1048576'), 'song order preserved');
audioCheck(str_ends_with($soundBody, '262:256,263:256'), 'sound order preserved');

$start = $db->prepares;
expectAudioFailure($service, implode(',', range(100, 164)), '');
audioCheck($db->prepares === $start, '65 IDs rejected before querying');

echo "MUCHOCORE_GD2209_AUDIO_BATCH_OK\n";
