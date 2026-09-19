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
            '/logingjaccount19' => '/loginGJAccount',
            '/logingjaccount20' => '/loginGJAccount',
            '/logingjaccount21' => '/loginGJAccount',
            '/logingjaccount22' => '/loginGJAccount',
            '/registergjaccount19' => '/registerGJAccount',
            '/registergjaccount20' => '/registerGJAccount',
            '/registergjaccount21' => '/registerGJAccount',
            '/registergjaccount22' => '/registerGJAccount',

            // Cloud saves.
            '/backupgjaccountnew' => '/backupGJAccount',
            '/backupgjaccount19' => '/backupGJAccount',
            '/backupgjaccount20' => '/backupGJAccount',
            '/syncgjaccountnew' => '/syncGJAccount',
            '/syncgjaccount19' => '/syncGJAccount',
            '/syncgjaccount20' => '/syncGJAccount',

            // Level discovery and transfer.
            '/getgjlevels' => '/getGJLevels21',
            '/getgjlevels19' => '/getGJLevels21',
            '/getgjlevels20' => '/getGJLevels21',
            '/getgjlevels22' => '/getGJLevels21',
            '/downloadgjlevel' => '/downloadGJLevel21',
            '/downloadgjlevel19' => '/downloadGJLevel21',
            '/downloadgjlevel20' => '/downloadGJLevel21',
            '/uploadgjlevel' => '/uploadGJLevel21',
            '/uploadgjlevel19' => '/uploadGJLevel21',
            '/uploadgjlevel20' => '/uploadGJLevel21',

            // Comments and likes.
            '/getgjcomments' => '/getGJComments21',
            '/getgjcomments19' => '/getGJComments21',
            '/getgjcomments20' => '/getGJComments21',
            '/uploadgjcomment' => '/uploadGJComment20',
            '/uploadgjcomment19' => '/uploadGJComment20',
            '/uploadgjcomment21' => '/uploadgjcomment21',
            '/likegjitem' => '/likeGJItem21',
            '/likegjitem19' => '/likeGJItem21',
            '/likegjitem20' => '/likeGJItem21',

            // User and leaderboard endpoints.
            '/getgjuserinfo' => '/getGJUserInfo20',
            '/getgjuserinfo19' => '/getGJUserInfo20',
            '/getgjuserinfo21' => '/getGJUserInfo20',
            '/getgjuserinfo22' => '/getGJUserInfo20',
            '/getgjusers' => '/getGJUsers20',
            '/getgjusers19' => '/getGJUsers20',
            '/getgjusers21' => '/getGJUsers20',
            '/getgjusers22' => '/getGJUsers20',
            '/getgjscores' => '/getGJScores20',
            '/getgjscores19' => '/getGJScores20',
            '/getgjscores21' => '/getGJScores20',
            '/getgjscores22' => '/getGJScores20',
            '/updategjaccsettings19' => '/updateGJAccSettings20',
            '/updategjaccsettings21' => '/updateGJAccSettings20',
            '/updategjaccsettings22' => '/updateGJAccSettings20',
            '/updategjuserscore19' => '/updateGJUserScore',
            '/updategjuserscore20' => '/updateGJUserScore',
            '/updategjuserscore21' => '/updateGJUserScore',
            '/updategjuserscore22' => '/updategjuserscore22',

            // Discovery.
            '/getgjmappacks19' => '/getGJMapPacks21',
            '/getgjmappacks20' => '/getGJMapPacks21',
            '/getgjgauntlets19' => '/getGJGauntlets21',
            '/getgjgauntlets20' => '/getGJGauntlets21',

            // Level scores.
            '/getgjlevelscores19' => '/getGJLevelScores',
            '/getgjlevelscores20' => '/getGJLevelScores',
            '/getgjlevelscores21' => '/getGJLevelScores211',
            '/getgjlevelscores22' => '/getGJLevelScores',
        ];

        return $compatAliases[$path] ?? $path;
    }

    private function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $this->normalizePath($path);
    }
}
