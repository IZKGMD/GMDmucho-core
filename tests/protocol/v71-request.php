<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/V71/Request.php';

use MuchoCore\V71\Request;

$cases = [
    ['123', 123],
    ['-42', -42],
    ['1e3', 0],
    ['10.5', 0],
    [' 12', 0],
    ['12 ', 0],
    ['1,000', 0],
];

foreach ($cases as [$raw, $expected]) {
    $_POST = ['value' => $raw];
    $actual = Request::int('value');

    if ($actual !== $expected) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL V71 int parsing %s: expected %d, got %d
",
                var_export($raw, true),
                $expected,
                $actual
            )
        );
        exit(1);
    }

    echo "PASS V71 int parsing " . var_export($raw, true) . "
";
}

$_POST = [];
if (Request::int('missing', 7) !== 7) {
    fwrite(STDERR, "FAIL V71 int default
");
    exit(1);
}

echo "V71_REQUEST_INT_OK
";
