<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Compatibility\CompatibilityProfile;
use MuchoCore\Database\Database;
use MuchoCore\Diagnostics\ClientTrace;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;
use MuchoCore\Security\MuchoProtect;
use PDO;
use Throwable;

final readonly class Application
{
    private Router $router;
    private PDO $pdo;
    private MuchoProtect $protect;
    private PluginManager $plugins;

    public function __construct()
    {
        $this->pdo = (new Database())->connection();
        $this->router = new Router();

        $services = AppServices::build($this->pdo, $this->router);

        $this->protect = $services['protect'];
        $this->plugins = $services['plugins'];

        $this->plugins->load();
        AppRoutes::register($this->router, $services);
        $this->plugins->boot();
    }

    public function handle(Request $request): Response
    {
        ClientTrace::captureRequest($request);
        $this->plugins->emit('request.received', [
            'request' => $request,
        ]);

        if (($_SERVER['MUCHO_PROTECT_PRECHECKED'] ?? '') !== '1') {
            $protection = $this->protect->inspect(
                $request,
                $this->router->normalizePath($request->path)
            );

            if ($protection['decision'] === 'block') {
                $response = Response::text('-1');
                $this->plugins->emit('request.completed', [
                    'request' => $request,
                    'response' => $response,
                    'blocked' => true,
                ]);
                ClientTrace::captureResponse($response);
                return $response;
            }
        }

        try {
            $profile = CompatibilityProfile::fromEnvironment();

            if (!$profile->allows($request->clientVersion())) {
                $response = Response::text('-1');
                $this->plugins->emit('request.completed', [
                    'request' => $request,
                    'response' => $response,
                    'compatible' => false,
                ]);
                ClientTrace::captureResponse($response);
                return $response;
            }

            $response = $this->router->dispatch($request);

            $this->plugins->emit('request.completed', [
                'request' => $request,
                'response' => $response,
                'compatible' => true,
            ]);
            ClientTrace::captureResponse($response);

            return $response;
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] %s %s | %s: %s | %s:%d',
                (string)($request->method ?? '?'),
                (string)($request->path ?? '?'),
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $response = Response::text('-1');
            $this->plugins->emit('request.failed', [
                'request' => $request,
                'error' => $e,
                'response' => $response,
            ]);
            ClientTrace::captureResponse($response);

            return $response;
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
