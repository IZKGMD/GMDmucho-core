<?php
declare(strict_types=1);

/**
 * MuchoCore shared-hosting bootstrap installer.
 *
 * Upload this single file to a fresh shared-hosting web directory with FTP
 * or the host's file manager. It downloads the latest published stable
 * shared-hosting package from GitHub, verifies the GitHub release digest,
 * extracts the package safely, and hands off to /shared-install.php.
 *
 * FTP credentials never need to be sent to MuchoGDPS.
 */

const REPOSITORY = 'IZKGMD/GMDmucho-core';
const RELEASES_API = 'https://api.github.com/repos/' . REPOSITORY . '/releases/latest';
const MAX_DOWNLOAD_BYTES = 256 * 1024 * 1024;
const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

$target = __DIR__;
$lockPath = $target . '/.muchocore-ftp-install.lock';
$maxTimeLimit = 0;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$isHttps = (string)($_SERVER['HTTPS'] ?? '') === 'on'
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');

if (!is_dir($target) || !is_writable($target)) {
    http_response_code(500);
    exit('The target directory is not writable by PHP.');
}

if (!$isHttps) {
    http_response_code(400);
    exit('Open this installer over HTTPS before starting the installation.');
}

if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    http_response_code(500);
    exit('Unable to start the installer session.');
}

if (empty($_SESSION['muchocore_ftp_install_csrf'])) {
    $_SESSION['muchocore_ftp_install_csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fail_install(string $message): never
{
    throw new RuntimeException($message);
}

function http_get(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            fail_install('Unable to initialize the HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MuchoCore-FTP-Installer/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            fail_install('GitHub download failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            fail_install('GitHub returned HTTP ' . $status . ' while resolving the stable release.');
        }
        if (strlen($body) > MAX_RESPONSE_BYTES) {
            fail_install('GitHub API response is unexpectedly large.');
        }

        return (string)$body;
    }

    if (!ini_get('allow_url_fopen')) {
        fail_install('This host needs either PHP cURL or allow_url_fopen enabled to download the MuchoCore release.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 60,
            'follow_location' => 1,
            'max_redirects' => 4,
            'header' => "User-Agent: MuchoCore-FTP-Installer/1.0\r\nAccept: application/vnd.github+json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        fail_install('Unable to reach GitHub from this hosting account.');
    }
    if (strlen($body) > MAX_RESPONSE_BYTES) {
        fail_install('GitHub API response is unexpectedly large.');
    }

    return $body;
}

function download_file(string $url, string $destination): int
{
    $handle = fopen($destination, 'wb');
    if ($handle === false) {
        fail_install('Unable to create a temporary release archive.');
    }

    $bytes = 0;
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                fail_install('Unable to initialize the release downloader.');
            }

            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'MuchoCore-FTP-Installer/1.0',
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($handle, &$bytes): int {
                    $length = strlen($chunk);
                    $bytes += $length;
                    if ($bytes > MAX_DOWNLOAD_BYTES) {
                        return 0;
                    }

                    $written = fwrite($handle, $chunk);
                    return $written === false ? 0 : $written;
                },
            ]);

            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($ok === false || $error !== '') {
                fail_install(
                    $bytes > MAX_DOWNLOAD_BYTES
                        ? 'The shared-hosting package is larger than the safety limit.'
                        : 'Release download failed: ' . ($error !== '' ? $error : 'unknown transfer error')
                );
            }
            if ($status < 200 || $status >= 300) {
                fail_install('GitHub returned HTTP ' . $status . ' while downloading the shared-hosting package.');
            }
        } else {
            if (!ini_get('allow_url_fopen')) {
                fail_install('This host needs either PHP cURL or allow_url_fopen enabled to download the MuchoCore release.');
            }

            $remote = @fopen($url, 'rb', false, stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 300,
                    'follow_location' => 1,
                    'max_redirects' => 4,
                    'header' => "User-Agent: MuchoCore-FTP-Installer/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]));

            if ($remote === false) {
                fail_install('Unable to download the shared-hosting package from GitHub.');
            }

            while (!feof($remote)) {
                $chunk = fread($remote, 1024 * 1024);
                if ($chunk === false) {
                    fclose($remote);
                    fail_install('The release download was interrupted.');
                }

                $bytes += strlen($chunk);
                if ($bytes > MAX_DOWNLOAD_BYTES) {
                    fclose($remote);
                    fail_install('The shared-hosting package is larger than the safety limit.');
                }

                if ($chunk !== '' && fwrite($handle, $chunk) === false) {
                    fclose($remote);
                    fail_install('Unable to write the downloaded release archive.');
                }
            }

            fclose($remote);
        }
    } finally {
        fclose($handle);
    }

    if ($bytes < 1) {
        fail_install('The downloaded shared-hosting package is empty.');
    }

    return $bytes;
}

