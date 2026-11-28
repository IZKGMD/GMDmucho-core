<?php

declare(strict_types=1);

use MuchoCore\Core\Environment;
use MuchoCore\Music\MusicLibrary;
use MuchoCoreLegacySongs\NetworkCatalog;

require_once __DIR__ . '/bootstrap.php';
require_once MUCHO_V2_ROOT . '/patchers/android/legacy-custom-songs/server/legacy-songs/library.php';

muchoV2RequireMethod('GET');

$operation = $_GET['op'] ?? '';
if (!is_string($operation) || !in_array($operation, ['catalog', 'track', 'level', 'newgrounds'], true)) {
    muchoV2Send(['error' => 'unknown_operation'], 404);
}

try {
    $db = muchoV2Db();
    $directory = (string) Environment::get('MUCHO_LEGACY_SONGS_DIR', MUCHO_V2_ROOT . '/storage/music-public');
    $catalog = new NetworkCatalog($db, ['/music/' => $directory]);
    [$status, $body] = (new MusicLibrary($db, $catalog))->handle($operation, $_GET);
    muchoV2Send($body, $status);
} catch (Throwable $error) {
    error_log('[MuchoCore Music Library] ' . $error->getMessage());
    muchoV2Send(['error' => 'catalog_unavailable'], 503);
}
