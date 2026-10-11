<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Community/PublicVisibility.php';
use MuchoCore\Community\PublicVisibility;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE levels (id INTEGER PRIMARY KEY, is_deleted INTEGER, is_unlisted INTEGER, unlisted2 INTEGER)');
$db->exec('INSERT INTO levels VALUES (1,0,0,0),(2,1,0,0),(3,0,1,0),(4,0,0,1)');
$columns = ['id', 'is_deleted', 'is_unlisted', 'unlisted2'];
$visible = $db->query('SELECT l.id FROM levels l' . PublicVisibility::where('levels', $columns, 'l'))->fetchAll(PDO::FETCH_COLUMN);
if ($visible !== [1]) throw new RuntimeException('Public levels include deleted or unlisted content.');
$count = $db->query('SELECT COUNT(*) FROM levels' . PublicVisibility::where('levels', $columns))->fetchColumn();
if ((int)$count !== 1) throw new RuntimeException('Public counts include hidden levels.');
$db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, is_active INTEGER, is_banned INTEGER)');
$db->exec('INSERT INTO accounts VALUES (1,1,0),(2,0,0),(3,1,1)');
$visible = $db->query('SELECT id FROM accounts' . PublicVisibility::where('accounts', ['is_active', 'is_banned']))->fetchAll(PDO::FETCH_COLUMN);
if ($visible !== [1]) throw new RuntimeException('Public counts include banned or inactive accounts.');
if (PublicVisibility::where('levels', ['id']) !== '') throw new RuntimeException('Legacy column compatibility failed.');
try {
    PublicVisibility::where('levels', $columns, 'l; DROP TABLE accounts');
    throw new RuntimeException('Invalid SQL alias accepted.');
} catch (InvalidArgumentException) {}
echo "PASS public overview excludes hidden content and disabled accounts\n";