function release_info(): array
{
    $payload = json_decode(http_get(RELEASES_API), true);
    if (!is_array($payload)) {
        fail_install('GitHub returned an invalid release response.');
    }

    $tag = (string)($payload['tag_name'] ?? '');
    if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $match)) {
        fail_install('GitHub did not return a stable semantic-version release.');
    }

    if (($payload['draft'] ?? true) || ($payload['prerelease'] ?? true)) {
        fail_install('The latest GitHub release is not a published stable release.');
    }

    $version = $match[1];
    $assetName = 'MuchoCore-v' . $version . '-shared-hosting.zip';
    $assets = is_array($payload['assets'] ?? null) ? $payload['assets'] : [];

    foreach ($assets as $asset) {
        if (!is_array($asset) || (string)($asset['name'] ?? '') !== $assetName) {
            continue;
        }

        $download = (string)($asset['browser_download_url'] ?? '');
        $digest = (string)($asset['digest'] ?? '');

        if ($download === '' || !str_starts_with($download, 'https://github.com/')) {
            fail_install('The shared-hosting release asset has an invalid download URL.');
        }

        if (!preg_match('/^sha256:[0-9a-f]{64}$/i', $digest)) {
            fail_install('The shared-hosting release does not expose a SHA-256 digest.');
        }

        return [
            'version' => $version,
            'tag' => $tag,
            'asset' => $assetName,
            'download' => $download,
            'sha256' => strtolower(substr($digest, 7)),
        ];
    }

    fail_install(
        'GitHub release ' . $tag .
        ' does not contain ' . $assetName . '.'
    );
}

function assert_clean_target(string $target): void
{
    foreach ([
        '.env',
        '.htaccess',
        'composer.json',
        'composer.lock',
        'src',
        'database',
        'public',
        'vendor',
        'config',
        'storage',
    ] as $marker) {
        if (file_exists($target . '/' . $marker)) {
            fail_install(
                'The target directory already contains "' . $marker .
                '". Use a fresh folder or a dedicated shared-hosting document root.'
            );
        }
    }
}

function assert_archive(ZipArchive $zip, string $version): void
{
    $required = [
        'muchocore/.htaccess',
        'muchocore/VERSION',
        'muchocore/public/shared-install.php',
        'muchocore/vendor/autoload.php',
        'muchocore/docs/SHARED_HOSTING.md',
    ];
    $found = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $name);

        if ($normalized === '' || str_starts_with($normalized, '/')
            || str_contains($normalized, "\0")
            || preg_match('#(^|/)\.\.?(/|$)#', $normalized) === 1) {
            fail_install('The release archive contains an unsafe path.');
        }

        if (!str_starts_with($normalized, 'muchocore/')) {
            fail_install('The release archive contains an unexpected top-level path.');
        }

        $found[$normalized] = true;
    }

    foreach ($required as $name) {
        if (!isset($found[$name])) {
            fail_install('The release archive is incomplete: missing ' . $name . '.');
        }
    }

    $embeddedVersion = trim((string)$zip->getFromName('muchocore/VERSION'));
    if ($embeddedVersion !== $version) {
        fail_install(
            'The downloaded archive version does not match the GitHub release tag.'
        );
    }
}

