<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'MuchoCore\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
