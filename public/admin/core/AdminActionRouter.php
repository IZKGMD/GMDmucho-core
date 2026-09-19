<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Action Router
 * Copyright (C) 2026 IZK
 */

final class MuchoAdminActionRouter
{
    private array $routes=[];


    public function register(
        string $action,
        string $handler
    ): self {

        if(
            !preg_match(
                '/^[a-z0-9._-]{1,80}$/',
                $action
            )
        ){
            throw new InvalidArgumentException(
                'Invalid admin action: '.$action
            );
        }

        if(
            isset(
                $this->routes[$action]
            )
        ){
            throw new RuntimeException(
                'Duplicate admin action: '.$action
            );
        }

        $this->routes[$action]=$handler;

        return $this;
    }


    public function has(
        string $action
    ): bool {
        return isset(
            $this->routes[$action]
        );
    }


    public function handler(
        string $action
    ): ?string {
        return $this->routes[$action]
            ?? null;
    }


    public function actions(): array
    {
        return array_keys(
            $this->routes
        );
    }
}


function buildMuchoAdminActionRouter(): MuchoAdminActionRouter
{
    $router=
        new MuchoAdminActionRouter();

    $map=require
        __DIR__.'/../actions/map.php';

    if(!is_array($map)){
        throw new RuntimeException(
            'Invalid admin action map.'
        );
    }

    foreach(
        $map as $group=>$actions
    ){
        if(
            !preg_match(
                '/^[a-z0-9_-]+$/',
                (string)$group
            )
        ){
            throw new RuntimeException(
                'Invalid action group.'
            );
        }

        $handler=
            __DIR__.
            '/../actions/'.
            $group.
            '.php';

        if(!is_file($handler)){
            throw new RuntimeException(
                'Missing action handler: '.
                $group
            );
        }

        foreach($actions as $action){

            $router->register(
                (string)$action,
                $handler
            );
        }
    }

    return $router;
}
