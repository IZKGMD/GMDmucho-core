<?php
/**
 * Guard the Apache rules shipped in the PHP7/5v.pl ZIP.
 *
 * Cvolton-style clients may request /getGJLevels21.php directly at the
 * website root instead of /database/getGJLevels21.php. Both must reach the
 * native router, without hijacking unrelated PHP pages.
 *
 * Runs on PHP 7.0; does not require Apache, MySQL or a running game server.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = file_get_contents($root . '/tools/release/build-php7-native.sh');
$needle = "cat > \"\$STAGE/5V_ROOT_REWRITE_RULES.txt\" <<'APACHE'";
$parts = explode($needle, $builder, 2);
if (count($parts) !== 2) {
    fwrite(STDERR, "Root rewrite heredoc not found in the ZIP builder\n");
    exit(1);
}
$block = explode("\nAPACHE", $parts[1], 2);
if (count($block) !== 2) {
    fwrite(STDERR, "Root rewrite heredoc has no closing marker\n");
    exit(1);
}

$rules = array();
foreach (preg_split('/\r?\n/', trim($block[0])) as $line) {
    $line = trim($line);
    if (strpos($line, 'RewriteRule ') !== 0) {
        continue;
    }
    if (!preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]+)\]$/', $line, $m)) {
        fwrite(STDERR, "Invalid Apache rewrite: " . $line . "\n");
        exit(1);
    }
    if ($m[2] !== 'mc/public/php7.php') {
        fwrite(STDERR, "Unexpected shared-host rewrite destination: " . $m[2] . "\n");
        exit(1);
    }
    if (strpos(',' . $m[3] . ',', ',L,') === false) {
        fwrite(STDERR, "Shared-host rewrite is not terminal: " . $line . "\n");
        exit(1);
    }
    $rules[] = '~' . str_replace('~', '\\~', $m[1]) . '~i';
}

function shared5vRoutesToPhp7($path, $rules)
{
    foreach ($rules as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            return true;
        }
    }
    return false;
}

$expected = array(
    'getGJLevels21.php',           // Cvolton: root-level wrappers
    'downloadGJLevel22.php',
    'uploadGJLevel21.php',
    'getGJLevelScores211.php',
    'getGJUserInfo20.php',
    'loginGJAccount.php',
    'getAccountURL.php',
    'checkIfServerOnline.php',
    'database/getGJLevels21.php',  // original GD path
    'a/downloadGJLevel22.php',    // shortened GD 2.209 path
    'a/accounts/registerGJAccount22.php',
    'gd-content/news/news.dat',
    'news/news_version.txt'
);
$unrelated = array(
    'index.php',
    'install.php',
    'wp-login.php',
    'dashboard/index.php',
    'tools/status.php',
    'mc/config/php7-local.php',
    'mc/admin.php'
);
foreach ($expected as $path) {
    if (!shared5vRoutesToPhp7($path, $rules)) {
        fwrite(STDERR, "Not routed to PHP7 shared runtime: /" . $path . "\n");
        exit(1);
    }
}
foreach ($unrelated as $path) {
    if (shared5vRoutesToPhp7($path, $rules)) {
        fwrite(STDERR, "Unrelated website path is hijacked: /" . $path . "\n");
        exit(1);
    }
}

// All legacy wrapper URLs must normalize to the same native dispatcher route.
require_once $root . '/compat/php7/runtime.php';
foreach (array('getGJLevels21.php', 'downloadGJLevel22.php', 'getGJLevelScores211.php') as $endpoint) {
    $direct = mucho7_path('/' . $endpoint);
    $database = mucho7_path('/database/' . $endpoint);
    $short = mucho7_path('/a/' . $endpoint);
    if ($direct !== $database || $direct !== $short) {
        fwrite(STDERR, "Native dispatcher routes differ for " . $endpoint . "\n");
        exit(1);
    }
}

$docs = file_get_contents($root . '/docs/PHP7_NATIVE_PHASE1.md');
$rootAlias = 'RewriteRule ^(?:[A-Za-z][A-Za-z0-9_-]*GJ[A-Za-z0-9_-]*|getAccountURL|getCustomContentURL|checkIfServerOnline)\\.php$ mc/public/php7.php [QSA,L,NC]';
if (strpos($builder, $rootAlias) === false || strpos($docs, $rootAlias) === false) {
    fwrite(STDERR, "README root alias differs from ZIP builder\n");
    exit(1);
}

echo "PHP7_5V_CVOLTON_ROOT_ROUTING_OK\n";
