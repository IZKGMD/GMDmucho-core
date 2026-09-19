<?php
declare(strict_types=1);

/*
 * Mucho Profile v1
 * Copyright (C) 2026 IZK
 */

function failMP(string $error, int $code = 400): never {
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => $error
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

function rootMP(): string {
    return '/var/www/mucho-core';
}

function envMP(): array {
    $env = [];

    foreach ([
        rootMP() . '/.env',
        rootMP() . '/.env.local',
        rootMP() . '/config/.env'
    ] as $file) {
        if (!is_file($file)) continue;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && str_ends_with($value, '"')) ||
                    ($value[0] === "'" && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            }

            $env[$key] = $value;
        }
    }

    return $env;
}

function dbMP(): PDO {
    $e = envMP();

    if (!empty($e['DATABASE_URL'])) {
        $url = parse_url($e['DATABASE_URL']);
        if (!$url) failMP('bad_database_url');

        $driver = str_starts_with($url['scheme'] ?? '', 'postgres')
            ? 'pgsql'
            : 'mysql';

        $host = $url['host'] ?? '127.0.0.1';
        $port = $url['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $db   = ltrim($url['path'] ?? '', '/');

        $dsn = "$driver:host=$host;port=$port;dbname=$db";

        if ($driver === 'mysql') {
            $dsn .= ';charset=utf8mb4';
        }

        return new PDO(
            $dsn,
            urldecode($url['user'] ?? ''),
            urldecode($url['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }

    $host = $e['DB_HOST'] ?? $e['MYSQL_HOST'] ?? '127.0.0.1';
    $port = $e['DB_PORT'] ?? $e['MYSQL_PORT'] ?? '3306';
    $name = $e['DB_DATABASE'] ?? $e['DB_NAME'] ?? $e['MYSQL_DATABASE'] ?? '';
    $user = $e['DB_USERNAME'] ?? $e['DB_USER'] ?? $e['MYSQL_USER'] ?? '';
    $pass = $e['DB_PASSWORD'] ?? $e['DB_PASS'] ?? $e['MYSQL_PASSWORD'] ?? '';

    return new PDO(
        "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
}

function installMP(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'pgsql') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mucho_profile_customization (
                account_id BIGINT PRIMARY KEY,
                display_name VARCHAR(32) NOT NULL DEFAULT '',
                status VARCHAR(48) NOT NULL DEFAULT '',
                bio VARCHAR(240) NOT NULL DEFAULT '',
                theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
                theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
                banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
                title VARCHAR(48) NOT NULL DEFAULT '',
                badges TEXT NOT NULL DEFAULT '[]',
                pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
                showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
                favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
                online_visible SMALLINT NOT NULL DEFAULT 1,
                edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mucho_profile_customization (
                account_id BIGINT PRIMARY KEY,
                display_name VARCHAR(32) NOT NULL DEFAULT '',
                status VARCHAR(48) NOT NULL DEFAULT '',
                bio VARCHAR(240) NOT NULL DEFAULT '',
                theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
                theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
                banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
                title VARCHAR(48) NOT NULL DEFAULT '',
                badges TEXT NOT NULL,
                pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
                showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
                favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
                online_visible TINYINT NOT NULL DEFAULT 1,
                edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

function ensureMP(PDO $db, int $id): void {
    $q = $db->prepare(
        'SELECT account_id FROM mucho_profile_customization WHERE account_id=?'
    );
    $q->execute([$id]);

    if ($q->fetchColumn() === false) {
        $q = $db->prepare(
            "INSERT INTO mucho_profile_customization(account_id,badges)
             VALUES(?, '[]')"
        );
        $q->execute([$id]);
    }
}

function cleanMP(string $value, int $max): string {
    $value = trim(str_replace(["\0", "\r"], '', $value));

    return function_exists('mb_substr')
        ? mb_substr($value, 0, $max, 'UTF-8')
        : substr($value, 0, $max);
}

function colorMP(string $value, string $default): string {
    $value = strtoupper(trim($value));

    return preg_match('/^#[0-9A-F]{6}$/', $value)
        ? $value
        : $default;
}

function pinnedMP(string $value): string {
    $result = [];

    foreach (preg_split('/[,\s]+/', trim($value)) ?: [] as $item) {
        if ($item !== '' && ctype_digit($item) && (int)$item > 0) {
            $result[] = (string)(int)$item;
        }

        if (count($result) >= 3) break;
    }

    return implode(',', $result);
}

function profileMP(PDO $db, int $id): array {
    /*
     * Legacy MuchoProfile.dll consumes display_name, title and
     * theme_primary. New clients additionally consume the explicit role,
     * prefix, two colours and the native Geometry Dash moderator badge.
     */
    $q = $db->prepare("
        SELECT
            a.account_id,
            a.username,
            a.role AS account_role,

            mr.role AS mucho_role,
            COALESCE(mr.verified, 0) AS role_verified,

            p.account_id AS customization_id,
            p.display_name,
            p.status,
            p.bio,
            p.theme_primary,
            p.theme_secondary,
            p.banner,
            p.title,
            p.badges,
            p.pinned_levels,
            p.showcase,
            p.favorite_difficulty,
            p.online_visible,
            p.created_at,
            p.updated_at

        FROM accounts a

        LEFT JOIN mucho_account_roles mr
            ON mr.account_id=a.account_id

        LEFT JOIN mucho_profile_customization p
            ON p.account_id=a.account_id

        WHERE a.account_id=?
        LIMIT 1
    ");

    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'ok' => false,
            'error' => 'profile_not_found',
            'account_id' => $id
        ];
    }

    $rawRole = trim((string)($row['mucho_role'] ?? ''));

    if ($rawRole === '' || strtoupper($rawRole) === 'PLAYER') {
        $rawRole = trim((string)($row['account_role'] ?? 'user'));
    }

    $rawRole = strtolower($rawRole);

    $role = match ($rawRole) {
        'owner' => 'OWNER',
        'admin', 'administrator', 'developer' => 'ADMIN',
        'elder', 'elder_mod', 'elder-moderator' => 'ELDER_MOD',
        'moderator', 'mod', 'helper' => 'MOD',
        default => 'PLAYER'
    };

    $style = match ($role) {
        'OWNER' => [
            'prefix' => 'OWNER',
            'primary' => '#FF334F',
            'secondary' => '#A90024',
            'mod_badge' => 2,
            'icon' => 'elder_moderator'
        ],
        'ADMIN' => [
            'prefix' => 'ADMIN',
            'primary' => '#FFD447',
            'secondary' => '#FF8A00',
            'mod_badge' => 2,
            'icon' => 'elder_moderator'
        ],
        'ELDER_MOD' => [
            'prefix' => 'ELDER MOD',
            'primary' => '#7C5CFF',
            'secondary' => '#31D7FF',
            'mod_badge' => 2,
            'icon' => 'elder_moderator'
        ],
        'MOD' => [
            'prefix' => 'MOD',
            'primary' => '#A855F7',
            'secondary' => '#38D9FF',
            'mod_badge' => 1,
            'icon' => 'moderator'
        ],
        default => [
            'prefix' => '',
            'primary' => '#42D9CF',
            'secondary' => '#806EFF',
            'mod_badge' => 0,
            'icon' => 'none'
        ]
    };

    $hasCustomization = !empty($row['customization_id']);
    $customName = trim((string)($row['display_name'] ?? ''));
    $baseName = $customName !== ''
        ? $customName
        : (string)$row['username'];

    $prefix = (string)$style['prefix'];
    $decoratedName = $prefix !== ''
        ? '[' . $prefix . '] ' . $baseName
        : $baseName;

    $customTitle = trim((string)($row['title'] ?? ''));

    /* Avoid "[OWNER] name [OWNER]" from older profile records. */
    if (
        $customTitle !== '' &&
        in_array(
            strtoupper($customTitle),
            ['OWNER', 'ADMIN', 'MOD', 'ELDER', 'ELDER MOD', 'ELDER_MOD'],
            true
        )
    ) {
        $customTitle = '';
    }

    $badges = json_decode((string)($row['badges'] ?? '[]'), true);
    $badges = is_array($badges)
        ? array_values(array_map('strval', array_slice($badges, 0, 8)))
        : [];

    $primary = $role === 'PLAYER'
        ? colorMP((string)($row['theme_primary'] ?? ''), '#42D9CF')
        : (string)$style['primary'];

    $secondary = $role === 'PLAYER'
        ? colorMP((string)($row['theme_secondary'] ?? ''), '#806EFF')
        : (string)$style['secondary'];

    return [
        'ok' => true,
        'account_id' => (int)$row['account_id'],
        'username' => (string)$row['username'],

        /* Backward-compatible fields used by MuchoProfile.dll v1.5. */
        'display_name' => $baseName,
        'title' => $prefix !== '' ? $prefix : $customTitle,
        'theme_primary' => $primary,
        'theme_secondary' => $secondary,

        /* Explicit presentation fields for the richer client profile. */
        'custom_display_name' => $customName,
        'decorated_display_name' => $decoratedName,
        'role' => $role,
        'role_prefix' => $prefix,
        'role_primary' => (string)$style['primary'],
        'role_secondary' => (string)$style['secondary'],
        'role_icon' => (string)$style['icon'],
        'mod_badge' => (int)$style['mod_badge'],
        'verified' => (bool)($row['role_verified'] ?? false),

        'badges' => implode(', ', $badges),
        'badges_list' => $badges,
        'status' => (string)($row['status'] ?? ''),
        'bio' => (string)($row['bio'] ?? ''),
        'banner' => (string)($row['banner'] ?: 'gradient_01'),
        'pinned_levels' => (string)($row['pinned_levels'] ?? ''),
        'showcase' => (string)(
            $row['showcase'] ?: 'stars,demons,creator_points'
        ),
        'favorite_difficulty' =>
            (string)($row['favorite_difficulty'] ?? ''),
        'online_visible' => $hasCustomization
            ? (int)$row['online_visible']
            : 1,
        'created_at' => $row['created_at'] ?: null,
        'updated_at' => $row['updated_at'] ?: null
    ];
}

function issueTokenMP(PDO $db, int $id): string {
    ensureMP($db, $id);

    $token = rtrim(
        strtr(base64_encode(random_bytes(24)), '+/', '-_'),
        '='
    );

    $q = $db->prepare("
        UPDATE mucho_profile_customization
        SET edit_token_hash=?
        WHERE account_id=?
    ");

    $q->execute([
        hash('sha256', $token),
        $id
    ]);

    return $token;
}

function verifyTokenMP(PDO $db, int $id, string $token): bool {
    if ($token === '') return false;

    $q = $db->prepare("
        SELECT edit_token_hash
        FROM mucho_profile_customization
        WHERE account_id=?
    ");

    $q->execute([$id]);
    $hash = (string)($q->fetchColumn() ?: '');

    return $hash !== '' &&
        hash_equals($hash, hash('sha256', $token));
}


$db = dbMP();
installMP($db);


/* ===========================
   COMMAND LINE ADMIN
   =========================== */

if (PHP_SAPI === 'cli') {

    $cmd = $argv[1] ?? '--help';

    if ($cmd === '--install') {
        echo "Mucho Profile v1 table ready." . PHP_EOL;
        exit;
    }

    if ($cmd === '--issue-token') {
        $id = (int)($argv[2] ?? 0);

        if ($id < 1) {
            failMP('Usage: --issue-token ACCOUNT_ID');
        }

        echo issueTokenMP($db, $id) . PHP_EOL;
        exit;
    }

    if ($cmd === '--set-title') {
        $id = (int)($argv[2] ?? 0);
        $title = cleanMP($argv[3] ?? '', 48);

        if ($id < 1) {
            failMP('Usage: --set-title ACCOUNT_ID TITLE');
        }

        ensureMP($db, $id);

        $q = $db->prepare("
            UPDATE mucho_profile_customization
            SET title=?
            WHERE account_id=?
        ");

        $q->execute([$title, $id]);

        echo "OK" . PHP_EOL;
        exit;
    }

    if ($cmd === '--set-badges') {
        $id = (int)($argv[2] ?? 0);
        $badges = json_decode($argv[3] ?? '[]', true);

        if ($id < 1 || !is_array($badges)) {
            failMP(
                'Usage: --set-badges ACCOUNT_ID \'["Founder","Creator"]\''
            );
        }

        $badges = array_values(
            array_slice(
                array_map(
                    fn($x) => cleanMP((string)$x, 32),
                    $badges
                ),
                0,
                8
            )
        );

        ensureMP($db, $id);

        $q = $db->prepare("
            UPDATE mucho_profile_customization
            SET badges=?
            WHERE account_id=?
        ");

        $q->execute([
            json_encode($badges, JSON_UNESCAPED_UNICODE),
            $id
        ]);

        echo "OK" . PHP_EOL;
        exit;
    }

    if ($cmd === '--show') {
        $id = (int)($argv[2] ?? 0);

        if ($id < 1) {
            failMP('Usage: --show ACCOUNT_ID');
        }

        echo json_encode(
            profileMP($db, $id),
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        exit;
    }

    echo "
Mucho Profile v1 — IZK

--install
--issue-token ACCOUNT_ID
--set-title ACCOUNT_ID \"TITLE\"
--set-badges ACCOUNT_ID '[\"Founder\",\"Creator\"]'
--show ACCOUNT_ID
";

    exit;
}


/* ===========================
   HTTP API
   =========================== */

header('Content-Type: application/json; charset=UTF-8');

$action = (string)(
    $_POST['action']
    ?? $_GET['action']
    ?? 'get'
);

$id = (int)(
    $_POST['accountID']
    ?? $_GET['accountID']
    ?? 0
);

if ($id < 1) {
    failMP('bad_account_id');
}


/* GET PROFILE */

if ($action === 'get') {

    echo json_encode(
        profileMP($db, $id),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* UPDATE PROFILE */

if ($action !== 'update') {
    failMP('bad_action');
}

ensureMP($db, $id);

$token = (string)($_POST['token'] ?? '');

if (!verifyTokenMP($db, $id, $token)) {
    failMP('bad_token', 403);
}

$data = [
    'display_name' =>
        cleanMP((string)($_POST['display_name'] ?? ''), 32),

    'status' =>
        cleanMP((string)($_POST['status'] ?? ''), 48),

    'bio' =>
        cleanMP((string)($_POST['bio'] ?? ''), 240),

    'theme_primary' =>
        colorMP(
            (string)($_POST['theme_primary'] ?? ''),
            '#42D9CF'
        ),

    'theme_secondary' =>
        colorMP(
            (string)($_POST['theme_secondary'] ?? ''),
            '#806EFF'
        ),

    'banner' =>
        preg_match(
            '/^[A-Za-z0-9_-]{0,64}$/',
            (string)($_POST['banner'] ?? '')
        )
        ? (string)($_POST['banner'] ?? '')
        : 'gradient_01',

    'pinned_levels' =>
        pinnedMP((string)($_POST['pinned_levels'] ?? '')),

    'showcase' =>
        cleanMP((string)($_POST['showcase'] ?? ''), 200),

    'favorite_difficulty' =>
        cleanMP(
            (string)($_POST['favorite_difficulty'] ?? ''),
            32
        ),

    'online_visible' =>
        ((int)($_POST['online_visible'] ?? 1)) ? 1 : 0,

    'account_id' => $id
];

$q = $db->prepare("
    UPDATE mucho_profile_customization SET
        display_name=:display_name,
        status=:status,
        bio=:bio,
        theme_primary=:theme_primary,
        theme_secondary=:theme_secondary,
        banner=:banner,
        pinned_levels=:pinned_levels,
        showcase=:showcase,
        favorite_difficulty=:favorite_difficulty,
        online_visible=:online_visible,
        updated_at=CURRENT_TIMESTAMP
    WHERE account_id=:account_id
");

$q->execute($data);

echo json_encode([
    'ok' => true,
    'account_id' => $id
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
