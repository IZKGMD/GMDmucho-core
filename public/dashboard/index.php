<?php
declare(strict_types=1);

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Branding\BrandingService;
use MuchoCore\Database\Database;
use MuchoCore\Security\RateLimiter;
use MuchoCore\Security\SoftAntiBot;
use MuchoCore\Security\Turnstile;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = (new Database())->connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$branding = new BrandingService($db);
$brandingData = $branding->get();
$serverName = $brandingData['server_name'];
$serverByName = $brandingData['server_by_name'];
$socialUrl = $brandingData['social_url'];

$rootDir = dirname(__DIR__, 2);
$musicDir = $rootDir . '/storage/music-public';

/*
 * Player portal:
 * - public player/profile browsing
 * - GD account login for uploads
 * - exactly one player upload per 180 seconds
 * - no account-count/rate quota beyond the 180-second cooldown
 */
$musicMaxBytes = 64 * 1024 * 1024;
$youtubeImportEnabled = filter_var(
    getenv('MUCHO_ENABLE_YOUTUBE_IMPORT') ?: '1',
    FILTER_VALIDATE_BOOL
);
$youtubeDownloader = (string)(getenv('MUCHO_YTDLP_BIN') ?: 'yt-dlp');

$secure = (
    ($_SERVER['HTTPS'] ?? '') === 'on' ||
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

session_name('MUCHO_PLAYER');
session_start();

function pdH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pdCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf'];
}

function pdRequireCsrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(pdCsrf(), $token)) {
        http_response_code(403);
        exit('CSRF rejected');
    }
}

function pdFlash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type,
        ];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function pdIp(): string
{
    return trim(
        (string)(
            $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown'
        )
    );
}

function pdRedirect(string $url = '/dashboard'): never
{
    header('Location: ' . $url);
    exit;
}

function pdAntiBotOrReject(string $scope): void
{
    if (Turnstile::enabled()) {
        $token = (string)($_POST['cf-turnstile-response'] ?? '');
        $expectedHostname = parse_url(
            (string)(getenv('MUCHO_ACCOUNT_URL') ?: ''),
            PHP_URL_HOST
        );

        if (!Turnstile::verify(
            $token,
            $scope,
            is_string($expectedHostname) ? $expectedHostname : null
        )) {
            pdFlash('Security check failed. Please try again.', 'error');
            pdRedirect();
        }

        return;
    }

    $token = (string)($_POST['antibot_token'] ?? '');
    $honeypot = (string)($_POST['website'] ?? '');

    if (!SoftAntiBot::verify($scope, $token, $honeypot)) {
        pdFlash('Please try again.', 'error');
        pdRedirect();
    }
}

function pdAccountFromSession(): ?array
{
    $account = $_SESSION['account'] ?? null;
    return is_array($account) && (int)($account['id'] ?? 0) > 0
        ? $account
        : null;
}

function pdFormatNumber(mixed $value): string
{
    return number_format((int)$value);
}

function pdIconType(int $type): array
{
    return [
        0 => ['cube', 'cube'],
        1 => ['ship', 'ship'],
        2 => ['ball', 'ball'],
        3 => ['ufo', 'ufo'],
        4 => ['wave', 'wave'],
        5 => ['robot', 'robot'],
        6 => ['spider', 'spider'],
        7 => ['swing', 'swing'],
        8 => ['jetpack', 'jetpack'],
    ][$type] ?? ['cube', 'cube'];
}

function pdIconUrl(array $profile, int $size = 92): string
{
    [$type, $column] = pdIconType((int)($profile['icon_type'] ?? 0));
    $value = max(1, (int)($profile[$column] ?? $profile['icon_id'] ?? 1));
    $color1 = max(0, min(106, (int)($profile['color1'] ?? 0)));
    $color2 = max(0, min(106, (int)($profile['color2'] ?? 3)));

    return 'https://gdicon.oat.zone/icon.png?' . http_build_query([
        'type' => $type,
        'value' => $value,
        'color1' => $color1,
        'color2' => $color2,
        'size' => $size,
    ]);
}

function pdProfile(PDO $db, string $username): ?array
{
    $stmt = $db->prepare(
        'SELECT
            a.account_id,
            a.username,
            a.created_at,
            COALESCE(r.code, "user") AS role_code,
            COALESCE(p.user_id, 0) AS user_id,
            COALESCE(p.stars, 0) AS stars,
            COALESCE(p.moons, 0) AS moons,
            COALESCE(p.diamonds, 0) AS diamonds,
            COALESCE(p.secret_coins, 0) AS secret_coins,
            COALESCE(p.user_coins, 0) AS user_coins,
            COALESCE(p.demons, 0) AS demons,
            COALESCE(p.creator_points, 0) AS creator_points,
            COALESCE(p.icon_id, 1) AS icon_id,
            COALESCE(p.icon_type, 0) AS icon_type,
            COALESCE(p.color1, 0) AS color1,
            COALESCE(p.color2, 3) AS color2,
            COALESCE(p.glow, 0) AS glow,
            COALESCE((SELECT c.clan_id
                      FROM mucho_clan_members cm
                      INNER JOIN mucho_clans c ON c.clan_id = cm.clan_id
                      WHERE cm.account_id = a.account_id
                      LIMIT 1), 0) AS clan_id,
            COALESCE((SELECT c.tag
                      FROM mucho_clan_members cm
                      INNER JOIN mucho_clans c ON c.clan_id = cm.clan_id
                      WHERE cm.account_id = a.account_id
                      LIMIT 1), "") AS clan_tag
         FROM accounts a
         LEFT JOIN roles r ON r.id = a.role_id
         LEFT JOIN profiles p ON p.account_id = a.account_id
         WHERE a.username = :username
           AND a.is_active = 1
         LIMIT 1'
    );

    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    $row['levels_count'] = 0;
    $row['level_downloads'] = 0;
    $row['songs_count'] = 0;
    $row['verified_songs'] = 0;
    $row['songs_mb'] = 0.0;

    try {
        $q = $db->prepare(
            'SELECT
                COUNT(*) AS levels_count,
                COALESCE(SUM(downloads), 0) AS level_downloads
             FROM levels
             WHERE account_id = :account_id
               AND is_deleted = 0'
        );
        $q->execute(['account_id' => (int)$row['account_id']]);
        $stats = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $row['levels_count'] = (int)($stats['levels_count'] ?? 0);
        $row['level_downloads'] = (int)($stats['level_downloads'] ?? 0);
    } catch (Throwable) {
    }

    try {
        $q = $db->prepare(
            'SELECT
                COUNT(*) AS songs_count,
                COALESCE(SUM(CASE WHEN is_verified=1 THEN 1 ELSE 0 END), 0) AS verified_songs,
                COALESCE(SUM(size), 0) AS songs_mb
             FROM songs
             WHERE author_id = :account_id'
        );
        $q->execute(['account_id' => (int)$row['account_id']]);
        $stats = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $row['songs_count'] = (int)($stats['songs_count'] ?? 0);
        $row['verified_songs'] = (int)($stats['verified_songs'] ?? 0);
        $row['songs_mb'] = round((float)($stats['songs_mb'] ?? 0), 2);
    } catch (Throwable) {
    }

    return $row;
}

