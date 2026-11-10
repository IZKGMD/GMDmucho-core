<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Http/Request.php';

use MuchoCore\Http\Request;

function checkPostInt(mixed $input, int $expected, string $label): void
{
    $request = new Request('POST', '/database/getGJLevels21.php', [], ['page' => $input], []);
    $actual = $request->postInt('page', 77);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf("FAIL %s: expected %d, got %d\n", $label, $expected, $actual));
        exit(1);
    }
    echo "PASS {$label}\n";
}

checkPostInt(14, 14, 'native PHP integer');
checkPostInt(PHP_INT_MAX, PHP_INT_MAX, 'native maximum');
checkPostInt(PHP_INT_MIN, PHP_INT_MIN, 'native minimum');
checkPostInt('42', 42, 'normal decimal string');
checkPostInt('00042', 42, 'GD zero-padded decimal identifier');
checkPostInt('-00042', -42, 'zero-padded negative');
checkPostInt('-0000', 0, 'negative zero');
checkPostInt((string) PHP_INT_MAX, PHP_INT_MAX, 'positive numeric boundary');
checkPostInt((string) PHP_INT_MIN, PHP_INT_MIN, 'negative numeric boundary');
checkPostInt(substr((string) PHP_INT_MIN, 1), 77, 'overflow positive by one');
checkPostInt((string) PHP_INT_MIN . '0', 77, 'overflow negative');
checkPostInt(str_repeat('9', 300), 77, 'very long decimal value');
checkPostInt('12' . "\n", 12, 'preserve legacy end-line numeric semantics');
checkPostInt(' 12', 77, 'leading whitespace is not numeric');
checkPostInt('+12', 77, 'plus sign is not supported');
checkPostInt('1.0', 77, 'floating-point syntax is not integer');
checkPostInt('1e2', 77, 'exponential syntax is not integer');
checkPostInt('', 77, 'empty string');
checkPostInt('-', 77, 'sign without digits');
checkPostInt(['12'], 77, 'PHP array input');
checkPostInt(12.0, 77, 'float input not converted');
checkPostInt(true, 77, 'boolean input not converted');

echo "MUCHOCORE_PHP8_REQUEST_INT_BOUNDS_OK\n";
