<?php
declare(strict_types=1);

use MuchoCore\Database\Database;

require dirname(__DIR__, 3).'/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$requestId = bin2hex(random_bytes(12));
header('X-Request-Id: '.$requestId);

const MUCHO_ADMIN_CLIENT_VERSION = '1.0.0';
const MUCHO_ADMIN_TOKEN_TTL = 28800;
const MUCHO_ADMIN_MAX_BODY = 65536;

/** @return never */
function respond(array $payload, int $status = 200): never
{
    global $requestId;
    http_response_code($status);
    $payload['request_id'] = $requestId;
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/** @return never */
function fail(string $code, string $message, int $status): never
{
    respond(['ok' => false, 'error' => $code, 'message' => $message], $status);
}

function clientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');

    if (($remote === '127.0.0.1' || $remote === '::1') && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function ipHash(): string
{
    return hash('sha256', clientIp());
}

function roleRank(string $role): int
{
    return match (strtolower($role)) {
        'helper' => 10,
        'moderator' => 20,
        'admin' => 30,
        'owner' => 40,
        default => 0,
    };
}

function bearerToken(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,128})$/', $header, $match)) {
        return '';
    }
    return $match[1];
}

function base32Decode(string $encoded): string
{
    $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $result = '';

    foreach (str_split($encoded) as $char) {
        $value = strpos($alphabet, $char);
        if ($value === false) {
            return '';
        }
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $result .= chr(($buffer >> $bits) & 0xff);
        }
    }

    return $result;
}

function totpCode(string $secret, int $counter): string
{
    $key = base32Decode($secret);
    if ($key === '') {
        return '';
    }

    $high = ($counter >> 32) & 0xffffffff;
    $low = $counter & 0xffffffff;
    $digest = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
    $offset = ord($digest[19]) & 0x0f;
    $binary = (
        ((ord($digest[$offset]) & 0x7f) << 24) |
        ((ord($digest[$offset + 1]) & 0xff) << 16) |
        ((ord($digest[$offset + 2]) & 0xff) << 8) |
        (ord($digest[$offset + 3]) & 0xff)
    );

    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function verifyTotp(string $secret, string $candidate): bool
{
    if (!preg_match('/^[0-9]{6}$/', $candidate)) {
        return false;
    }

    $counter = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $expected = totpCode($secret, $counter + $offset);
        if ($expected !== '' && hash_equals($expected, $candidate)) {
            return true;
        }
    }
    return false;
}

function auditClient(PDO $db, array $admin, string $action, string $targetType = '', string $targetId = '', array $details = []): void
{
    $query = $db->prepare(
        'INSERT INTO mucho_admin_client_audit
         (admin_user_id, admin_username, action, target_type, target_id, details, ip_hash)
         VALUES (:admin_id, :username, :action, :target_type, :target_id, :details, :ip_hash)'
    );
    $query->execute([
        'admin_id' => (int)$admin['id'],
        'username' => (string)$admin['username'],
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip_hash' => ipHash(),
    ]);
}

function requireAdmin(PDO $db, int $minimumRank = 10): array
{
    $rawToken = bearerToken();
    if ($rawToken === '') {
        fail('unauthorized', 'Bearer token required.', 401);
    }

    $query = $db->prepare(
        'SELECT a.id, a.username, a.role, a.is_active, t.id AS token_id, t.expires_at
         FROM mucho_admin_client_tokens t
         JOIN admin_users a ON a.id = t.admin_user_id
         WHERE t.token_hash = :token_hash
           AND t.revoked_at IS NULL
           AND t.expires_at > NOW()
           AND a.is_active = 1
         LIMIT 1'
    );
    $query->execute(['token_hash' => hash('sha256', $rawToken)]);
    $admin = $query->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        fail('unauthorized', 'Session expired or invalid.', 401);
    }

    $rank = roleRank((string)$admin['role']);
    if ($rank < $minimumRank) {
        fail('forbidden', 'Insufficient administrator rank.', 403);
    }

    $db->prepare('UPDATE mucho_admin_client_tokens SET last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => (int)$admin['token_id']]);

    $admin['rank'] = $rank;
    return $admin;
}

