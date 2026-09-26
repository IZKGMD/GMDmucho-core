<?php

declare(strict_types=1);

namespace MuchoCore\Routing;

require_once __DIR__ . '/CompatibilityAliases.php';

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

        $key = strtoupper($method) . ' ' . $this->normalizePath($path);
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
            $method . ' ' . $path,
            'ANY ' . $path,
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

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if ($path === '') {
            $path = '/';
        }

        $path = preg_replace('#\.php$#i', '', $path) ?? $path;

        if ($path === '') {
            $path = '/';
        }

        $path = strtolower($path);

        return CompatibilityAliases::all()[$path] ?? $path;
    }

}
