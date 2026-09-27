<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Level/LevelValidator.php';
require dirname(__DIR__, 2) . '/src/Search/LevelSearchIndexer.php';
$config = require dirname(__DIR__, 2) . '/public/admin/config/pages.php';
require dirname(__DIR__, 2) . '/src/Admin/AdminRbac.php';

use MuchoCore\Level\LevelValidator;
use MuchoCore\Search\LevelSearchIndexer;
use MuchoCore\Admin\AdminRbac;

$valid = LevelValidator::validate([
    'account_id' => 123,
    'name' => 'Test Level',
    'level_data' => '1,2;3,4',
    'game_version' => 22,
    'binary_version' => 31,
    'object_count' => 42,
]);

if (!$valid['ok']) {
    throw new RuntimeException('valid level payload was rejected');
}

if (!preg_match('/^[a-f0-9]{64}$/', $valid['sha256'])) {
    throw new RuntimeException('level hash contract failed');
}

$invalid = LevelValidator::validate([
    'account_id' => 0,
    'name' => "bad\0name",
    'level_data' => '',
    'game_version' => 0,
]);

foreach ([
    'invalid_account_id',
    'level_name_contains_control_characters',
    'empty_level_data',
    'invalid_game_version',
] as $reason) {
    if (!in_array($reason, $invalid['errors'], true)) {
        throw new RuntimeException(
            "missing validator error: {$reason}"
        );
    }
}

if (LevelSearchIndexer::normalize("  My   LEVEL  ") !== 'my level') {
    throw new RuntimeException('search normalization contract failed');
}

if (($config['intelligence'] ?? null) !== 'Intelligence & Scale') {
    throw new RuntimeException('intelligence admin page registry contract failed');
}

$ref = new ReflectionClass(AdminRbac::class);
$source = file_get_contents(
    dirname(__DIR__, 2) . '/src/Admin/AdminRbac.php'
);
if (!is_string($source) || !str_contains($source, "'intelligence' => 'monitoring.view'")) {
    throw new RuntimeException('intelligence RBAC mapping contract failed');
}

echo "MUCHOCORE_V108_INTELLIGENCE_OK\n";
