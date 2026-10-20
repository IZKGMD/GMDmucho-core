<?php
declare(strict_types=1);

// The single archive includes a PHP 7-readable diagnosis + the complete
// PHP 8.2+ installer. This test does not install a database or expose secrets.
$root = dirname(__DIR__, 2);
$bootstrap = $root . '/public/install.php';
$script = file_get_contents($bootstrap);
$apache = file_get_contents($root . '/.htaccess');
$builder = file_get_contents($root . '/tools/release/build-shared-hosting.sh');

if (!is_string($script) || !is_string($apache) || !is_string($builder)) {
    throw new RuntimeException('Cannot inspect the shared-hosting installer sources.');
}
foreach ([
    'PHP_VERSION_ID >= 80200',
    "'pdo_mysql'",
    "'mbstring'",
    "'openssl'",
    "'random_bytes'",
    '/vendor/autoload.php',
    "require __DIR__ . '/shared-install.php'",
    'Installation blocked:',
    'Nothing has been installed or changed in your database.',
] as $needle) {
    if (!str_contains($script, $needle)) {
        throw new RuntimeException('Shared browser entrypoint is missing: ' . $needle);
    }
}
if (strpos($script, 'if ($ready)') > strpos($script, "require __DIR__ . '/shared-install.php'")) {
    throw new RuntimeException('Installer must check host requirements before loading modern PHP.');
}
if (!str_contains($apache, 'RewriteRule ^install\.php$ public/install.php [END]')) {
    throw new RuntimeException('/install.php must work from the project-root Apache document root.');
}
foreach ([
    '"public/install.php"',
    '"docs/SHARED_INSTALL_RU.txt"',
    "grep -Fxq 'muchocore/public/install.php'",
    "grep -Fxq 'muchocore/docs/SHARED_INSTALL_RU.txt'",
] as $needle) {
    if (!str_contains($builder, $needle)) {
        throw new RuntimeException('Unified ZIP builder is missing: ' . $needle);
    }
}
if (!is_file($root . '/docs/SHARED_INSTALL_RU.txt')) {
    throw new RuntimeException('Russian installer instructions were not bundled.');
}

// Ensure an HTTP GET only shows a diagnostic (never credentials), and an
// HTTP POST is rejected without entering the database setup flow.
if (!function_exists('exec')) {
    throw new RuntimeException('Installer contract runner requires CLI exec.');
}
foreach (['GET', 'POST'] as $method) {
    $cli = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
        . 'unset($_SERVER["HTTPS"], $_SERVER["SERVER_PORT"]);'
        . 'require ' . var_export($bootstrap, true) . ';';
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($cli) . ' 2>&1';
    $lines = [];
    $status = 0;
    exec($command, $lines, $status);
    $output = implode("\n", $lines);
    if ($status !== 0) {
        throw new RuntimeException('Shared installer preflight failed: ' . $output);
    }
    if ($method === 'GET') {
        if (!str_contains($output, 'Check your hosting environment')
            || !str_contains($output, 'HTTPS connection')
            || !str_contains($output, 'Nothing has been installed')) {
            throw new RuntimeException('Shared installer GET must show a safe preflight page.');
        }
        continue;
    }
    if (!str_contains($output, 'Installation blocked:')) {
        throw new RuntimeException('HTTP installer POST must never enter the credential form.');
    }
}
echo "shared-one-archive-installer-contract: OK\n";
