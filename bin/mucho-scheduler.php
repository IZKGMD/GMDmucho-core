#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Job\AutomationScheduler;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    $lines = file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

$loop = in_array('--loop', $argv, true);
$sleep = 15;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sleep=')) {
        $sleep = max(5, min(300, (int)substr($arg, 8)));
    }
}

$db = (new Database())->connection();
$scheduler = new AutomationScheduler($db);

do {
    try {
        $count = $scheduler->tick();
        echo sprintf(
            "[MuchoCore Scheduler] tick complete; enqueued=%d\n",
            $count
        );
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "[MuchoCore Scheduler] tick failed: " . $e->getMessage() . "\n"
        );
    }

    if (!$loop) {
        break;
    }

    sleep($sleep);
} while (true);
