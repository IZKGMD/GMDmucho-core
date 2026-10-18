<?php
declare(strict_types=1);

$probe = dirname(__DIR__, 2) . '/tools/hosting/muchocore-probe.php';
if (!is_file($probe)) {
    throw new RuntimeException('Free-hosting probe is missing.');
}
$source = (string)file_get_contents($probe);
foreach ([
    "PHP_VERSION_ID >= 80300",
    "'pdo_mysql'",
    "'mode'",
    "'gd'",
    "'probe'",
    "'muchocore'",
    "'1'",
    "'delete_after_test' => true",
] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException('Probe lost expected contract: ' . $expected);
    }
}
if (preg_match('/(?:eval\s*\(|shell_exec\s*\(|system\s*\(|exec\s*\(|passthru\s*\()/i', $source)) {
    throw new RuntimeException('Probe must not invoke dynamic code or system commands.');
}

$php = escapeshellarg(PHP_BINARY);
$code = <<<'CODE'
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['mode'] = 'gd';
$_POST['probe'] = 'muchocore';
require $argv[1];
CODE;
$command = $php . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($probe);
$output = [];
$exit = 0;
exec($command, $output, $exit);
if ($exit !== 0 || implode("\n", $output) !== '1') {
    throw new RuntimeException('Valid game-like POST must receive exactly 1.');
}

echo "free-hosting-probe-contract: OK\n";
