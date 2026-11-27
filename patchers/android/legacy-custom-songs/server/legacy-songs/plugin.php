<?php

declare(strict_types=1);

use MuchoCore\Core\Environment;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;
use MuchoCoreLegacySongs\NetworkCatalog;

require_once __DIR__ . '/library.php';

return new class implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $directory = (string) Environment::get(
            'MUCHO_LEGACY_SONGS_DIR',
            dirname(__DIR__, 3) . '/storage/music-public'
        );
        $catalog = new NetworkCatalog($context->db(), ['/music/' => $directory]);
        foreach (['catalog', 'track', 'level'] as $operation) {
            $context->route('GET', '/api/plugin/legacy-songs/v1/' . $operation,
                static function (Request $request) use ($catalog, $operation): Response {
                    [$status, $body] = $catalog->handle($operation, $request->query);
                    return Response::json($body, $status);
                });
        }
    }
};
