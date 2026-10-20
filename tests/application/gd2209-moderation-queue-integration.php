<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Cache\NullCache;
use MuchoCore\Http\Request;
use MuchoCore\Level\LevelController;
use MuchoCore\Level\LevelRepository;
use MuchoCore\Level\LevelService;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Protocol\GdLevelListEncoder;

// Public level-list cache must depend on gameplay filters and wire version,
// never on identifiers carried by a client's otherwise identical request.
final class LevelPublicCacheFixture implements \MuchoCore\Cache\CacheInterface
{
    public array $entries = [];
    public int $hits = 0;
    public int $writes = 0;

    public function get(string $key): ?string
    {
        $value = $this->entries[$key] ?? null;
        if ($value !== null) {
            ++$this->hits;
        }
        return $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->entries[$key] = $value;
        ++$this->writes;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function deletePrefix(string $prefix): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->entries[$key]);
            }
        }
    }
}

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Moderation queue test requires isolated MariaDB.');
}

$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($db ? ';dbname=' . $db : '') . ';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$assert = static function (bool $ok, string $why): void {
    if (!$ok) {
        throw new RuntimeException('GD 2.209 moderation queue: ' . $why);
    }
};
$dbName = 'mc_modqueue209_' . bin2hex(random_bytes(5));
$server = $connect(null);

