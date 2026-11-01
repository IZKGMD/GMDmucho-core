<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function pdH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pdFormatNumber(mixed $value): string
{
    return number_format((int)$value);
}

function captureEvents(PDO $db, ?array $account): string
{
    ob_start();
    try {
        require dirname(__DIR__, 2) . '/public/dashboard/events-panel.php';
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function checkEvents(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE mucho_creator_plugin (id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL)');
$db->exec('CREATE TABLE mucho_creator_events (
    id INTEGER PRIMARY KEY, title TEXT NOT NULL, rules TEXT NOT NULL,
    deadline TEXT NOT NULL, state TEXT NOT NULL, min_judges INTEGER NOT NULL
)');
$db->exec('CREATE TABLE mucho_creator_entries (
    id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
    level_id INTEGER NOT NULL, level_name TEXT NOT NULL, disqualified INTEGER NOT NULL,
    result_rank INTEGER, result_score REAL
)');
$db->exec('CREATE TABLE mucho_news_posts (
    id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL,
    external_url TEXT, is_published INTEGER NOT NULL, is_pinned INTEGER NOT NULL,
    published_at TEXT
)');

$db->exec('INSERT INTO mucho_creator_plugin(id, enabled) VALUES(1, 0)');
$off = captureEvents($db, null);
checkEvents(str_contains($off, "Events haven't opened yet."), 'Disabled state missing');
checkEvents(!str_contains($off, 'href="/extensions/creator-events"'), 'Disabled plugin must not offer a dead event link');

$db->exec('UPDATE mucho_creator_plugin SET enabled=1');
$empty = captureEvents($db, null);
checkEvents(str_contains($empty, 'The calendar is clear'), 'Empty event state missing');

$db->exec("INSERT INTO mucho_creator_events
    (id,title,rules,deadline,state,min_judges)
    VALUES
    (10,'Genesis <script>alert(1)</script>','Build a new level','2099-06-20 12:00:00','open',2),
    (11,'Rating finals','The final stage','2020-01-01 00:00:00','published',3),
    (12,'Recently closed','Review pending','2020-01-01 00:00:00','open',1)");
$db->exec("INSERT INTO mucho_creator_entries
    (id,event_id,account_id,level_id,level_name,disqualified,result_rank,result_score)
    VALUES
    (1,10,5,12345,'My Level',0,NULL,NULL),
    (2,11,5,12346,'Final Level',0,1,27.50)");
$db->exec("INSERT INTO mucho_news_posts
    (id,title,body,external_url,is_published,is_pinned,published_at)
    VALUES
    (1,'Update <script>alert(2)</script>','Body',NULL,1,1,'2026-11-01 12:00:00')");
$html = captureEvents($db, ['id'=>5, 'username'=>'Creator']);
checkEvents(str_contains($html, 'Genesis &lt;script&gt;alert(1)&lt;/script&gt;'), 'Event title must be escaped');
checkEvents(!str_contains($html, '<script>alert(1)</script>'), 'Raw HTML injection in event title');
checkEvents(str_contains($html, 'Update &lt;script&gt;alert(2)&lt;/script&gt;'), 'News title must be escaped');
checkEvents(str_contains($html, 'data-me-state="live"'), 'Live contest state missing');
checkEvents(str_contains($html, 'data-me-state="results"'), 'Published contest state missing');
checkEvents(str_contains($html, 'data-me-state="closed"'), 'Expired open contest must be closed');
checkEvents(str_contains($html, 'Your entry: My Level'), 'Signed-in user entry missing');
checkEvents(str_contains($html, 'Rank #1'), 'Published result rank missing');
checkEvents(str_contains($html, '/extensions/creator-events#event-10'), 'Contest anchor missing');
checkEvents(str_contains($html, 'Closes in') || str_contains($html, 'data-me-deadline='), 'Live deadline missing');

$guest = captureEvents($db, null);
checkEvents(!str_contains($guest, 'Your entry: My Level'), 'Guest must not see a private entry label');

echo "MuchoGDPS events dashboard: OK\n";
