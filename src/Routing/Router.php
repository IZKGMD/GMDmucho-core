<?php

declare(strict_types=1);

namespace MuchoCore\Routing;

use Closure;
use InvalidArgumentException;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final class Router
{
    /** @var array<string, Closure> */
    private array $routes = [];

    public function add(string $method, string $path, mixed $handler): void
    {
        if (!is_callable($handler)) {
            throw new InvalidArgumentException(
                sprintf('Invalid route handler for %s %s', $method, $path)
            );
        }

        $key = $this->routeKey($method, $path);
        $this->routes[$key] = Closure::fromCallable($handler);
    }

    public function dispatch(Request $request): Response
    {
        $method = strtoupper((string)($request->method ?? 'GET'));
        $path = $this->normalizePath((string)($request->path ?? '/'));

        if ($path === '/checkifserveronline') {
            return Response::text('1');
        }

        foreach ([
            $this->routeKey($method, $path),
            $this->routeKey('ANY', $path),
        ] as $key) {
            if (isset($this->routes[$key])) {
                return ($this->routes[$key])($request);
            }
        }

        return Response::text('-1');
    }

    public function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : '/';

        $path = preg_replace('#/+#', '/', $path) ?? '/';

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        while (
            preg_match(
                '#^/(?:database|accounts|api|a)(?:/|$)#i',
                $path
            ) === 1
        ) {
            $path = preg_replace(
                '#^/(?:database|accounts|api|a)(?:/|$)#i',
                '/',
                $path,
                1
            ) ?? $path;
        }

        $path = preg_replace('#\.php$#i', '', $path) ?? $path;

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if ($path === '') {
            $path = '/';
        }

        $path = strtolower($path);

        static $compatAliases = [
            // Account endpoints used by different client generations.
            '/loginGJAccount19' => '/loginGJAccount',
            '/loginGJAccount20' => '/loginGJAccount',
            '/loginGJAccount21' => '/loginGJAccount',
            '/loginGJAccount22' => '/loginGJAccount',
            '/registerGJAccount19' => '/registerGJAccount',
            '/registerGJAccount20' => '/registerGJAccount',
            '/registerGJAccount21' => '/registerGJAccount',
            '/registerGJAccount22' => '/registerGJAccount',

            // Cloud saves.
            '/backupGJAccountNew' => '/backupGJAccount',
            '/backupGJAccount19' => '/backupGJAccount',
            '/backupGJAccount20' => '/backupGJAccount',
            '/syncGJAccountNew' => '/syncGJAccount',
            '/syncGJAccount19' => '/syncGJAccount',
            '/syncGJAccount20' => '/syncGJAccount',

            // Level discovery and transfer.
            '/getGJLevels' => '/getGJLevels21',
            '/getGJLevels19' => '/getGJLevels21',
            '/getGJLevels20' => '/getGJLevels21',
            '/getGJLevels22' => '/getGJLevels21',
            '/downloadGJLevel' => '/downloadGJLevel21',
            '/downloadGJLevel19' => '/downloadGJLevel21',
            '/downloadGJLevel20' => '/downloadGJLevel21',
            '/uploadGJLevel' => '/uploadGJLevel21',
            '/uploadGJLevel19' => '/uploadGJLevel21',
            '/uploadGJLevel20' => '/uploadGJLevel21',

            // Comments and likes.
            '/getGJComments' => '/getGJComments21',
            '/getGJComments19' => '/getGJComments21',
            '/getGJComments20' => '/getGJComments21',
            '/uploadGJComment' => '/uploadGJComment20',
            '/uploadGJComment19' => '/uploadGJComment20',
            '/uploadGJComment21' => '/uploadGJComment21',
            '/likeGJItem' => '/likeGJItem21',
            '/likeGJItem19' => '/likeGJItem21',
            '/likeGJItem20' => '/likeGJItem21',

            // User and leaderboard endpoints.
            '/getGJUserInfo' => '/getGJUserInfo20',
            '/getGJUserInfo19' => '/getGJUserInfo20',
            '/getGJUserInfo21' => '/getGJUserInfo20',
            '/getGJUserInfo22' => '/getGJUserInfo20',
            '/getGJUsers' => '/getGJUsers20',
            '/getGJUsers19' => '/getGJUsers20',
            '/getGJUsers21' => '/getGJUsers20',
            '/getGJUsers22' => '/getGJUsers20',
            '/getGJScores' => '/getGJScores20',
            '/getGJScores19' => '/getGJScores20',
            '/getGJScores21' => '/getGJScores20',
            '/getGJScores22' => '/getGJScores20',
            '/updateGJAccSettings19' => '/updateGJAccSettings20',
            '/updateGJAccSettings21' => '/updateGJAccSettings20',
            '/updateGJAccSettings22' => '/updateGJAccSettings20',
            '/updateGJUserScore19' => '/updateGJUserScore',
            '/updateGJUserScore20' => '/updateGJUserScore',
            '/updateGJUserScore21' => '/updateGJUserScore',
            '/updateGJUserScore22' => '/updateGJUserScore22',

            // Discovery.
            '/getGJMapPacks19' => '/getGJMapPacks21',
            '/getGJMapPacks20' => '/getGJMapPacks21',
            '/getGJGauntlets19' => '/getGJGauntlets21',
            '/getGJGauntlets20' => '/getGJGauntlets21',

            // Level scores.
            '/getGJLevelScores19' => '/getGJLevelScores',
            '/getGJLevelScores20' => '/getGJLevelScores',
            '/getGJLevelScores21' => '/getGJLevelScores211',
            '/getGJLevelScores22' => '/getGJLevelScores',
        ];

        return $compatAliases[$path] ?? $path;
    }

    private function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $this->normalizePath($path);
    }
}
