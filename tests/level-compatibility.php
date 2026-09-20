<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

use MuchoCore\Level\LevelTransferService;

require dirname(__DIR__) . '/vendor/autoload.php';

$ref = new ReflectionClass(LevelTransferService::class);
$service = $ref->newInstanceWithoutConstructor();

$encode = $ref->getMethod('encodeLegacyDescription');
$encode->setAccessible(true);

$legacy = $encode->invoke($service, 'Hello GD', 19);
$expected = str_replace(
    ['+', '/'],
    ['-', '_'],
    base64_encode('Hello GD')
);

if ($legacy !== $expected) {
    throw new RuntimeException(
        'Legacy description encoding mismatch.'
    );
}

$modern = $encode->invoke($service, 'Hello GD', 20);

if ($modern !== 'Hello GD') {
    throw new RuntimeException(
        'Modern description encoding must remain unchanged.'
    );
}

echo "LEVEL_COMPATIBILITY_OK\n";