function intInput(array $input, string $key, int $minimum, int $maximum): int
{
    if (!array_key_exists($key, $input) || !is_int($input[$key])) {
        fail('invalid_request', 'Invalid field: '.$key, 422);
    }
    return max($minimum, min($maximum, $input[$key]));
}

function boolInput(array $input, string $key): bool
{
    if (!array_key_exists($key, $input) || !is_bool($input[$key])) {
        fail('invalid_request', 'Invalid field: '.$key, 422);
    }
    return $input[$key];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail('method_not_allowed', 'POST required.', 405);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > MUCHO_ADMIN_MAX_BODY) {
    fail('invalid_request', 'Request body is missing or too large.', 413);
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    fail('invalid_json', 'JSON object required.', 400);
}

$action = (string)($input['action'] ?? '');
if ($action === '' || strlen($action) > 64) {
    fail('invalid_request', 'Action is required.', 422);
}

try {
    $db = (new Database())->connection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($action === 'auth.login') {
        $username = substr(trim((string)($input['username'] ?? '')), 0, 64);
        $password = (string)($input['password'] ?? '');
        $totp = preg_replace('/\D/', '', (string)($input['totp'] ?? '')) ?? '';
        $clientVersion = substr((string)($input['client_version'] ?? ''), 0, 32);

        if ($username === '' || $password === '' || strlen($password) > 1024) {
            fail('invalid_credentials', 'Invalid username or password.', 401);
        }

        $limit = $db->prepare(
            'SELECT COUNT(*) FROM mucho_admin_client_login_attempts
             WHERE ip_hash = :ip_hash AND success = 0
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $limit->execute(['ip_hash' => ipHash()]);
        if ((int)$limit->fetchColumn() >= 8) {
            fail('rate_limited', 'Too many login attempts. Try again later.', 429);
        }

        $query = $db->prepare(
            'SELECT id, username, password_hash, role, totp_secret, is_active
             FROM admin_users WHERE username = :username LIMIT 1'
        );
        $query->execute(['username' => $username]);
        $row = $query->fetch();

        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $passwordOk = password_verify($password, (string)($row['password_hash'] ?? $dummyHash));
        $active = $row && (int)$row['is_active'] === 1;

        $attempt = $db->prepare(
            'INSERT INTO mucho_admin_client_login_attempts (username, ip_hash, success)
             VALUES (:username, :ip_hash, :success)'
        );

        if (!$passwordOk || !$active || roleRank((string)($row['role'] ?? '')) < 10) {
            $attempt->execute(['username' => $username, 'ip_hash' => ipHash(), 'success' => 0]);
            fail('invalid_credentials', 'Invalid username or password.', 401);
        }

        $totpSecret = trim((string)($row['totp_secret'] ?? ''));
        if ($totpSecret !== '' && $totp === '') {
            $attempt->execute([
                'username' => $username,
                'ip_hash' => ipHash(),
                'success' => 0,
            ]);
            fail('totp_required', 'Two-factor authentication code required.', 401);
        }
        if ($totpSecret !== '' && !verifyTotp($totpSecret, $totp)) {
            $attempt->execute([
                'username' => $username,
                'ip_hash' => ipHash(),
                'success' => 0,
            ]);
            fail('invalid_totp', 'Invalid two-factor authentication code.', 401);
        }

        $attempt->execute(['username' => $username, 'ip_hash' => ipHash(), 'success' => 1]);
        $db->prepare('DELETE FROM mucho_admin_client_login_attempts WHERE ip_hash = :ip_hash AND success = 0')
            ->execute(['ip_hash' => ipHash()]);
        $db->exec('DELETE FROM mucho_admin_client_tokens WHERE expires_at < (NOW() - INTERVAL 7 DAY)');

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $insert = $db->prepare(
            'INSERT INTO mucho_admin_client_tokens
             (admin_user_id, token_hash, client_version, created_ip_hash, expires_at)
             VALUES (:admin_id, :token_hash, :client_version, :ip_hash,
                     DATE_ADD(NOW(), INTERVAL '.MUCHO_ADMIN_TOKEN_TTL.' SECOND))'
        );
        $insert->execute([
            'admin_id' => (int)$row['id'],
            'token_hash' => hash('sha256', $token),
            'client_version' => $clientVersion,
            'ip_hash' => ipHash(),
        ]);

        $admin = ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'role' => (string)$row['role']];
        auditClient($db, $admin, 'auth.login');

        respond([
            'ok' => true,
            'token' => $token,
            'expires_in' => MUCHO_ADMIN_TOKEN_TTL,
            'admin' => [
                'id' => (int)$row['id'],
                'username' => (string)$row['username'],
                'role' => (string)$row['role'],
                'rank' => roleRank((string)$row['role']),
            ],
        ]);
    }

    if ($action === 'auth.logout') {
        $admin = requireAdmin($db);
        $db->prepare('UPDATE mucho_admin_client_tokens SET revoked_at = NOW() WHERE id = :id')
            ->execute(['id' => (int)$admin['token_id']]);
        auditClient($db, $admin, 'auth.logout');
        respond(['ok' => true]);
    }

    if ($action === 'auth.me') {
        $admin = requireAdmin($db);
        respond(['ok' => true, 'admin' => [
            'id' => (int)$admin['id'],
            'username' => (string)$admin['username'],
            'role' => (string)$admin['role'],
            'rank' => (int)$admin['rank'],
        ]]);
    }

    if ($action === 'server.health') {
        $admin = requireAdmin($db, 40);
        $db->query('SELECT 1')->fetchColumn();
        respond([
            'ok' => true,
            'api' => 'MuchoAdminClient',
            'version' => MUCHO_ADMIN_CLIENT_VERSION,
            'database' => 'connected',
            'time' => gmdate('c'),
            // Пасхалка №1: кубик не спит, он просто ждёт следующий запрос.
            'motto' => 'The cube is watching the backups.',
            'admin' => (string)$admin['username'],
        ]);
    }

    if ($action === 'players.search') {
        requireAdmin($db, 40);
        $search = substr(trim((string)($input['query'] ?? '')), 0, 64);
        $like = '%'.$search.'%';
        $exactId = ctype_digit($search) ? (int)$search : -1;
        $query = $db->prepare(
            'SELECT a.account_id, a.username, COALESCE(r.code, \'user\') AS role, a.is_active, a.is_banned,
                    p.stars, p.moons, p.diamonds, p.secret_coins, p.user_coins,
                    p.demons, p.creator_points, p.bio, p.last_played_at
             FROM accounts a
             LEFT JOIN profiles p ON p.account_id = a.account_id
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE (:empty = 1 OR a.username LIKE :like_search OR a.account_id = :exact_id)
             ORDER BY a.account_id DESC LIMIT 50'
        );
        $query->execute(['empty' => $search === '' ? 1 : 0, 'like_search' => $like, 'exact_id' => $exactId]);
        $players = [];
        foreach ($query->fetchAll() as $row) {
            $players[] = [
                'account_id' => (int)$row['account_id'],
                'username' => (string)$row['username'],
                'role' => (string)$row['role'],
                'is_active' => (bool)$row['is_active'],
                'is_banned' => (bool)$row['is_banned'],
                'stars' => (int)($row['stars'] ?? 0),
                'moons' => (int)($row['moons'] ?? 0),
                'diamonds' => (int)($row['diamonds'] ?? 0),
                'secret_coins' => (int)($row['secret_coins'] ?? 0),
                'user_coins' => (int)($row['user_coins'] ?? 0),
                'demons' => (int)($row['demons'] ?? 0),
                'creator_points' => (int)($row['creator_points'] ?? 0),
                'bio' => (string)($row['bio'] ?? ''),
                'last_played_at' => $row['last_played_at'],
            ];
        }
        respond(['ok' => true, 'players' => $players]);
    }

    if ($action === 'players.get') {
        requireAdmin($db, 40);
        $accountId = intInput($input, 'account_id', 1, PHP_INT_MAX);
        $query = $db->prepare(
            'SELECT a.account_id, a.username, COALESCE(r.code, \'user\') AS role, a.is_active, a.is_banned,
                    p.stars, p.moons, p.diamonds, p.secret_coins, p.user_coins,
                    p.demons, p.creator_points, p.bio, p.last_played_at
             FROM accounts a
             LEFT JOIN profiles p ON p.account_id = a.account_id
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id LIMIT 1'
        );
        $query->execute(['id' => $accountId]);
        $row = $query->fetch();
        if (!$row) {
            fail('not_found', 'Player not found.', 404);
        }
        respond(['ok' => true, 'player' => [
            'account_id' => (int)$row['account_id'],
            'username' => (string)$row['username'],
            'role' => (string)$row['role'],
            'is_active' => (bool)$row['is_active'],
            'is_banned' => (bool)$row['is_banned'],
            'stars' => (int)($row['stars'] ?? 0),
            'moons' => (int)($row['moons'] ?? 0),
            'diamonds' => (int)($row['diamonds'] ?? 0),
            'secret_coins' => (int)($row['secret_coins'] ?? 0),
            'user_coins' => (int)($row['user_coins'] ?? 0),
            'demons' => (int)($row['demons'] ?? 0),
            'creator_points' => (int)($row['creator_points'] ?? 0),
            'bio' => (string)($row['bio'] ?? ''),
            'last_played_at' => $row['last_played_at'],
        ]]);
    }

    if ($action === 'players.set_ban') {
        $admin = requireAdmin($db, 40);
        $accountId = intInput($input, 'account_id', 1, PHP_INT_MAX);
        $banned = boolInput($input, 'banned');

        $query = $db->prepare('SELECT a.account_id, a.username, COALESCE(r.code, \'user\') AS role, a.is_banned
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id LIMIT 1');
        $query->execute(['id' => $accountId]);
        $target = $query->fetch();
        if (!$target) {
            fail('not_found', 'Player not found.', 404);
        }
        if (strtolower((string)$target['role']) === 'owner' && (int)$admin['rank'] < 40) {
            fail('forbidden', 'Only an owner can moderate an owner account.', 403);
        }

        $db->prepare('UPDATE accounts SET is_banned = :banned WHERE account_id = :id')
            ->execute(['banned' => $banned ? 1 : 0, 'id' => $accountId]);
        auditClient($db, $admin, $banned ? 'player.ban' : 'player.unban', 'account', (string)$accountId, [
            'username' => (string)$target['username'],
            'previous' => (bool)$target['is_banned'],
            'current' => $banned,
        ]);
        respond(['ok' => true, 'account_id' => $accountId, 'is_banned' => $banned]);
    }

    if ($action === 'players.set_role') {
        $admin = requireAdmin($db, 40);
        $accountId = intInput($input, 'account_id', 1, PHP_INT_MAX);
        $role = strtolower((string)($input['role'] ?? ''));
        if ($role === 'helper') {
            $role = 'moderator';
        }
        if (!in_array($role, ['user', 'moderator', 'admin', 'owner'], true)) {
            fail('invalid_request', 'Invalid account role.', 422);
        }
        $query = $db->prepare('SELECT a.username, COALESCE(r.code, \'user\') AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id LIMIT 1');
        $query->execute(['id' => $accountId]);
        $target = $query->fetch();
        if (!$target) {
            fail('not_found', 'Player not found.', 404);
        }
        $db->prepare(
            'UPDATE accounts a
             JOIN roles r ON r.code = :role
             SET a.role_id = r.id
             WHERE a.account_id = :id'
        )->execute(['role' => $role, 'id' => $accountId]);
        auditClient($db, $admin, 'player.role', 'account', (string)$accountId, [
            'username' => (string)$target['username'],
            'previous' => (string)$target['role'],
            'current' => $role,
        ]);
        respond(['ok' => true, 'account_id' => $accountId, 'role' => $role]);
    }

    if ($action === 'players.update_profile') {
        $admin = requireAdmin($db, 40);
        $accountId = intInput($input, 'account_id', 1, PHP_INT_MAX);
        $stars = intInput($input, 'stars', 0, 100000000);
        $moons = intInput($input, 'moons', 0, 100000000);
        $diamonds = intInput($input, 'diamonds', 0, 1000000000);
        $secretCoins = intInput($input, 'secret_coins', 0, 1000000);
        $userCoins = intInput($input, 'user_coins', 0, 100000000);
        $demons = intInput($input, 'demons', 0, 10000000);
        $creatorPoints = intInput($input, 'creator_points', 0, 10000000);
        $bio = substr(trim((string)($input['bio'] ?? '')), 0, 2000);

        $query = $db->prepare(
            'SELECT a.username, p.stars, p.moons, p.diamonds, p.secret_coins,
                    p.user_coins, p.demons, p.creator_points, p.bio
             FROM accounts a JOIN profiles p ON p.account_id = a.account_id
             WHERE a.account_id = :id LIMIT 1'
        );
        $query->execute(['id' => $accountId]);
        $before = $query->fetch();
        if (!$before) {
            fail('not_found', 'Player profile not found.', 404);
        }

        $update = $db->prepare(
            'UPDATE profiles SET stars = :stars, moons = :moons, diamonds = :diamonds,
                    secret_coins = :secret_coins, user_coins = :user_coins,
                    demons = :demons, creator_points = :creator_points, bio = :bio
             WHERE account_id = :id'
        );
        $update->execute([
            'stars' => $stars,
            'moons' => $moons,
            'diamonds' => $diamonds,
            'secret_coins' => $secretCoins,
            'user_coins' => $userCoins,
            'demons' => $demons,
            'creator_points' => $creatorPoints,
            'bio' => $bio,
            'id' => $accountId,
        ]);

        auditClient($db, $admin, 'player.profile.update', 'account', (string)$accountId, [
            'username' => (string)$before['username'],
            'previous' => $before,
            'current' => [
                'stars' => $stars,
                'moons' => $moons,
                'diamonds' => $diamonds,
                'secret_coins' => $secretCoins,
                'user_coins' => $userCoins,
                'demons' => $demons,
                'creator_points' => $creatorPoints,
                'bio' => $bio,
            ],
        ]);
        respond(['ok' => true, 'account_id' => $accountId]);
    }

    if ($action === 'levels.search') {
        requireAdmin($db, 40);
        $search = substr(trim((string)($input['query'] ?? '')), 0, 64);
        $like = '%'.$search.'%';
        $exactId = ctype_digit($search) ? (int)$search : -1;
        $query = $db->prepare(
            'SELECT l.level_id, l.account_id, l.name, a.username AS creator,
                    l.stars, l.difficulty, l.demon, l.demon_difficulty,
                    l.featured, l.epic, l.custom_difficulty, l.rate_tier,
                    l.custom_rate_tier, l.requested_stars, l.downloads, l.likes,
                    l.is_deleted, l.updated_at
             FROM levels l
             LEFT JOIN accounts a ON a.account_id = l.account_id
             WHERE (:empty = 1 OR l.name LIKE :level_search OR a.username LIKE :creator_search OR l.level_id = :exact_id)
             ORDER BY l.level_id DESC LIMIT 50'
        );
        $query->execute([
            'empty' => $search === '' ? 1 : 0,
            'level_search' => $like,
            'creator_search' => $like,
            'exact_id' => $exactId,
        ]);
        $levels = [];
        foreach ($query->fetchAll() as $row) {
            $levels[] = [
                'level_id' => (int)$row['level_id'],
                'account_id' => (int)$row['account_id'],
                'name' => (string)$row['name'],
                'creator' => (string)($row['creator'] ?? ''),
                'stars' => (int)$row['stars'],
                'difficulty' => (int)$row['difficulty'],
                'demon' => (bool)$row['demon'],
                'demon_difficulty' => (int)$row['demon_difficulty'],
                'featured' => (bool)$row['featured'],
                'epic' => (int)$row['epic'],
                'custom_difficulty' => (int)$row['custom_difficulty'],
                'rate_tier' => (int)$row['rate_tier'],
                'custom_rate_tier' => (int)$row['custom_rate_tier'],
                'requested_stars' => (int)$row['requested_stars'],
                'downloads' => (int)$row['downloads'],
                'likes' => (int)$row['likes'],
                'is_deleted' => (bool)$row['is_deleted'],
                'updated_at' => $row['updated_at'],
            ];
        }
        respond(['ok' => true, 'levels' => $levels]);
    }

    if ($action === 'levels.update') {
        $admin = requireAdmin($db, 40);
        $levelId = intInput($input, 'level_id', 1, PHP_INT_MAX);
        $stars = intInput($input, 'stars', 0, 10);
        $difficulty = intInput($input, 'difficulty', 0, 100);
        $demon = boolInput($input, 'demon');
        $demonDifficulty = intInput($input, 'demon_difficulty', 0, 6);
        $featured = boolInput($input, 'featured');
        $epic = intInput($input, 'epic', 0, 3);
        $deleted = boolInput($input, 'is_deleted');

        $query = $db->prepare(
            'SELECT level_id, name, stars, difficulty, demon, demon_difficulty, featured, epic, is_deleted
             FROM levels WHERE level_id = :id LIMIT 1'
        );
        $query->execute(['id' => $levelId]);
        $before = $query->fetch();
        if (!$before) {
            fail('not_found', 'Level not found.', 404);
        }

        $update = $db->prepare(
            'UPDATE levels SET stars = :stars, difficulty = :difficulty, demon = :demon,
                    demon_difficulty = :demon_difficulty, featured = :featured,
                    epic = :epic, is_deleted = :is_deleted
             WHERE level_id = :id'
        );
        $update->execute([
            'stars' => $stars,
            'difficulty' => $difficulty,
            'demon' => $demon ? 1 : 0,
            'demon_difficulty' => $demonDifficulty,
            'featured' => $featured ? 1 : 0,
            'epic' => $epic,
            'is_deleted' => $deleted ? 1 : 0,
            'id' => $levelId,
        ]);

        auditClient($db, $admin, 'level.update', 'level', (string)$levelId, [
            'name' => (string)$before['name'],
            'previous' => $before,
            'current' => [
                'stars' => $stars,
                'difficulty' => $difficulty,
                'demon' => $demon,
                'demon_difficulty' => $demonDifficulty,
                'featured' => $featured,
                'epic' => $epic,
                'is_deleted' => $deleted,
            ],
        ]);
        respond(['ok' => true, 'level_id' => $levelId]);
    }

    if ($action === 'audit.list') {
        requireAdmin($db, 40);
        $query = $db->query(
            'SELECT id, admin_username, action, target_type, target_id, details, created_at
             FROM mucho_admin_client_audit ORDER BY id DESC LIMIT 100'
        );
        respond(['ok' => true, 'entries' => $query->fetchAll()]);
    }

    if ($action === 'mucho.konami') {
        $admin = requireAdmin($db, 40);
        auditClient($db, $admin, 'mucho.konami');
        respond(['ok' => true, 'message' => '↑ ↑ ↓ ↓ ← → ← → B A — Mucho remembers.']);
    }

    fail('unknown_action', 'Unknown API action.', 404);
} catch (Throwable $error) {
    error_log('[MuchoAdminClient '.$requestId.'] '.$error->getMessage());
    fail('server_error', 'Internal server error.', 500);
}
