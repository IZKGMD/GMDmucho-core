<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Protocol/GdLegacyText.php';
require dirname(__DIR__, 2) . '/src/Migration/CvoltonDatabaseImporter.php';

use MuchoCore\Migration\CvoltonDatabaseImporter;

$reflection = new ReflectionClass(CvoltonDatabaseImporter::class);
$importer = $reflection->newInstanceWithoutConstructor();
$normalize = new ReflectionMethod(CvoltonDatabaseImporter::class, 'levelParams');

$description = 'A timing based extreme demon. (LDM: Blue Mode)';
$wire = base64_encode($description);
$row = [
    'gameVersion' => 22,
    'levelName' => 'Fixture',
    'levelDesc' => $wire,
];

$params = $normalize->invoke($importer, 42, $row);
if (($params['description'] ?? null) !== $description) {
    throw new RuntimeException('GD 2.x imported Base64 description was not decoded.');
}

$row['levelDesc'] = $description;
$params = $normalize->invoke($importer, 42, $row);
if (($params['description'] ?? null) !== $description) {
    throw new RuntimeException('Already plain GD 2.x descriptions must be preserved.');
}

$row['gameVersion'] = 19;
$row['levelDesc'] = $wire;
$params = $normalize->invoke($importer, 42, $row);
if (($params['description'] ?? null) !== $wire) {
    throw new RuntimeException('GD 1.9 encoded storage format must be preserved.');
}

echo "cvolton-level-description: OK\n";
