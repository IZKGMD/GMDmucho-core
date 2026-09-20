<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http/Request.php';
require __DIR__ . '/../src/Http/Response.php';
require __DIR__ . '/../src/Routing/Router.php';

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;

$router = new Router();

$router->add(
    'POST',
    '/getGJLevels21',
    static fn(Request $request): Response => Response::text('LEVELS_OK')
);

$router->add(
    'POST',
    '/loginGJAccount',
    static fn(Request $request): Response => Response::text('LOGIN_OK')
);

function assertText(string $expected, Response $response, string $name): void
{
    if ($response->body !== $expected) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL %s: expected %s, got %s\n",
                $name,
                var_export($expected, true),
                var_export($response->body, true)
            )
        );
        exit(1);
    }

    echo "PASS {$name}\n";
}

assertText(
    'LEVELS_OK',
    $router->dispatch(
        new Request(
            'POST',
            '/a/database/getGJLevels20.php',
            [],
            [],
            []
        )
    ),
    'generated /a/database compatibility prefix'
);

assertText(
    'LEVELS_OK',
    $router->dispatch(
        new Request(
            'POST',
            '/api/getGJLevels20.php/',
            [],
            [],
            []
        )
    ),
    'api prefix and trailing slash'
);

assertText(
    'LOGIN_OK',
    $router->dispatch(
        new Request(
            'POST',
            '/accounts/loginGJAccount22.php',
            [],
            [],
            []
        )
    ),
    'accounts prefix and 2.2 login alias'
);

assertText(
    'LOGIN_OK',
    $router->dispatch(
        new Request(
            'POST',
            '/A/loginGJAccount.php?ignored=1',
            [],
            [],
            []
        )
    ),
    'single-letter prefix and query stripping'
);

echo "MUCHOCORE_ROUTER_COMPATIBILITY_OK\n";
