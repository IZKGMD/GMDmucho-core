<?php
declare(strict_types=1);

use MuchoCore\Demonlist\DemonlistService;
use MuchoCore\Migration\CvoltonContentImporter;

$root = dirname(__DIR__,2);
$compiled = in_array('--compiled', $argv, true);
require $root . ($compiled ? '/compat/php7/domain.php' : '/vendor/autoload.php');
$host = getenv('TEST_DB_HOST');
$pass = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || !$pass) {
    throw new RuntimeException('Requires a disposable MariaDB instance on loopback.');
}
$dsn = 'mysql:host=127.0.0.1;port=' . (int)(getenv('TEST_DB_PORT') ?: 3306) . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn, 'root', $pass, $options);
$name = 'mc_content_' . bin2hex(random_bytes(6));
$targetName = $name . '_target';
$sourceName = $name . '_source';

function verifyContent($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $server->exec('CREATE DATABASE '.$sourceName);
    $server->exec('CREATE DATABASE '.$targetName);
    $source = new PDO($dsn.';dbname='.$sourceName, 'root', $pass, $options);
    $target = new PDO($dsn.';dbname='.$targetName, 'root', $pass, $options);

    $target->exec('CREATE TABLE accounts (account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(20),is_active INT,is_banned INT)');
    $target->exec("INSERT INTO accounts VALUES (33,'Creator',1,0),(34,'Runner',1,0)");
    $target->exec('CREATE TABLE levels (level_id INT PRIMARY KEY,account_id INT,name VARCHAR(20),demon INT,is_deleted INT,is_unlisted INT,unlisted2 INT)');
    $target->exec("INSERT INTO levels VALUES
        (3,33,'Destination',0,0,0,0),(103,33,'Imported',1,0,0,0),(104,33,'Stage 2',1,0,0,0),
        (105,33,'Stage 3',1,0,0,0),(106,33,'Stage 4',1,0,0,0),(107,33,'Stage 5',1,0,0,0),(108,33,'Private',1,0,1,0)");
    $target->exec('CREATE TABLE mucho_cvolton_account_map (source_id INT PRIMARY KEY,target_id INT)');
    $target->exec('CREATE TABLE mucho_cvolton_level_map (source_id INT PRIMARY KEY,target_id INT)');
    $target->exec('INSERT INTO mucho_cvolton_account_map VALUES (1,33)');
    $target->exec('INSERT INTO mucho_cvolton_level_map VALUES (3,103),(4,104),(5,105),(6,106),(7,107),(8,108)');
    foreach (['012_interactions.php','20260924_003_v71_runtime_schema.php','20260929_001_content_packs.php',
        '20261020_001_gameplay_rotations_rewards.php','20261124_001_cvolton_content_map.php',
        '20261124_002_demonlist.php','20261124_003_demonlist_review.php'] as $file) {
        $file = $root.'/database/migrations/'.$file;
        if (basename($file) === '20261124_003_demonlist_review.php') {
            $target->exec("INSERT INTO mucho_demonlist_records (account_id,level_id,video_id,status) VALUES (33,107,'abcdefghijk','approved')");
        }
        $migration = require ($compiled ? mucho7_compiled_include($file) : $file);
        if (is_array($migration)) {
            foreach ($migration as $sql) {
                $target->exec($sql);
            }
        } else {
            $migration($target);
        }
    }

    $source->exec('CREATE TABLE users (userID INT PRIMARY KEY,extID VARCHAR(40))');
    $source->exec("INSERT INTO users VALUES (123,'1'),(125,'guest')");
    $source->exec('CREATE TABLE comments (commentID INT PRIMARY KEY,userID INT,levelID INT,comment TEXT,timestamp INT,likes INT,isSpam INT,percent INT)');
    $source->exec("INSERT INTO comments VALUES (1,123,3,'aGVsbG8=',1700000000,-2,0,71),(2,125,3,'aGk=',1700000000,0,0,0)");
    $source->exec('CREATE TABLE acccomments (commentID INT PRIMARY KEY,userID INT,comment TEXT,timestamp INT)');
    $source->exec("INSERT INTO acccomments VALUES (50,123,'cHJvZmlsZQ==',1700000000)");
    $source->exec('CREATE TABLE lists (listID INT PRIMARY KEY,accountID INT,listName VARCHAR(100),listlevels TEXT)');
    $source->exec("INSERT INTO lists VALUES (1,1,'Published','3,4'),(2,1,'Private level','8')");
    $source->exec('CREATE TABLE gauntlets (ID INT PRIMARY KEY,level1 INT,level2 INT,level3 INT,level4 INT,level5 INT)');
    $source->exec('INSERT INTO gauntlets VALUES (23,3,4,5,6,7)');
    $source->exec('CREATE TABLE mappacks (ID INT PRIMARY KEY,name VARCHAR(64),levels TEXT,stars INT,coins INT,difficulty INT)');
    $source->exec("INSERT INTO mappacks VALUES (2,'Pack','5,4,3',5,3,2)");
    $source->exec('CREATE TABLE dailyfeatures (feaID INT PRIMARY KEY,levelID INT,timestamp INT,type INT)');
    $source->exec('INSERT INTO dailyfeatures VALUES (3,3,1700000000,0),(4,4,1700000000,1),(5,5,1700000000,2)');

    $migration = new CvoltonContentImporter($target);
    verifyContent($migration->preflight($source)['comments'] === 2, 'Preview missed original comments.');
    $target->beginTransaction();
    $stats = $migration->apply($source);
    $target->commit();
    verifyContent($stats['comments_imported'] === 1 && $stats['comments_skipped'] === 1 && $stats['acccomments_imported'] === 1, 'Comment counts or legacy author mapping.');
    verifyContent($target->query('SELECT content FROM comments WHERE account_id=33 AND level_id=103 AND percent=71 AND likes=-2')->fetchColumn() === 'hello', 'Remapped comment content.');
    verifyContent($target->query('SELECT level_ids FROM mucho_level_lists')->fetchColumn() === '103,104' && $stats['lists_skipped'] === 1, 'Visibility and ordered list mapping.');
    verifyContent($target->query('SELECT levels FROM mucho_map_packs')->fetchColumn() === '105,104,103', 'Map pack order or level mapping.');
    verifyContent((int)$target->query('SELECT level5 FROM mucho_gauntlets')->fetchColumn() === 107, 'Gauntlet level mapping.');
    verifyContent($stats['dailyfeatures_imported'] === 3, 'Daily/Weekly/Event imports.');
    $target->beginTransaction();
    $retry = $migration->apply($source);
    $target->commit();
    verifyContent($retry['comments_already_imported'] === 1 && $retry['dailyfeatures_already_imported'] === 3, 'Repeat migration duplicated content.');
    verifyContent((int)$source->query('SELECT COUNT(*) FROM comments')->fetchColumn() === 2, 'Source database changed.');

    $list = new DemonlistService($target);
    verifyContent($list->playerRecord(33,107) === ['status'=>'approved','decision_note'=>''], 'Schema upgrade changed an existing record.');
    $target->exec('DELETE FROM mucho_demonlist_records WHERE level_id=107');
    $list->saveLevel(103, 1, true);
    verifyContent(count($list->levels()) === 1, 'Published level missing.');
    try {
        $list->saveLevel(104, 1, true);
        throw new RuntimeException('Two Demons occupied the same position.');
    } catch (InvalidArgumentException $e) {
        verifyContent((int)$list->levels()[0]['level_id'] === 103, 'Position conflict overwrote the ranked level.');
    }
    $proof = 'https://youtu.be/abcdefghijk';
    verifyContent(DemonlistService::videoId('https://youtube.invalid/watch?v=abcdefghijk') === null, 'Untrusted proof hostname accepted.');
    verifyContent($list->submit(34,103,$proof) && !$list->submit(34,103,$proof), 'Duplicate record submission.');
    verifyContent($list->records(103) === [], 'Unreviewed record published.');
    $pending = $list->pending()[0];
    $id = (int)$pending['id'];
    $revision = (int)$pending['revision'];
    verifyContent($list->review($id,$revision,1,'reject','Show the start of the run.'), 'Pending run could not be rejected.');
    verifyContent($list->playerRecord(34,103)['decision_note'] === 'Show the start of the run.', 'Player did not receive review feedback.');
    $newProof = 'https://youtu.be/12345678901';
    verifyContent($list->submit(34,103,$newProof), 'Rejected record cannot be resubmitted.');
    verifyContent(!$list->review($id,$revision,1,'approve',''), 'Stale approval applied to a replacement video.');
    $newRevision = (int)$list->pending()[0]['revision'];
    verifyContent($newRevision > $revision && $list->playerRecord(34,103)['decision_note'] === '', 'Replacement retained stale review state.');
    verifyContent($list->review($id,$newRevision,1,'approve','') && !$list->review($id,$newRevision,1,'reject',''), 'Review outcome was applied twice.');
    verifyContent($list->records(103)[0]['username'] === 'Runner', 'Approved record missing.');
    $approved = $list->approvedForReview(103,0)[0];
    verifyContent($approved['video_id'] === '12345678901' && $list->approvedForReview(103,$id) === [], 'Approved review list did not return the correct proof or cursor.');
    verifyContent($list->review($id,$newRevision,1,'revoke','Recording is no longer available.'), 'Approved record could not be removed.');
    verifyContent($list->records(103) === [] && $list->approvedForReview(103,0) === [], 'Revoked record remained approved.');
    verifyContent($list->submit(34,103,$newProof) && !$list->review($id,$newRevision,1,'approve',''), 'Resubmitting the same URL reused its old review revision.');
    $latest = (int)$list->pending()[0]['revision'];
    verifyContent($list->review($id,$latest,1,'approve',''), 'Current record could not be approved.');
    $target->exec('UPDATE levels SET is_unlisted=1 WHERE level_id=103');
    verifyContent($list->levels() === [] && $list->records(103) === [] && !$list->submit(33,103,$proof), 'Hidden level remained public.');
    echo "MUCHOCORE_CVOLTON_CONTENT_AND_DEMONLIST_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS '.$sourceName);
    $server->exec('DROP DATABASE IF EXISTS '.$targetName);
}
