<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Database\Database;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;

final readonly class Application
{
    private RequestPipeline $pipeline;

    public function __construct()
    {
        $router=new Router();
        $pdo=(new Database())->connection();
        $services=AppServices::build($pdo,$router);
        $plugins=$services['plugins'];

        $plugins->load();
        AppRoutes::register($router,$services);
        $plugins->boot();

        $this->pipeline=new RequestPipeline(
            $router,
            $services['protect'],
            $plugins
        );
    }

    public function handle(Request $request): Response
    {
        return $this->pipeline->handle($request);
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
