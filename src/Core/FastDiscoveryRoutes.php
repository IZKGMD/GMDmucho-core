<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Artist\TopArtistController;
use MuchoCore\Artist\TopArtistRepository;
use MuchoCore\Artist\TopArtistService;
use MuchoCore\Compatibility\DiscoveryController;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Music\SongController;
use MuchoCore\Music\SongRepository;
use MuchoCore\Music\SongService;
use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Routing\Router;
use PDO;

/**
 * Small, opt-in route graph for public GD discovery endpoints.
 *
 * Only Application invokes this, after the normal front-controller
 * MuchoProtect precheck. The same controllers, handlers and plugin lifecycle
 * as the full application are retained; no response caching is introduced.
 */
final class FastDiscoveryRoutes
{
    public static function supports(string $path): bool
    {
        return in_array($path, [
            '/getgjsonginfo',
            '/getgjtopartists',
            '/getgjcreators',
            '/getgjcreators19',
            '/getgjdailylevel',
            '/getgjgauntlets',
            '/getgjgauntlets21',
            '/getgjmappacks',
            '/getgjmappacks20',
            '/getgjmappacks21',
        ], true);
    }

    public static function register(Router $router, PDO $pdo, string $path): void
    {
        if (!self::supports($path)) {
            throw new \InvalidArgumentException('Unsupported fast discovery route');
        }

        if ($path === '/getgjsonginfo') {
            $controller = new SongController(
                new SongService(new SongRepository($pdo), new GdSongEncoder())
            );
            $router->add('ANY', $path, [$controller, 'info']);
            return;
        }

        if ($path === '/getgjtopartists') {
            $controller = new TopArtistController(
                new TopArtistService(new TopArtistRepository($pdo))
            );
            $router->add('ANY', $path,
                static fn(Request $request): Response => Response::text($controller->get()));
            return;
        }

        $controller = new DiscoveryController($pdo);
        $method = match ($path) {
            '/getgjcreators', '/getgjcreators19' => 'creators',
            '/getgjdailylevel' => 'daily',
            '/getgjgauntlets', '/getgjgauntlets21' => 'gauntlets',
            default => 'mapPacks',
        };
        $router->add('ANY', $path, [$controller, $method]);
    }
}