function copy_tree(string $source, string $target, array &$created): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
                fail_install('Unable to create installation directory: ' . $relative);
            }
            if (!in_array($destination, $created, true)) {
                $created[] = $destination;
            }
            continue;
        }

        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
            fail_install('Unable to create directory for: ' . $relative);
        }

        if (!copy($item->getPathname(), $destination)) {
            fail_install('Unable to install file: ' . $relative);
        }

        @chmod($destination, 0644);
        $created[] = $destination;
    }
}

function rollback(array $created): void
{
    usort($created, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });

    foreach ($created as $path) {
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            @rmdir($path);
        }
    }
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#060811">';
    echo '<title>' . e($title) . ' — MuchoCore</title>';
    echo '<style>';
    echo ':root{--bg:#060811;--panel:#0c121d;--line:rgba(255,255,255,.09);--text:#f4f7ff;--muted:#97a3b8;--violet:#8c7dff;--green:#69e2a8;--red:#ff8197}';
    echo '*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(900px 500px at 80% -10%,rgba(85,232,223,.08),transparent 65%),radial-gradient(700px 500px at 10% 0%,rgba(140,125,255,.14),transparent 66%),var(--bg);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);padding:32px}';
    echo '.box{width:min(760px,100%);margin:5vh auto;padding:28px;border:1px solid var(--line);border-radius:20px;background:linear-gradient(180deg,#0e1522,#090e17);box-shadow:0 30px 90px rgba(0,0,0,.28)}';
    echo 'h1{margin:0 0 10px;font-size:32px;letter-spacing:-1.5px}h2{margin:26px 0 9px;font-size:14px}p{color:var(--muted);font-size:13px;line-height:1.7}';
    echo '.meta{margin:18px 0;padding:14px 16px;border:1px solid rgba(140,125,255,.2);border-radius:12px;background:rgba(140,125,255,.06)}';
    echo '.meta b{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:#a49cff}.meta code{display:block;margin-top:6px;font:11px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#dce2ef}';
    echo '.ok,.err{margin:16px 0;padding:13px 15px;border-radius:11px;font-size:12px;line-height:1.55}.ok{background:rgba(105,226,168,.08);border:1px solid rgba(105,226,168,.2);color:#c3f1da}.err{background:rgba(255,129,151,.07);border:1px solid rgba(255,129,151,.2);color:#ffc2cd}';
    echo 'button{width:100%;min-height:48px;border:1px solid rgba(177,167,255,.55);border-radius:12px;background:linear-gradient(135deg,#8f81ff,#7567f1);color:#fff;font-weight:900;cursor:pointer}button:disabled{opacity:.55;cursor:not-allowed}.check{display:grid;grid-template-columns:18px 1fr;gap:10px;margin:17px 0;color:#abb6c8;font-size:12px;line-height:1.6}input{accent-color:#8c7dff;margin-top:3px}.muted{font-size:11px;color:#6e7b91}.links{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.links a{color:#b8b0ff;font-size:11px;font-weight:800}';
    echo '</style></head><body><main class="box">' . $body . '</main></body></html>';
    exit;
}