try {
    $server->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4');
    $db = $connect($dbName);
    $db->exec("CREATE TABLE roles (id INT PRIMARY KEY, code VARCHAR(32) NOT NULL)");
    $db->exec("INSERT INTO roles VALUES (1,'user'),(2,'moderator'),(3,'owner')");
    $db->exec("CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        role_id INT NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE songs (
        id BIGINT UNSIGNED PRIMARY KEY,
        is_verified TINYINT NOT NULL DEFAULT 1,
        name VARCHAR(128),
        author_id BIGINT,
        author_name VARCHAR(128),
        size DECIMAL(10,2),
        download_url TEXT,
        youtube_video_id VARCHAR(128),
        youtube_channel_id VARCHAR(128)
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE levels (
        level_id BIGINT UNSIGNED PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(128) NOT NULL,
        is_deleted TINYINT NOT NULL DEFAULT 0,
        is_unlisted TINYINT NOT NULL DEFAULT 0,
        stars TINYINT NOT NULL DEFAULT 0,
        requested_stars TINYINT NOT NULL DEFAULT 0,
        game_version INT NOT NULL DEFAULT 22,
        level_version INT NOT NULL DEFAULT 1,
        difficulty INT NOT NULL DEFAULT 3,
        downloads INT NOT NULL DEFAULT 0,
        audio_track INT NOT NULL DEFAULT 0,
        likes INT NOT NULL DEFAULT 0,
        demon TINYINT NOT NULL DEFAULT 0,
        demon_difficulty INT NOT NULL DEFAULT 0,
        auto_level TINYINT NOT NULL DEFAULT 0,
        featured TINYINT NOT NULL DEFAULT 0,
        epic TINYINT NOT NULL DEFAULT 0,
        object_count INT NOT NULL DEFAULT 0,
        description TEXT,
        length INT NOT NULL DEFAULT 2,
        original_level_id BIGINT NOT NULL DEFAULT 0,
        two_player TINYINT NOT NULL DEFAULT 0,
        coins TINYINT NOT NULL DEFAULT 0,
        coins_verified TINYINT NOT NULL DEFAULT 0,
        ldm TINYINT NOT NULL DEFAULT 0,
        song_id BIGINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE moderation_suggestions (
        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL,
        level_id BIGINT UNSIGNED NOT NULL,
        stars INT NOT NULL,
        feature_tier INT NOT NULL DEFAULT 0,
        coins_verified TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_level (level_id),
        INDEX idx_account (account_id)
    ) ENGINE=InnoDB");

    foreach ([1 => 2, 2 => 2, 3 => 1] as $id => $role) {
        $stmt = $db->prepare("INSERT INTO accounts
            (account_id,username,role_id,password_hash,gjp2_hash)
            VALUES (:id,:username,:role,:password,:gjp)");
        $stmt->execute([
            'id' => $id,
            'username' => 'User' . $id,
            'role' => $role,
            'password' => password_hash('pw' . $id, PASSWORD_DEFAULT),
            'gjp' => password_hash('gjp' . $id, PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id' => $id]);
    }

    $db->exec("INSERT INTO levels(level_id,account_id,name,stars,is_deleted,is_unlisted)
        VALUES
        (100,1,'Pending Alpha',0,0,0),
        (101,1,'Rated Beta',5,0,0),
        (102,2,'No Suggestion',0,0,0),
        (103,2,'Deleted Gamma',0,1,0),
        (104,2,'Pending Delta',0,0,0),
        (105,2,'Private Epsilon',0,0,1)");
    $db->exec("INSERT INTO moderation_suggestions(level_id,account_id,stars)
        VALUES (100,1,6),(100,2,9),(101,1,5),(103,1,8),(104,2,3),(105,2,2)");

    $auth = new AccountAuthenticator($db);
    $service = new LevelService(
        new LevelRepository($db), new GdLevelListEncoder(), new NullCache()
    );
    $ctrl = new LevelController($service, $auth, new ModerationRepository($db));

    $request = static function(int $type, int $id, string $credential, string $query = ''): Request {
        return new Request('POST', '/database/getGJLevels21.php', [], [
            'type' => (string)$type,
            'accountID' => (string)$id,
            'gjp2' => $credential,
            'str' => $query,
            'gameVersion' => '22',
            'binaryVersion' => '51',
        ], []);
    };

    // Role and password checks must happen even before checking for an empty queue.
    $assert($ctrl->list($request(8,3,'pw3'))->body === '-1',
        'regular accounts cannot inspect advisor queue');
    $assert($ctrl->list($request(8,1,'wrong'))->body === '-1',
        'spoofed moderator account rejected');
    $assert($ctrl->list($request(27,3,'pw3'))->body === '-1',
        'regular account cannot inspect sent proposals');
    $assert($ctrl->list($request(27,2,'pw1'))->body === '-1',
        'other advisor cannot impersonate sender');

    $queue = $ctrl->list($request(8,1,'pw1'))->body;
    $assert($queue !== '-1' && $queue !== '-2', 'moderator queue loads');
    $assert(str_contains($queue,'1:100:2:Pending Alpha'), 'pending suggestion included');
    $assert(str_contains($queue,'1:104:2:Pending Delta'), 'other moderator pending suggestion included');
    $assert(!str_contains($queue, '1:101:2:Rated Beta'), 'already-rated suggestion not queued');
    $assert(!str_contains($queue, '1:103:2:Deleted Gamma'), 'deleted level excluded');
    $assert(!str_contains($queue, '1:105:2:Private Epsilon'), 'unlisted level excluded');
    $assert(!str_contains($queue, '1:102:2:No Suggestion'), 'unsubmitted level excluded');
    $assert(str_contains($queue, ':39:9:'), 'newest requested star count reflected');
    $assert(str_contains($queue, '#2:0:10#'), 'queue correct total/offset/page size');
    $assert(str_contains($queue, ':62:') && str_contains($queue, ':63:'),
        '2.209 responses include exact upload and update Unix timestamps');
    $oldRequest = $request(8,1,'pw1');
    $oldPost = $oldRequest->post;
    $oldPost['binaryVersion'] = '47';
    $oldResponse = $ctrl->list(new Request('POST', $oldRequest->path,
        [], $oldPost, []))->body;
    $assert(!str_contains($oldResponse, ':62:') && !str_contains($oldResponse, ':63:'),
        '2.2081 response excludes new level time fields');


    $sent1 = $ctrl->list($request(27,1,'pw1'))->body;
    $assert(str_contains($sent1,'1:100:2:Pending Alpha'), 'advisor sees own submitted level');
    $assert(str_contains($sent1, ':39:6:'), 'advisor sees own stars, not other advisor latest suggestion');
    $assert(str_contains($sent1,'1:101:2:Rated Beta'), 'sent history keeps rated entries');
    $assert(!str_contains($sent1,'1:104:2:Pending Delta'), 'advisor cannot see other sent list');
    $assert(str_contains($sent1, '#2:0:10#'), 'sender total respects account boundary');

    $sent2 = $ctrl->list($request(27,2,'pw2'))->body;
    $assert(str_contains($sent2,'1:100:2:Pending Alpha'), 'second sender own history included');
    $assert(str_contains($sent2,'1:104:2:Pending Delta'), 'second sender own separate level');
    $assert(!str_contains($sent2,'1:101:2:Rated Beta'), 'first sender rated history not visible');

    $search = $ctrl->list($request(8,1,'pw1','104'))->body;
    $assert(str_contains($search, '1:104:2:Pending Delta'), 'queue numeric level-ID filtering');
    $assert(!str_contains($search, '1:100:2:Pending Alpha'), 'queue filter excludes other IDs');
    $empty = $ctrl->list($request(8,1,'pw1','does not exist'))->body;
    $assert($empty === '-2', 'empty queue search stays no-results response');

    // Two anonymous clients requesting the same public level must reuse the
    // cache despite transport-only fields, input order and whitespace.
    $cache = new LevelPublicCacheFixture();
    $publicService = new LevelService(
        new LevelRepository($db), new GdLevelListEncoder(), $cache
    );
    $firstInput = [
        'type' => '0', 'page' => '0', 'str' => '100',
        'gameVersion' => '22', 'binaryVersion' => '51',
        'udid' => 'device-one', 'uuid' => 'client-one',
        'gjp2' => 'anonymous-placeholder',
    ];
    $firstList = $publicService->getLevels($firstInput);
    $secondList = $publicService->getLevels([
        'uuid' => 'client-two', 'binaryVersion' => '51',
        'str' => ' 100 ', 'page' => '0',
        'type' => '0', 'gameVersion' => '22',
        'udid' => 'device-two', 'unrelated' => 'ignored',
    ]);
    $assert(
        $firstList !== '-1' && $firstList !== '-2'
        && $firstList === $secondList
        && $cache->writes === 1 && $cache->hits === 1,
        'public search ignores client identity and reuses one cached response'
    );
    $oldWire = $publicService->getLevels([
        'str' => '100', 'type' => '0', 'page' => '0',
        'gameVersion' => '22', 'binaryVersion' => '47',
    ]);
    $assert(
        $oldWire !== $firstList && !str_contains($oldWire, ':62:')
        && $cache->writes === 2,
        'old client cannot receive cached 2.209 timestamp fields'
    );
    $demonOnly = $publicService->getLevels([
        'str' => '100', 'type' => '0', 'page' => '0',
        'gameVersion' => '22', 'binaryVersion' => '51',
        'diff' => '-2',
    ]);
    $assert(
        $demonOnly === '-2' && $cache->writes === 3,
        'changing a gameplay filter never reuses an incompatible cached result'
    );

    // Sparse first-page lookups have a known exact total from the rows.
    // The old implementation always issued a separate COUNT(*) read.
    $levelRepo = new LevelRepository($db);
    $readsCounter = static function () use ($db): int {
        $value = $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM);
        return (int)($value[1] ?? -1);
    };
    $beforeSparse = $readsCounter();
    $one = $levelRepo->search(0, '100', 22, 0, 10);
    $sparseReads = $readsCounter() - $beforeSparse;
    $assert(
        $one['total'] === 1
        && count($one['levels']) === 1
        && $sparseReads === 1,
        'first-page numeric level lookup returns exact total with one SELECT'
    );

    $none = $levelRepo->search(0, 'nonexistent-search-text', 22, 0, 10);
    $assert(
        $none['total'] === 0 && $none['levels'] === [],
        'first-page empty search retains its exact zero total'
    );

    // Full and later pages still require the real total; the wire format
    // must never undercount pages after the sparse COUNT optimization.
    for ($extra = 200; $extra < 212; $extra++) {
        $db->prepare(
            'INSERT INTO levels(level_id,account_id,name,stars,is_deleted,is_unlisted)
             VALUES (:id,1,:name,0,0,0)'
        )->execute(['id'=>$extra, 'name'=>'Extra ' . $extra]);
    }
    $full = $levelRepo->search(0, '', 22, 0, 10);
    $last = $levelRepo->search(0, '', 22, 10, 10);
    $assert(
        $full['total'] === 16 && count($full['levels']) === 10
        && $last['total'] === 16 && count($last['levels']) === 6,
        'full and later pages retain exact totals for Geometry Dash'
    );

    echo "MUCHOCORE_GD_2209_MODERATION_QUEUE_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $dbName);
}
