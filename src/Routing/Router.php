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
            'syncgjaccountnew.php' => '/syncGJAccount',
            'syncgjaccountnew' => '/syncGJAccount',
            'backupgjaccountnew.php' => '/backupGJAccount',
            'backupgjaccountnew' => '/backupGJAccount',
            // Cloud saves
            '/backupgjaccountnew' => '/backupgjaccount',
            '/backupgjaccount20'  => '/backupgjaccount',
            '/syncgjaccountnew'   => '/syncgjaccount',
            '/syncgjaccount20'    => '/syncgjaccount',

            // Levels listing
            '/getgjlevels'   => '/getgjlevels21',
            '/getgjlevels19' => '/getgjlevels21',
            '/getgjlevels20' => '/getgjlevels21',

            // Level download
            '/downloadgjlevel'   => '/downloadgjlevel21',
            '/downloadgjlevel19' => '/downloadgjlevel21',
            '/downloadgjlevel20' => '/downloadgjlevel21',

            // Comments
            '/getgjcomments'   => '/getgjcomments21',
            '/getgjcomments19' => '/getgjcomments21',
            '/getgjcomments20' => '/getgjcomments21',

            // Scores
            '/getgjscores'   => '/getgjscores20',
            '/getgjscores19' => '/getgjscores20',

            // Likes
            '/likegjitem'   => '/likegjitem21',
            '/likegjitem19' => '/likegjitem21',
            '/likegjitem20' => '/likegjitem21',

            // User score update
            '/updategjuserscore19' => '/updategjuserscore',
            '/updategjuserscore20' => '/updategjuserscore',
            '/updategjuserscore21' => '/updategjuserscore',

            // Level upload
            '/uploadgjlevel'   => '/uploadgjlevel21',
            '/uploadgjlevel19' => '/uploadgjlevel21',
            '/uploadgjlevel20' => '/uploadgjlevel21',

            // Comment upload
            '/uploadgjcomment'   => '/uploadgjcomment20',
            '/uploadgjcomment19' => '/uploadgjcomment20',
        ];

        return $compatAliases[$path] ?? $path;
    }

    private function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $this->normalizePath($path);
    }
}