$checks = [
    [
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'text' => 'PHP ' . PHP_VERSION . ' (8.3+ required)',
    ],
    [
        'ok' => class_exists('ZipArchive'),
        'text' => 'PHP Zip extension',
    ],
    [
        'ok' => function_exists('hash_file'),
        'text' => 'SHA-256 hashing',
    ],
    [
        'ok' => function_exists('random_bytes'),
        'text' => 'Secure random generator',
    ],
    [
        'ok' => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'text' => 'Outbound HTTPS downloads (cURL or allow_url_fopen)',
    ],
    [
        'ok' => is_writable($target),
        'text' => 'Target directory writable by PHP',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!array_filter($checks, static fn(array $check): bool => !$check['ok'])) {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (empty($_SESSION['muchocore_ftp_install_csrf'])
            || !hash_equals((string)$_SESSION['muchocore_ftp_install_csrf'], $csrf)) {
            page(
                'Installer expired',
                '<h1>Installer session expired.</h1><div class="err">Refresh this page and start again.</div>'
            );
        }

        set_time_limit($maxTimeLimit);
        $lock = fopen($lockPath, 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            page(
                'Installation in progress',
                '<h1>Another installation is already running.</h1><div class="err">Refresh after it finishes.</div>'
            );
        }

        $archive = null;
        $stage = null;
        $created = [];

        try {
            assert_clean_target($target);

            $release = release_info();

            $archive = tempnam($target, '.muchocore-release-');
            if ($archive === false) {
                fail_install('Unable to create a temporary release file.');
            }

            $bytes = download_file($release['download'], $archive);
            $actual = strtolower((string)hash_file('sha256', $archive));

            if (!hash_equals($release['sha256'], $actual)) {
                fail_install('Release SHA-256 verification failed. The package was not installed.');
            }

            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) {
                fail_install('The downloaded file is not a valid ZIP archive.');
            }

            assert_archive($zip, $release['version']);

            $stage = sys_get_temp_dir() . '/muchocore-ftp-' . bin2hex(random_bytes(8));
            if (!mkdir($stage, 0700, true)) {
                $zip->close();
                fail_install('Unable to create the temporary extraction directory.');
            }

            if (!$zip->extractTo($stage)) {
                $zip->close();
                fail_install('Unable to extract the verified shared-hosting package.');
            }
            $zip->close();

            $source = $stage . '/muchocore';
            if (!is_dir($source)) {
                fail_install('The verified archive does not contain its expected project directory.');
            }

            copy_tree($source, $target, $created);

            @unlink($archive);
            $archive = null;
            if ($stage !== null) {
                // The staging tree is no longer needed after the file copy.
                $remove = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($remove as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($stage);
                $stage = null;
            }

            unset($_SESSION['muchocore_ftp_install_csrf']);
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
            @unlink(__FILE__);

            header('Location: shared-install.php', true, 303);
            exit;
        } catch (Throwable $e) {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            if (is_string($archive)) {
                @unlink($archive);
            }
            if (is_string($stage) && is_dir($stage)) {
                $remove = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($remove as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($stage);
            }

            rollback($created);
            @unlink($lockPath);

            page(
                'Installation failed',
                '<h1>Installation stopped safely.</h1>'
                . '<div class="err">' . e($e->getMessage()) . '</div>'
                . '<p>The verified release was not left half-installed. Fix the issue and upload the bootstrap again.</p>'
            );
        }
    } else {
        page(
            'Requirements not met',
            '<h1>This host is not ready.</h1><div class="err">Fix the failed requirement checks below, then refresh.</div>'
        );
    }
}

$checksHtml = '';
foreach ($checks as $check) {
    $checksHtml .= '<div class="' . ($check['ok'] ? 'ok' : 'err') . '">'
        . ($check['ok'] ? '✓ ' : '✕ ') . e($check['text'])
        . '</div>';
}

$form = '<h1>Install MuchoCore on shared hosting.</h1>'
    . '<p>Upload this file through FTP or your hosting file manager, then let the browser download and verify the current published MuchoCore shared-hosting package. Your FTP username and password stay between you and your hosting provider.</p>'
    . '<div class="meta"><b>What happens next</b><code>GitHub stable release → SHA-256 verify → extract → /shared-install.php</code></div>'
    . '<h2>Preflight</h2>' . $checksHtml
    . '<form method="post">'
    . '<input type="hidden" name="csrf" value="' . e((string)$_SESSION['muchocore_ftp_install_csrf']) . '">'
    . '<label class="check"><input type="checkbox" required><span>I am installing into a fresh directory dedicated to this MuchoCore site, and I understand that the installer creates the application files there.</span></label>'
    . '<button type="submit"' . (in_array(false, array_column($checks, 'ok'), true) ? ' disabled' : '') . '>Download, verify and install MuchoCore →</button>'
    . '</form>'
    . '<div class="links"><a href="https://muchogdps.space/install/shared/">Shared hosting guide ↗</a><a href="https://github.com/IZKGMD/GMDmucho-core/releases" rel="noopener">Releases ↗</a></div>';

page('Shared hosting installer', $form);
