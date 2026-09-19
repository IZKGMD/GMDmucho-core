<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Action Dispatcher v6.1
 * Copyright (C) 2026 IZK
 */

if(
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    !== 'POST'
){
    return;
}

$action=
    (string)(
        $_POST['action']
        ?? ''
    );


/*
 * Empty POST action:
 * retain compatibility with legacy behavior.
 */
if($action===''){
    require __DIR__.
        '/legacy-dispatcher.php';

    return;
}


$router=
    buildMuchoAdminActionRouter();


/*
 * Known modular action.
 */
if($router->has($action)){

    $handler=
        $router->handler($action);

    if(
        !is_string($handler) ||
        !is_file($handler)
    ){
        throw new RuntimeException(
            'Admin action handler unavailable.'
        );
    }

    require $handler;

    return;
}


/*
 * Fallback is mandatory during migration:
 * advanced-actions.php and any older/private actions
 * continue behaving exactly as before.
 */
require __DIR__.
    '/legacy-dispatcher.php';