function pdTopPlayers(PDO $db, int $limit = 12): array
{
    try {
        $stmt = $db->query(
            'SELECT
                a.account_id,
                a.username,
                COALESCE(r.code, "user") AS role_code,
                COALESCE(p.user_id, 0) AS user_id,
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.icon_id, 1) AS icon_id,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.glow, 0) AS glow,
                COALESCE((SELECT c.tag
                          FROM mucho_clan_members cm
                          INNER JOIN mucho_clans c ON c.clan_id = cm.clan_id
                          WHERE cm.account_id = a.account_id
                          LIMIT 1), "") AS clan_tag
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             LEFT JOIN profiles p ON p.account_id = a.account_id
             WHERE a.is_active = 1
               AND a.is_banned = 0
             ORDER BY COALESCE(p.stars, 0) DESC, a.account_id ASC
             LIMIT ' . (int)$limit
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

function pdRecentSongs(PDO $db, int $limit = 10): array
{
    try {
        $stmt = $db->query(
            'SELECT
                s.id,
                s.name,
                s.author_id,
                s.author_name,
                s.size,
                s.is_verified,
                s.created_at
             FROM songs s
             WHERE s.is_verified = 1
             ORDER BY s.id DESC
             LIMIT ' . (int)$limit
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

function pdPlayerLevels(PDO $db, int $accountId, int $limit = 8): array
{
    try {
        $stmt = $db->prepare(
            'SELECT
                level_id,
                name,
                stars,
                downloads,
                likes,
                created_at
             FROM levels
             WHERE account_id = :account_id
               AND is_deleted = 0
               AND is_unlisted = 0
             ORDER BY level_id DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

function pdPlayerSongs(PDO $db, int $accountId, int $limit = 8): array
{
    try {
        $stmt = $db->prepare(
            'SELECT
                id,
                name,
                author_name,
                size,
                is_verified,
                created_at
             FROM songs
             WHERE author_id = :account_id
             ORDER BY id DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'login') {
    pdRequireCsrf();
    pdAntiBotOrReject('login');

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (
        $username === '' ||
        mb_strlen($username, 'UTF-8') > 20 ||
        $password === ''
    ) {
        pdFlash('Enter your Geometry Dash username and password.', 'error');
        pdRedirect();
    }

    $limiter = new RateLimiter();
    if (!$limiter->allow('dashboard-login:' . pdIp(), 8, 900)) {
        pdFlash('Too many login attempts. Try again later.', 'error');
        pdRedirect();
    }

    try {
        $stmt = $db->prepare(
            'SELECT account_id, username, is_active, is_banned
             FROM accounts
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($account) ||
            (int)$account['is_active'] !== 1 ||
            (int)$account['is_banned'] !== 0
        ) {
            throw new RuntimeException('Invalid account');
        }

        (new AccountAuthenticator($db))->authenticate(
            (int)$account['account_id'],
            $password
        );

        session_regenerate_id(true);

        $_SESSION['account'] = [
            'id' => (int)$account['account_id'],
            'username' => (string)$account['username'],
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        pdFlash('Signed in as ' . (string)$account['username'] . '.');
    } catch (Throwable) {
        pdFlash('Invalid Geometry Dash username or password.', 'error');
    }

    pdRedirect();
}

if ($action === 'logout') {
    pdRequireCsrf();
    $_SESSION = [];
    session_destroy();
    pdRedirect();
}

if ($action === 'upload') {
    pdRequireCsrf();
    pdAntiBotOrReject('upload');

    $account = pdAccountFromSession();

    if (!$account) {
        pdFlash('Sign in to upload music.', 'error');
        pdRedirect();
    }

    $accountId = (int)$account['id'];
    $username = (string)$account['username'];

    $title = trim((string)($_POST['title'] ?? ''));
    $artist = trim((string)($_POST['artist'] ?? $username));

    if ($title === '' || mb_strlen($title, 'UTF-8') > 128) {
        pdFlash('Song title is required and must be 128 characters or less.', 'error');
        pdRedirect();
    }

    if ($artist === '' || mb_strlen($artist, 'UTF-8') > 128) {
        pdFlash('Artist is required and must be 128 characters or less.', 'error');
        pdRedirect();
    }

    if (
        !isset($_FILES['music_file']) ||
        ($_FILES['music_file']['error'] ?? -1) !== UPLOAD_ERR_OK
    ) {
        pdFlash('MP3 upload failed.', 'error');
        pdRedirect();
    }

    $file = $_FILES['music_file'];
    $size = (int)($file['size'] ?? 0);
    $tmp = (string)($file['tmp_name'] ?? '');

    if ($size <= 0 || $size > $musicMaxBytes) {
        pdFlash(
            'The uploaded file is too large for the current server upload transport (maximum 64 MB).',
            'error'
        );
        pdRedirect();
    }

    if (!is_uploaded_file($tmp)) {
        pdFlash('Invalid upload.', 'error');
        pdRedirect();
    }

    $limiter = new RateLimiter();

    if (!$limiter->allow('dashboard-music:' . $accountId, 1, 180)) {
        pdFlash(
            'You can upload one song every 3 minutes. Please try again when the cooldown ends.',
            'error'
        );
        pdRedirect();
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'], true)) {
        pdFlash('Only MP3 files are allowed.', 'error');
        pdRedirect();
    }

    if (
        !is_dir($musicDir) &&
        !mkdir($musicDir, 0770, true) &&
        !is_dir($musicDir)
    ) {
        pdFlash('Music storage is unavailable.', 'error');
        pdRedirect();
    }

    $stored = bin2hex(random_bytes(20)) . '.mp3';
    $target = $musicDir . '/' . $stored;

    if (!move_uploaded_file($tmp, $target)) {
        pdFlash('Cannot store the uploaded MP3.', 'error');
        pdRedirect();
    }

    @chmod($target, 0640);

    $baseUrl = rtrim(
        (string)(
            getenv('MUCHO_ACCOUNT_URL')
            ?: ('https://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))
        ),
        '/'
    );

    $downloadUrl = $baseUrl . '/music/' . rawurlencode($stored);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'INSERT INTO songs
                (name, author_id, author_name, size, download_url, is_verified)
             VALUES
                (:name, :author_id, :author_name, :size, :download_url, 1)'
        );

        $stmt->execute([
            'name' => $title,
            'author_id' => $accountId,
            'author_name' => $artist,
            'size' => round($size / 1024 / 1024, 2),
            'download_url' => $downloadUrl,
        ]);

        $songId = (int)$db->lastInsertId();
        $db->commit();

        pdFlash(
            'Track #' . $songId . ' uploaded and published.'
        );
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        @unlink($target);
        error_log('[Mucho Dashboard] ' . $e->getMessage());
        pdFlash('Upload failed. Please try again.', 'error');
    }

    pdRedirect();
}

if ($action === 'upload_youtube') {
    pdRequireCsrf();
    pdAntiBotOrReject('upload-youtube');

    $account = pdAccountFromSession();

    if (!$account) {
        pdFlash('Sign in to import music.', 'error');
        pdRedirect();
    }

    if (!$youtubeImportEnabled) {
        pdFlash('YouTube import is disabled on this server.', 'error');
        pdRedirect();
    }

    $accountId = (int)$account['id'];
    $username = (string)$account['username'];
    $youtubeUrl = trim((string)($_POST['youtube_url'] ?? ''));
    $titleOverride = trim((string)($_POST['youtube_title'] ?? ''));
    $artistOverride = trim((string)($_POST['youtube_artist'] ?? ''));

    if ($youtubeUrl === '' || mb_strlen($youtubeUrl, 'UTF-8') > 512) {
        pdFlash('Enter a valid YouTube URL.', 'error');
        pdRedirect();
    }

    $parts = parse_url($youtubeUrl);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowedHosts = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
    ];

    if (
        !isset($parts['scheme']) ||
        !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true) ||
        !in_array($host, $allowedHosts, true)
    ) {
        pdFlash('Only YouTube URLs are supported.', 'error');
        pdRedirect();
    }

    $limiter = new RateLimiter();

    if (!$limiter->allow('dashboard-youtube:' . $accountId, 1, 180)) {
        pdFlash(
            'You can import one song every 3 minutes. Please try again when the cooldown ends.',
            'error'
        );
        pdRedirect();
    }

    if (!function_exists('exec')) {
        pdFlash('YouTube import is unavailable because PHP exec() is disabled.', 'error');
        pdRedirect();
    }

    $yt = escapeshellarg($youtubeDownloader);
    $urlArg = escapeshellarg($youtubeUrl);

    $versionOutput = [];
    $versionCode = 1;
    @exec($yt . ' --version 2>&1', $versionOutput, $versionCode);

    if ($versionCode !== 0) {
        pdFlash(
            'YouTube import is unavailable: yt-dlp is not installed or is not executable.',
            'error'
        );
        pdRedirect();
    }

    $metaOutput = [];
    $metaCode = 1;

    @exec(
        $yt .
        ' --no-playlist --dump-single-json --skip-download --no-warnings --remote-components ejs:github --socket-timeout 15 --retries 2 ' .
        $urlArg .
        ' 2>&1',
        $metaOutput,
        $metaCode
    );

    $metadata = null;

    if ($metaCode === 0) {
        foreach (array_reverse($metaOutput) as $line) {
            $candidate = json_decode((string)$line, true);

            if (is_array($candidate)) {
                $metadata = $candidate;
                break;
            }
        }
    }

    if (!is_array($metadata)) {
        error_log(
            '[Mucho Dashboard] YouTube metadata failed (exit ' .
            $metaCode . '): ' .
            implode(' | ', array_slice($metaOutput, -8))
        );
        pdFlash('Could not read metadata from that YouTube video.', 'error');
        pdRedirect();
    }

    $title = $titleOverride !== ''
        ? $titleOverride
        : trim((string)($metadata['title'] ?? ''));

    $artist = $artistOverride !== ''
        ? $artistOverride
        : trim((string)($metadata['uploader'] ?? $metadata['channel'] ?? $username));

    if ($title === '' || mb_strlen($title, 'UTF-8') > 128) {
        pdFlash('The detected song title is invalid or too long.', 'error');
        pdRedirect();
    }

    if ($artist === '' || mb_strlen($artist, 'UTF-8') > 128) {
        pdFlash('The detected artist name is invalid or too long.', 'error');
        pdRedirect();
    }

    $tmpRoot = $rootDir . '/storage/tmp';

    if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0770, true) && !is_dir($tmpRoot)) {
        pdFlash('Temporary music storage is unavailable.', 'error');
        pdRedirect();
    }

    $jobDir = $tmpRoot . '/youtube-' . bin2hex(random_bytes(12));

    if (!mkdir($jobDir, 0770, true) && !is_dir($jobDir)) {
        pdFlash('Temporary music storage is unavailable.', 'error');
        pdRedirect();
    }

    $cleanup = static function(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $item) {
            if (is_file($item) || is_link($item)) {
                @unlink($item);
            }
        }

        @rmdir($dir);
    };

    $downloadTemplate = $jobDir . '/%(id)s.%(ext)s';

    $downloadCommand =
        $yt .
        ' --no-playlist --no-warnings --remote-components ejs:github --socket-timeout 15 --retries 2' .
        ' --max-filesize 64M -x --audio-format mp3 --audio-quality 5' .
        ' -o ' . escapeshellarg($downloadTemplate) .
        ' ' . $urlArg .
        ' 2>&1';

    $downloadOutput = [];
    $downloadCode = 1;

    @exec($downloadCommand, $downloadOutput, $downloadCode);

    if ($downloadCode !== 0) {
        $cleanup($jobDir);
        pdFlash(
            'YouTube audio import failed. Check that yt-dlp and FFmpeg are installed and the video is accessible.',
            'error'
        );
        pdRedirect();
    }

    $files = glob($jobDir . '/*.mp3') ?: [];

    if (count($files) !== 1 || !is_file($files[0])) {
        $cleanup($jobDir);
        pdFlash('YouTube audio import did not produce a valid MP3 file.', 'error');
        pdRedirect();
    }

    $source = $files[0];
    $sizeBytes = (int)filesize($source);

    if ($sizeBytes <= 0 || $sizeBytes > $musicMaxBytes) {
        $cleanup($jobDir);
        pdFlash('The imported MP3 is larger than the 64 MB server limit.', 'error');
        pdRedirect();
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);

    if (!in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'], true)) {
        $cleanup($jobDir);
        pdFlash('The imported file is not a valid MP3.', 'error');
        pdRedirect();
    }

    if (!is_dir($musicDir) && !mkdir($musicDir, 0770, true) && !is_dir($musicDir)) {
        $cleanup($jobDir);
        pdFlash('Music storage is unavailable.', 'error');
        pdRedirect();
    }

    $stored = bin2hex(random_bytes(20)) . '.mp3';
    $target = $musicDir . '/' . $stored;

    if (!rename($source, $target)) {
        $cleanup($jobDir);
        pdFlash('Cannot store the imported MP3.', 'error');
        pdRedirect();
    }

    @chmod($target, 0640);
    $cleanup($jobDir);

    $baseUrl = rtrim(
        (string)(
            getenv('MUCHO_ACCOUNT_URL')
            ?: ('https://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))
        ),
        '/'
    );

    $downloadUrl = $baseUrl . '/music/' . rawurlencode($stored);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'INSERT INTO songs
                (name, author_id, author_name, size, download_url, is_verified)
             VALUES
                (:name, :author_id, :author_name, :size, :download_url, 0)'
        );

        $stmt->execute([
            'name' => $title,
            'author_id' => $accountId,
            'author_name' => $artist,
            'size' => round($sizeBytes / 1024 / 1024, 2),
            'download_url' => $downloadUrl,
        ]);

        $songId = (int)$db->lastInsertId();
        $db->commit();

        pdFlash(
            'YouTube track #' . $songId . ' imported and published.'
        );
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        @unlink($target);
        error_log('[Mucho Dashboard] YouTube import: ' . $e->getMessage());
        pdFlash('Import failed. Please try again.', 'error');
    }

    pdRedirect();
}

$flash = pdFlash();
$account = pdAccountFromSession();
$search = trim((string)($_GET['search'] ?? ''));
$profileUsername = trim((string)($_GET['u'] ?? ''));

$profile = null;
$profileLevels = [];
$profileSongs = [];

if ($profileUsername !== '') {
    if (mb_strlen($profileUsername, 'UTF-8') <= 20) {
        $profile = pdProfile($db, $profileUsername);
        if ($profile) {
            $profileLevels = pdPlayerLevels($db, (int)$profile['account_id']);
            $profileSongs = pdPlayerSongs($db, (int)$profile['account_id']);
        }
    }
}

$players = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    try {
        $stmt = $db->prepare(
            'SELECT
                a.account_id,
                a.username,
                COALESCE(r.code, "user") AS role_code,
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.icon_id, 1) AS icon_id,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.glow, 0) AS glow,
                COALESCE((SELECT c.tag
                          FROM mucho_clan_members cm
                          INNER JOIN mucho_clans c ON c.clan_id = cm.clan_id
                          WHERE cm.account_id = a.account_id
                          LIMIT 1), "") AS clan_tag
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             LEFT JOIN profiles p ON p.account_id = a.account_id
             WHERE a.is_active = 1
               AND a.is_banned = 0
               AND a.username LIKE :query
             ORDER BY COALESCE(p.stars, 0) DESC, a.account_id ASC
             LIMIT 24'
        );
        $stmt->execute(['query' => $like]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $players = [];
    }
}

$topPlayers = pdTopPlayers($db);
$recentSongs = pdRecentSongs($db, 12);
$mySongs = $account ? pdPlayerSongs($db, (int)$account['id'], 20) : [];

$dashboardStats = [
    'players' => 0,
    'levels' => 0,
    'songs' => 0,
];

try {
    $statsStmt = $db->query(
        'SELECT
            (SELECT COUNT(*) FROM accounts WHERE is_active = 1 AND is_banned = 0) AS players,
            (SELECT COUNT(*) FROM levels WHERE is_deleted = 0 AND is_unlisted = 0) AS levels,
            (SELECT COUNT(*) FROM songs WHERE is_verified = 1) AS songs'
    );
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $dashboardStats['players'] = (int)($stats['players'] ?? 0);
    $dashboardStats['levels'] = (int)($stats['levels'] ?? 0);
    $dashboardStats['songs'] = (int)($stats['songs'] ?? 0);
} catch (Throwable) {
}

$loginAntiBot = (!$account && !$profile)
    ? SoftAntiBot::issue('login')
    : null;

$uploadAntiBot = ($account && !Turnstile::enabled())
    ? SoftAntiBot::issue('upload')
    : null;
$youtubeAntiBot = ($account && !Turnstile::enabled())
    ? SoftAntiBot::issue('upload-youtube')
    : null;

$loginTurnstile = (!$account && !$profile && Turnstile::enabled());
$uploadTurnstile = ($account && Turnstile::enabled());
?>
<!doctype html>
<html lang="en">
<head>
<link rel="icon" href="/assets/muchocore-dashboard-logo.jpg?v=1" type="image/jpeg">
<link rel="apple-touch-icon" href="/assets/muchocore-dashboard-logo.jpg?v=1">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#090b10">
<link rel="preconnect" href="https://gdicon.oat.zone" crossorigin>
<meta name="description" content="<?=pdH($serverName)?> player dashboard, music uploads and player profiles">
<title><?=pdH($serverName)?> · Player Dashboard</title>
<style>
:root{
    --bg:#07090f;
    --bg2:#0b0f17;
    --panel:#0f1520;
    --panel-soft:#111a27;
    --line:#263246;
    --line-soft:#1d2839;
    --text:#f4f7ff;
    --muted:#8e9bb0;
    --muted-2:#708098;
    --accent:#7968ff;
    --accent-2:#a89dff;
    --green:#42df9e;
    --danger:#ff7185;
    --shadow:0 18px 55px rgba(0,0,0,.28);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
    margin:0;
    min-height:100vh;
    color:var(--text);
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:
        radial-gradient(circle at 7% -5%,rgba(121,104,255,.16),transparent 31%),
        radial-gradient(circle at 92% 4%,rgba(66,223,158,.06),transparent 22%),
        linear-gradient(180deg,var(--bg),var(--bg2));
}
body::before{
    content:"";
    position:fixed;
    inset:auto auto -180px -180px;
    width:430px;
    height:430px;
    border-radius:50%;
    background:rgba(121,104,255,.10);
    filter:blur(90px);
    pointer-events:none;
}
body.dashboard-page{padding-bottom:10px}
a{color:inherit;text-decoration:none}
button,input{font:inherit}
.shell{
    width:min(1180px,calc(100% - 32px));
    margin:0 auto;
    padding:18px 0 44px;
}
.topbar{
    position:sticky;
    top:12px;
    z-index:40;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    min-height:68px;
    padding:10px 12px;
    border:1px solid rgba(43,54,73,.92);
    border-radius:18px;
    background:rgba(8,12,19,.82);
    backdrop-filter:blur(20px);
    box-shadow:0 14px 44px rgba(0,0,0,.24);
}
.brand{
    width:48px;
    height:48px;
    min-width:48px;
    display:grid;
    place-items:center;
    flex:0 0 auto;
    border-radius:13px;
    line-height:0;
}
.mucho-brand-logo{
    display:block;
    width:48px;
    height:48px;
    max-width:48px;
    max-height:48px;
    flex:0 0 48px;
    object-fit:contain;
    object-position:center;
    border-radius:12px;
    filter:drop-shadow(0 8px 20px rgba(0,0,0,.24));
    transition:transform .16s ease,filter .16s ease;
}
.brand:hover .mucho-brand-logo{
    transform:translateY(-1px) scale(1.025);
    filter:drop-shadow(0 10px 24px rgba(0,0,0,.30));
}
.brand-copy{
    display:grid;
    gap:2px;
    min-width:0;
}
.brand-copy b{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:13px;
    line-height:1;
    letter-spacing:-.2px;
}
.brand-copy small{
    color:var(--muted-2);
    font-size:9px;
    font-weight:800;
    letter-spacing:.12em;
}
.nav{
    min-width:0;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
    flex-wrap:wrap;
}
.nav form{margin:0;display:flex}
.nav a,.nav button{
    appearance:none;
    border:1px solid var(--line);
    background:#101724;
    color:#cbd3e2;
    padding:9px 11px;
    border-radius:10px;
    font-size:11px;
    font-weight:800;
    white-space:nowrap;
    cursor:pointer;
    transition:.16s ease;
}
.nav a:hover,.nav button:hover{
    color:#fff;
    border-color:#465373;
    transform:translateY(-1px);
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    min-height:40px;
    padding:10px 14px;
    border:1px solid transparent;
    border-radius:11px;
    color:#fff;
    background:linear-gradient(135deg,var(--accent),#6251e8);
    font-size:11px;
    font-weight:850;
    box-shadow:0 8px 22px rgba(79,64,200,.22);
    cursor:pointer;
    transition:.16s ease;
}
.btn:hover{transform:translateY(-1px);filter:brightness(1.05)}
.btn.alt{
    color:#d8deea;
    background:#111a27;
    border-color:var(--line);
    box-shadow:none;
}
.panel{
    min-width:0;
    background:linear-gradient(160deg,rgba(17,24,36,.97),rgba(11,16,25,.97));
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:var(--shadow);
    padding:22px;
}
.hero{
    display:grid;
    grid-template-columns:minmax(0,1.18fr) minmax(360px,.82fr);
    gap:14px;
    margin-top:16px;
    align-items:stretch;
}
.hero > .panel{min-height:100%}
.hero-main{
    display:flex;
    flex-direction:column;
    justify-content:center;
    min-height:330px;
}
.hero-main .hero-title{max-width:700px}
.hero-kicker{
    display:inline-flex;
    align-items:center;
    gap:7px;
    width:max-content;
    max-width:100%;
    padding:5px 9px;
    border:1px solid rgba(121,104,255,.24);
    border-radius:999px;
    background:rgba(121,104,255,.08);
    color:#bfb7ff;
    font-size:9px;
    font-weight:850;
    letter-spacing:.12em;
    text-transform:uppercase;
}
.hero-kicker::before{
    content:"";
    width:6px;
    height:6px;
    border-radius:50%;
    background:#42df9e;
    box-shadow:0 0 0 4px rgba(66,223,158,.08),0 0 14px rgba(66,223,158,.45);
}
.hero-copy{
    max-width:680px;
    font-size:14px;
    line-height:1.65;
}
.hero-actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:18px;
}
.hero-note{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:15px;
}
.hero-note span{
    padding:6px 8px;
    border:1px solid var(--line-soft);
    border-radius:8px;
    background:rgba(255,255,255,.02);
    color:#7f8da3;
    font-size:9px;
    font-weight:800;
}
.portal-panel{
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}
.portal-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}
.portal-head-copy{min-width:0}
.portal-eyebrow{
    color:#a89dff;
    font-size:9px;
    font-weight:850;
    letter-spacing:.12em;
    text-transform:uppercase;
}
.portal-title{
    margin:5px 0 0;
    font-size:22px;
    line-height:1.05;
    letter-spacing:-.7px;
}
.portal-description{
    margin:7px 0 0;
    color:var(--muted);
    font-size:11px;
    line-height:1.55;
}
.portal-mark{
    width:38px;
    height:38px;
    display:grid;
    place-items:center;
    flex:0 0 auto;
    border-radius:11px;
    border:1px solid rgba(121,104,255,.22);
    background:linear-gradient(145deg,rgba(121,104,255,.16),rgba(121,104,255,.05));
    color:#b7afff;
    font-size:15px;
    font-weight:900;
    box-shadow:inset 0 1px rgba(255,255,255,.04);
}
.form-divider{
    height:1px;
    margin:15px 0 14px;
    background:linear-gradient(90deg,transparent,var(--line),transparent);
}
.upload-summary{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:7px;
    margin:0 0 13px;
}
.upload-summary span{
    padding:8px 9px;
    border-radius:9px;
    background:#0b1320;
    border:1px solid #1f2b3e;
    color:#8493a9;
    font-size:9px;
    line-height:1.35;
}
.upload-summary b{
    display:block;
    color:#dfe5f2;
    font-size:11px;
    margin-bottom:2px;
}
.dashboard-stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    margin-top:14px;
}
.dashboard-stat{
    position:relative;
    min-width:0;
    padding:15px;
    overflow:hidden;
    border:1px solid var(--line);
    border-radius:15px;
    background:linear-gradient(145deg,rgba(17,24,36,.96),rgba(12,18,28,.96));
    box-shadow:0 12px 32px rgba(0,0,0,.16);
}
.dashboard-stat::after{
    content:"";
    position:absolute;
    width:90px;
    height:90px;
    right:-42px;
    bottom:-54px;
    border-radius:50%;
    background:rgba(121,104,255,.10);
    filter:blur(20px);
}
.dashboard-stat-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}
.dashboard-stat-icon{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:8px;
    border:1px solid rgba(121,104,255,.20);
    background:rgba(121,104,255,.08);
    color:#aaa1ff;
    font-size:12px;
    font-weight:900;
}
.dashboard-stat-label{
    color:#75849a;
    font-size:9px;
    font-weight:850;
    letter-spacing:.08em;
    text-transform:uppercase;
}
.dashboard-stat-value{
    margin-top:12px;
    font-size:22px;
    font-weight:900;
    letter-spacing:-.7px;
}
.dashboard-stat-detail{
    margin-top:3px;
    color:#64738a;
    font-size:9px;
}
.section-divider{
    display:flex;
    align-items:center;
    gap:10px;
    margin:24px 0 11px;
    color:#6f7e95;
    font-size:9px;
    font-weight:850;
    letter-spacing:.12em;
    text-transform:uppercase;
}
.section-divider::before,.section-divider::after{
    content:"";
    height:1px;
    flex:1;
    background:linear-gradient(90deg,transparent,var(--line),transparent);
}
.featured-row{
    display:grid;
    grid-template-columns:1.08fr .92fr;
    gap:12px;
}
.featured-card{
    min-width:0;
    display:flex;
    align-items:center;
    gap:15px;
    padding:16px;
    border:1px solid var(--line);
    border-radius:15px;
    background:linear-gradient(145deg,#111a27,#0d141f);
}
.featured-card img{
    width:74px;
    height:74px;
    flex:0 0 auto;
}
.featured-meta{min-width:0}
.featured-label{
    color:#7f8ea4;
    font-size:9px;
    font-weight:850;
    letter-spacing:.1em;
    text-transform:uppercase;
}
.featured-name{
    margin-top:4px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:18px;
    font-weight:900;
    letter-spacing:-.4px;
}
.featured-sub{
    margin-top:4px;
    color:#76869d;
    font-size:10px;
}
.link-panel{
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:8px;
    padding:16px;
    border:1px solid var(--line);
    border-radius:15px;
    background:linear-gradient(145deg,#0f1825,#0b131f);
}
.link-panel b{font-size:13px}
.link-panel p{margin:0;color:#77879e;font-size:10px;line-height:1.55}
.link-panel .btn{width:max-content}
.music-meta{
    display:flex;
    align-items:center;
    gap:8px;
}
.music-disc{
    width:30px;
    height:30px;
    display:grid;
    place-items:center;
    flex:0 0 auto;
    border-radius:9px;
    border:1px solid #29364c;
    background:linear-gradient(145deg,#172235,#0f1825);
    color:#a69dff;
    font-size:11px;
    font-weight:900;
}
.music-text{min-width:0}
.portal-panel form .btn{width:100%}
.hero-title{
    margin:8px 0 12px;
    max-width:760px;
    font-size:clamp(34px,5vw,52px);
    line-height:.98;
    letter-spacing:-2px;
}
.hero-title span{
    background:linear-gradient(90deg,#fff,var(--accent-2));
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
}
.muted{color:var(--muted)}
.small{font-size:11px}
.section{margin-top:18px}
.section-head{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:12px;
    margin:0 0 10px;
}
.section-head h2{
    margin:0;
    font-size:18px;
    line-height:1.1;
    letter-spacing:-.4px;
}
.search{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:8px;
    margin-top:20px;
}
.search input,
input[type=text],
input[type=password],
input[type=file]{
    width:100%;
    min-width:0;
    border:1px solid #28364a;
    background:#080e16;
    color:#fff;
    border-radius:11px;
    padding:11px 12px;
    outline:none;
}
.search input:focus,
input[type=text]:focus,
input[type=password]:focus,
input[type=file]:focus{
    border-color:#7668e6;
    box-shadow:0 0 0 3px rgba(122,104,255,.10);
}
.profile-mini{
    display:flex;
    align-items:center;
    gap:13px;
    padding:15px;
    border:1px solid #27354a;
    border-radius:14px;
    background:#0d1521;
}
.profile-mini img,.profile-avatar{
    object-fit:contain;
    filter:drop-shadow(0 8px 16px rgba(0,0,0,.35));
}
.profile-mini img{width:72px;height:72px}
.profile-name{font-weight:900;font-size:17px}
.role{
    display:inline-flex;
    margin-top:5px;
    padding:3px 7px;
    border-radius:999px;
    background:rgba(122,104,255,.12);
    border:1px solid rgba(122,104,255,.22);
    color:#c8c0ff;
    font-size:9px;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.04em;
}
.stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
    margin-top:13px;
}
.stat{
    min-width:0;
    padding:11px;
    border:1px solid #223044;
    border-radius:12px;
    background:#0d1520;
}
.stat b{display:block;font-size:20px;letter-spacing:-.5px}
.stat span{display:block;margin-top:2px;font-size:9px;color:#8493aa;text-transform:uppercase;letter-spacing:.05em}
.grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
}
.player-card{
    min-width:0;
    display:block;
    padding:15px;
    background:linear-gradient(160deg,#101825,#0d141f);
    border:1px solid var(--line);
    border-radius:15px;
    transition:.18s ease;
}
.player-card:hover{
    transform:translateY(-2px);
    border-color:#3b4a68;
    box-shadow:0 16px 34px rgba(0,0,0,.20);
}
.player-card-head{
    min-width:0;
    display:flex;
    gap:12px;
    align-items:center;
}
.player-card img{width:60px;height:60px;flex:0 0 auto}
.player-meta{min-width:0}
.player-meta b{
    display:block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:14px;
}
.rank{font-size:10px;color:#7888a1;margin-top:3px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.chip{
    font-size:10px;
    padding:4px 7px;
    border-radius:999px;
    background:#121e2d;
    border:1px solid #26364d;
    color:#b8c5d8;
}
.profile{
    display:grid;
    grid-template-columns:minmax(270px,.72fr) minmax(0,1.28fr);
    gap:14px;
}
.profile-summary{text-align:center}
.profile-avatar{
    width:160px;
    height:160px;
    display:block;
    margin:4px auto 10px;
}
.profile-title{font-size:28px;letter-spacing:-1px;margin:0}
.profile-id{font-size:11px;color:#76859e;margin-top:4px}
.profile-stats{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
    margin-top:15px;
}
.profile-stat{
    min-width:0;
    padding:10px;
    border:1px solid #263348;
    background:#0e1622;
    border-radius:11px;
}
.profile-stat b{display:block;font-size:17px}
.profile-stat span{display:block;margin-top:2px;font-size:9px;color:#75859c;text-transform:uppercase}
.list{display:grid;gap:8px}
.list-item{
    min-width:0;
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
    padding:12px 13px;
    border-radius:12px;
    border:1px solid #243146;
    background:#0e1622;
}
.list-item > span:first-child{min-width:0}
.list-item b{
    display:block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:13px;
}
.list-item small{
    display:block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#7788a0;
    margin-top:3px;
}
.badge{
    flex:0 0 auto;
    display:inline-flex;
    padding:4px 8px;
    border-radius:999px;
    background:#192438;
    border:1px solid #2a3b57;
    color:#b8c8df;
    font-size:9px;
    font-weight:800;
}
.badge.ok{
    background:rgba(66,223,158,.10);
    border-color:rgba(66,223,158,.25);
    color:#7ae8b6;
}
.notice{
    padding:12px 14px;
    border-radius:12px;
    margin-top:14px;
    background:#101a28;
    border:1px solid #26354b;
    font-size:12px;
}
.notice.ok{border-color:rgba(66,223,158,.28)}
.notice.error{border-color:rgba(255,111,131,.32)}
.upload{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(230px,.72fr);
    gap:12px;
}
.field{display:grid;gap:7px;margin-bottom:10px}
.field:last-child{margin-bottom:0}
label{font-size:10px;color:#9cabc0;font-weight:800}
.antibot-field{
    position:absolute!important;
    left:-10000px!important;
    top:auto!important;
    width:1px!important;
    height:1px!important;
    overflow:hidden!important;
    opacity:0!important;
}
.file{padding:10px!important}
.turnstile-box{
    margin:10px 0 12px;
    min-height:66px;
    display:flex;
    align-items:center;
    justify-content:flex-start;
}
.cooldown{
    padding:11px 12px;
    border-radius:11px;
    background:#0b1320;
    border:1px dashed #34455f;
    color:#9fb0c7;
    font-size:11px;
}
.footer{
    margin-top:28px;
    padding-top:4px;
    text-align:center;
    color:#6f7f97;
    font-size:11px;
    line-height:1.7;
}
.footer a{color:#8e9db4}
.empty{
    padding:22px;
    text-align:center;
    border:1px dashed #2e3c51;
    border-radius:13px;
    color:#72829a;
}
body.dashboard-page .mucho-theme-toggle{
    top:14px;
    right:14px;
}
body.dashboard-page .topbar{
    padding-right:58px;
}
@media(max-width:1020px){
    .hero{grid-template-columns:1fr}
    .profile{grid-template-columns:1fr}
    .upload{grid-template-columns:1fr}
    .dashboard-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .featured-row{grid-template-columns:1fr}
    .grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:720px){
    .shell{width:min(100% - 18px,1180px);padding-top:10px}
    .topbar{
        position:static;
        align-items:stretch;
        flex-direction:column;
        gap:10px;
        padding:10px 10px!important;
    }
    .brand{min-height:42px}
    .nav{
        justify-content:flex-start;
        flex-wrap:nowrap;
        overflow-x:auto;
        padding-bottom:2px;
        scrollbar-width:none;
    }
    .nav::-webkit-scrollbar{display:none}
    .nav a,.nav button{flex:0 0 auto}
    .hero-title{font-size:34px;letter-spacing:-1.4px}
    .search{grid-template-columns:1fr}
    .grid{grid-template-columns:1fr}
    .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .dashboard-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .upload-summary{grid-template-columns:1fr}
    .hero-actions .btn{flex:1 1 150px}
    .portal-panel form .btn{width:100%}
    .profile-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .section-head{align-items:flex-start;flex-direction:column;gap:6px}
    .list-item{align-items:flex-start}
    .list-item .badge{margin-top:1px}
    .panel{padding:17px}
    .brand{width:44px;height:44px;min-width:44px}
    .mucho-brand-logo{width:44px;height:44px;max-width:44px;max-height:44px;flex-basis:44px}
}
@media(prefers-reduced-motion:reduce){
    *{scroll-behavior:auto!important;transition:none!important}
}
</style>
<?php if (Turnstile::enabled()): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<link rel="stylesheet" href="/muchocore-theme.css?v=3">
<script src="/muchocore-theme.js?v=3" defer></script>
</head>
<body class="dashboard-page">
<div class="shell">

<header class="topbar">
    <a class="brand" href="/dashboard" aria-label="<?=pdH($serverName)?>"><img class="mucho-brand-logo" src="/assets/muchocore-dashboard-logo.jpg?v=1" alt="<?=pdH($serverName)?>" width="48" height="48" decoding="async"></a>
    <nav class="nav">
        <a href="/dashboard">Discover</a>
        <a href="#players">Players</a>
        <a href="/dashboard/clans.php">Clans</a>
        <a href="#music">Music</a>
        <?php if ($account): ?>
            <a href="#upload">Upload</a>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?=pdH(pdCsrf())?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Log out</button>
            </form>
        <?php else: ?>
            <a class="btn" href="#upload">Sign in & upload</a>
        <?php endif; ?>
    </nav>
</header>

<?php if ($flash): ?>
    <div class="notice <?=pdH($flash['type'])?>"><?=pdH($flash['message'])?></div>
<?php endif; ?>

<?php if ($profile): ?>

<section class="section">
    <div class="section-head">
        <h2>Player profile</h2>
        <a class="btn alt" href="/dashboard">← Discover</a>
    </div>

    <div class="profile">
        <div class="panel profile-summary">
            <img
                class="profile-avatar"
                src="<?=pdH(pdIconUrl($profile, 160))?>"
                alt=""
                loading="lazy"
                referrerpolicy="no-referrer"
            >
            <h1 class="profile-title"><?=pdH($profile['username'])?></h1>
            <?php if ((int)($profile['clan_id'] ?? 0) > 0): ?>
            <div style="margin-top:6px">
                <a class="badge" href="/dashboard/clans.php?clan=<?=((int)$profile['clan_id'])?>">
                    [<?=pdH($profile['clan_tag'])?>] Clan
                </a>
            </div>
            <?php endif; ?>
            <div class="profile-id">
                Account #<?=pdH($profile['account_id'])?>
                · User #<?=pdH($profile['user_id'])?>
            </div>
            <div class="role"><?=pdH(str_replace('_', ' ', (string)$profile['role_code']))?></div>
            <div class="profile-stats">
                <div class="profile-stat"><b><?=pdFormatNumber($profile['stars'])?></b><span>Stars</span></div>
                <div class="profile-stat"><b><?=pdFormatNumber($profile['moons'])?></b><span>Moons</span></div>
                <div class="profile-stat"><b><?=pdFormatNumber($profile['demons'])?></b><span>Demons</span></div>
                <div class="profile-stat"><b><?=pdFormatNumber($profile['diamonds'])?></b><span>Diamonds</span></div>
                <div class="profile-stat"><b><?=pdFormatNumber($profile['creator_points'])?></b><span>Creator</span></div>
                <div class="profile-stat"><b><?=pdFormatNumber($profile['levels_count'])?></b><span>Levels</span></div>
            </div>
        </div>

        <div class="panel">
            <div class="section-head">
                <h2>Creator activity</h2>
                <span class="badge"><?=pdFormatNumber($profile['level_downloads'])?> level downloads</span>
            </div>

            <?php if ($profileLevels): ?>
                <div class="list">
                    <?php foreach ($profileLevels as $level): ?>
                        <div class="list-item">
                            <span>
                                <b><?=pdH($level['name'])?></b>
                                <small>#<?=pdH($level['level_id'])?> · <?=pdFormatNumber($level['downloads'])?> downloads · <?=pdFormatNumber($level['likes'])?> likes</small>
                            </span>
                            <span class="badge"><?=pdFormatNumber($level['stars'])?> ★</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">No published levels yet.</div>
            <?php endif; ?>

            <div class="section-head" style="margin-top:18px">
                <h2>Music</h2>
                <span class="badge"><?=pdFormatNumber($profile['verified_songs'])?> verified</span>
            </div>

            <?php if ($profileSongs): ?>
                <div class="list">
                    <?php foreach ($profileSongs as $song): ?>
                        <div class="list-item">
                            <span>
                                <b><?=pdH($song['name'])?></b>
                                <small><?=pdH($song['author_name'])?> · #<?=pdH($song['id'])?> · <?=pdH($song['size'])?> MB</small>
                            </span>
                            <?php if ((int)$song['is_verified'] === 1): ?>
                                <span class="badge ok">Verified</span>
                            <?php else: ?>
                                <span class="badge ok">Published</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">No uploaded music yet.</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php else: ?>

<section class="hero">
    <div class="panel hero-main">
        <div class="hero-kicker">Player portal · Geometry Dash 2.2</div>
        <h1 class="hero-title">Meet the <span><?=pdH($serverName)?></span> community.</h1>
        <p class="muted hero-copy">
            Search players, explore creator activity, browse community music,
            and manage your own uploads from one clean dashboard.
        </p>

        <div class="hero-actions">
            <a class="btn" href="#players">Explore players</a>
            <a class="btn alt" href="/dashboard/clans.php">Explore clans</a>
            <a class="btn alt" href="#music">Browse music</a>
        </div>

        <div class="hero-note">
            <span>Fast player search</span>
            <span>Public profiles</span>
            <span>Clan tags</span>
            <span>1 upload / 3 min</span>
        </div>

        <form class="search" method="get" action="/dashboard">
            <input
                type="text"
                name="search"
                maxlength="20"
                value="<?=pdH($search)?>"
                placeholder="Search a player username..."
                autocomplete="off"
            >
            <button class="btn" type="submit">Search players</button>
        </form>
    </div>

    <div class="panel portal-panel" id="upload">
        <div>
            <div class="portal-head">
                <div class="portal-head-copy">
                    <div class="portal-eyebrow"><?= $account ? 'Music upload' : 'Account access' ?></div>
                    <h2 class="portal-title"><?= $account ? 'Upload a new track' : 'Sign in to upload' ?></h2>
                    <p class="portal-description">
                        <?= $account
                            ? 'Share an MP3 with the community. New tracks are published immediately.'
                            : 'Use your Geometry Dash account so ownership stays tied to the real player.'
                        ?>
                    </p>
                </div>
                <div class="portal-mark">♪</div>
            </div>
            <div class="form-divider"></div>
        </div>

        <?php if (!$account): ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?=pdH(pdCsrf())?>">
                <input type="hidden" name="action" value="login">
                <div class="field">
                    <label>Username</label>
                    <input type="text" name="username" maxlength="20" autocomplete="username" required>
                </div>

                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" maxlength="256" autocomplete="current-password" required>
                </div>

                <?php if ($loginTurnstile): ?>
                    <div class="turnstile-box">
                        <div class="cf-turnstile" data-sitekey="<?=pdH(Turnstile::siteKey())?>" data-theme="auto" data-action="login"></div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="antibot_token" value="<?=pdH($loginAntiBot['token'] ?? '')?>">
                    <label class="antibot-field" aria-hidden="true">Website
                        <input type="text" name="website" tabindex="-1" autocomplete="off">
                    </label>
                <?php endif; ?>

                <button class="btn" type="submit">Sign in to upload</button>
            </form>
        <?php else: ?>
            <div class="muted small">MUSIC UPLOAD · SIGNED IN</div>
            <h2 style="margin:6px 0 8px">Add a new track</h2>
            <div class="cooldown" style="margin-bottom:11px">
                Signed in as <b><?=pdH($account['username'])?></b> · one track every 3 minutes
            </div>

            <div class="music-tabs" role="tablist" aria-label="Music upload method" style="display:flex;gap:8px;margin:0 0 14px">
                <button class="btn music-tab is-active" type="button" role="tab" aria-selected="true" data-music-tab="file">Upload MP3</button>
                <button class="btn alt music-tab" type="button" role="tab" aria-selected="false" data-music-tab="youtube">YouTube URL</button>
            </div>

            <div class="upload-summary">
                <span><b>Ownership</b>GD account</span>
                <span><b>Publishing</b>Immediate</span>
                <span><b>Limit</b>64 MB</span>
            </div>

            <div data-music-panel="file">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?=pdH(pdCsrf())?>">
                <input type="hidden" name="action" value="upload">
                <?php if ($uploadTurnstile): ?>
                    <div class="turnstile-box">
                        <div class="cf-turnstile" data-sitekey="<?=pdH(Turnstile::siteKey())?>" data-theme="auto" data-action="upload"></div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="antibot_token" value="<?=pdH($uploadAntiBot['token'] ?? '')?>">
                    <label class="antibot-field" aria-hidden="true">Website
                        <input type="text" name="website" tabindex="-1" autocomplete="off">
                    </label>
                <?php endif; ?>

                <div class="upload">
                    <div>
                        <div class="field">
                            <label>Song title</label>
                            <input type="text" name="title" maxlength="128" required>
                        </div>

                        <div class="field">
                            <label>Artist</label>
                            <input type="text" name="artist" maxlength="128" value="<?=pdH($account['username'])?>" required>
                        </div>
                    </div>

                    <div>
                        <div class="field">
                            <label>MP3 file</label>
                            <input class="file" type="file" name="music_file" accept=".mp3,audio/mpeg" required>
                        </div>
                        <button class="btn" type="submit">Upload MP3</button>
                    </div>
                </div>
            </form>
            </div>

            <div data-music-panel="youtube" hidden>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?=pdH(pdCsrf())?>">
                    <input type="hidden" name="action" value="upload_youtube">
                    <?php if ($uploadTurnstile): ?>
                        <div class="turnstile-box">
                            <div class="cf-turnstile" data-sitekey="<?=pdH(Turnstile::siteKey())?>" data-theme="auto" data-action="upload-youtube"></div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="antibot_token" value="<?=pdH($youtubeAntiBot['token'] ?? '')?>">
                        <label class="antibot-field" aria-hidden="true">Website
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </label>
                    <?php endif; ?>

                    <div class="field">
                        <label>YouTube URL</label>
                        <input type="url" name="youtube_url" maxlength="512" placeholder="https://www.youtube.com/watch?v=..." required>
                    </div>

                    <div class="upload">
                        <div>
                            <div class="field">
                                <label>Song title <span class="muted small">(optional)</span></label>
                                <input type="text" name="youtube_title" maxlength="128" placeholder="Detected from YouTube">
                            </div>
                        </div>
                        <div>
                            <div class="field">
                                <label>Artist <span class="muted small">(optional)</span></label>
                                <input type="text" name="youtube_artist" maxlength="128" placeholder="Detected from YouTube">
                            </div>
                            <button class="btn" type="submit">Import from YouTube</button>
                        </div>
                    </div>

                    <p class="muted small" style="margin-top:10px">
                        Only import audio you have permission to use. YouTube import requires <code>yt-dlp</code> and FFmpeg on the server.
                    </p>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="dashboard-stats" aria-label="Community statistics">
    <div class="dashboard-stat">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-label">Players</span>
            <span class="dashboard-stat-icon">◈</span>
        </div>
        <div class="dashboard-stat-value"><?=pdFormatNumber($dashboardStats['players'])?></div>
        <div class="dashboard-stat-detail">Active community accounts</div>
    </div>
    <div class="dashboard-stat">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-label">Levels</span>
            <span class="dashboard-stat-icon">◆</span>
        </div>
        <div class="dashboard-stat-value"><?=pdFormatNumber($dashboardStats['levels'])?></div>
        <div class="dashboard-stat-detail">Published levels available</div>
    </div>
    <div class="dashboard-stat">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-label">Music</span>
            <span class="dashboard-stat-icon">♫</span>
        </div>
        <div class="dashboard-stat-value"><?=pdFormatNumber($dashboardStats['songs'])?></div>
        <div class="dashboard-stat-detail">Verified community tracks</div>
    </div>
    <div class="dashboard-stat">
        <div class="dashboard-stat-top">
            <span class="dashboard-stat-label">Upload rate</span>
            <span class="dashboard-stat-icon">3m</span>
        </div>
        <div class="dashboard-stat-value">1 / 3m</div>
        <div class="dashboard-stat-detail">Per signed-in player</div>
    </div>
</section>

<section class="section">
    <div class="section-divider">Community snapshot</div>
    <div class="featured-row">
        <?php if (!empty($topPlayers[0])): ?>
            <a class="featured-card" href="/dashboard?u=<?=rawurlencode((string)$topPlayers[0]['username'])?>">
                <img src="<?=pdH(pdIconUrl($topPlayers[0], 90))?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                <div class="featured-meta">
                    <div class="featured-label">Current top player</div>
                    <div class="featured-name"><?=pdH($topPlayers[0]['username'])?></div>
                    <div class="featured-sub"><?=pdFormatNumber($topPlayers[0]['stars'])?> Stars · <?=pdFormatNumber($topPlayers[0]['demons'])?> Demons · <?=pdFormatNumber($topPlayers[0]['creator_points'])?> Creator</div>
                </div>
            </a>
        <?php else: ?>
            <div class="featured-card">
                <div class="featured-meta">
                    <div class="featured-label">Community</div>
                    <div class="featured-name">No player data yet</div>
                    <div class="featured-sub">Create a GD account to appear here.</div>
                </div>
            </div>
        <?php endif; ?>
        <div class="link-panel">
            <b>Build your profile</b>
            <p>Open any player card to inspect stats, creator activity, published levels and music history.</p>
            <a class="btn alt" href="#players">View leaderboard</a>
        </div>
    </div>
</section>

<?php if ($search !== ''): ?>
<section class="section" id="player-results">
    <div class="section-head">
        <h2>Player results</h2>
        <span class="muted small"><?=count($players)?> matches shown</span>
    </div>

    <?php if ($players): ?>
        <div class="grid">
            <?php foreach ($players as $player): ?>
                <a class="player-card" href="/dashboard?u=<?=rawurlencode((string)$player['username'])?>">
                    <div class="player-card-head">
                        <img
                            src="<?=pdH(pdIconUrl($player, 72))?>"
                            alt=""
                            loading="lazy"
                            referrerpolicy="no-referrer"
                        >
                        <div class="player-meta">
                            <b><?=pdH($player['username'])?></b>
                            <div class="rank">Account #<?=pdH($player['account_id'])?></div>
                            <span class="role"><?=pdH(str_replace('_', ' ', (string)$player['role_code']))?></span>
                        <?php if ((string)($player['clan_tag'] ?? '') !== ''): ?>
                        <span class="role">[<?=pdH($player['clan_tag'])?>]</span>
                        <?php endif; ?>
                        </div>
                    </div>
                    <div class="chips">
                        <span class="chip"><?=pdFormatNumber($player['stars'])?> Stars</span>
                        <span class="chip"><?=pdFormatNumber($player['moons'])?> Moons</span>
                        <span class="chip"><?=pdFormatNumber($player['demons'])?> Demons</span>
                        <span class="chip"><?=pdFormatNumber($player['creator_points'])?> Creator</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty">No matching players found.</div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="section" id="players">
    <div class="section-head">
        <h2>Community leaderboard</h2>
        <span class="muted small">Top players by stars</span>
    </div>

    <div class="grid">
        <?php foreach ($topPlayers as $index => $player): ?>
            <a class="player-card" href="/dashboard?u=<?=rawurlencode((string)$player['username'])?>">
                <div class="player-card-head">
                    <img
                        src="<?=pdH(pdIconUrl($player, 72))?>"
                        alt=""
                        loading="lazy"
                        referrerpolicy="no-referrer"
                    >
                    <div class="player-meta">
                        <b><?=pdH($player['username'])?></b>
                        <div class="rank">#<?=($index + 1)?> · Account #<?=pdH($player['account_id'])?></div>
                        <span class="role"><?=pdH(str_replace('_', ' ', (string)$player['role_code']))?></span>
                        <?php if ((string)($player['clan_tag'] ?? '') !== ''): ?>
                        <span class="role">[<?=pdH($player['clan_tag'])?>]</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="chips">
                    <span class="chip"><?=pdFormatNumber($player['stars'])?> Stars</span>
                    <span class="chip"><?=pdFormatNumber($player['moons'])?> Moons</span>
                    <span class="chip"><?=pdFormatNumber($player['demons'])?> Demons</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" id="music">
    <div class="section-head">
        <h2>Music feed</h2>
        <span class="muted small">Newest tracks from the community</span>
    </div>

    <div class="panel">
        <div class="list">
            <?php foreach ($recentSongs as $song): ?>
                <div class="list-item">
                    <span class="music-meta">
                        <span class="music-disc">♫</span>
                        <span class="music-text">
                            <b><?=pdH($song['name'])?></b>
                            <small>
                            <?=pdH($song['author_name'])?> · #<?=pdH($song['id'])?>
                            · <?=pdH($song['size'])?> MB
                            </small>
                        </span>
                    </span>
                    <?php if ((int)$song['is_verified'] === 1): ?>
                        <span class="badge ok">Verified</span>
                    <?php else: ?>
                        <span class="badge">Pending</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$recentSongs): ?>
                <div class="empty">No songs have been uploaded yet.</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php endif; ?>

<?php if ($account): ?>
<section class="section" id="my-music">
    <div class="section-head">
        <h2>Your uploads</h2>
        <span class="muted small">Account-owned music</span>
    </div>

    <div class="panel">
        <?php if ($mySongs): ?>
            <div class="list">
                <?php foreach ($mySongs as $song): ?>
                    <div class="list-item">
                        <span>
                            <b><?=pdH($song['name'])?></b>
                            <small>#<?=pdH($song['id'])?> · <?=pdH($song['author_name'])?> · <?=pdH($song['size'])?> MB</small>
                        </span>
                        <?php if ((int)$song['is_verified'] === 1): ?>
                            <span class="badge ok">Verified</span>
                        <?php else: ?>
                            <span class="badge ok">Published</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty">You have not uploaded any music yet.</div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<footer class="footer">
    <?=pdH($serverName)?> Player Dashboard
    <?php if ($serverByName !== '' && $socialUrl !== ''): ?>
    · Server by <a href="<?=pdH($socialUrl)?>" target="_blank" rel="noopener noreferrer"><?=pdH($serverByName)?></a>
    <?php endif; ?>
    · Powered by MuchoCore ·
    <a href="https://github.com/IZKGMD" target="_blank" rel="noopener noreferrer">GitHub</a>
</footer>

</div>
<script>
(() => {
    const root = document.querySelector('.music-tabs');
    if (!root) return;

    const tabs = [...document.querySelectorAll('[data-music-tab]')];
    const panels = [...document.querySelectorAll('[data-music-panel]')];

    function selectMusicTab(name) {
        tabs.forEach((tab) => {
            const active = tab.dataset.musicTab === name;
            tab.classList.toggle('is-active', active);
            tab.classList.toggle('alt', !active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.musicPanel !== name;
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => selectMusicTab(tab.dataset.musicTab));
    });
})();
</script>

</body>
</html>
