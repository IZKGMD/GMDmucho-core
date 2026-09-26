<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Compatibility\CompatibilityProfile;
use MuchoCore\Diagnostics\ClientTrace;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;
use MuchoCore\Security\MuchoProtect;
use Throwable;

final readonly class RequestPipeline
{
    public function __construct(
        private Router $router,
        private MuchoProtect $protect,
        private PluginManager $plugins
    ) {}

    public function handle(Request $request): Response
    {
        ClientTrace::captureRequest($request);
        $this->plugins->emit('request.received', [
            'request' => $request,
        ]);

        if (($_SERVER['MUCHO_PROTECT_PRECHECKED'] ?? '') !== '1') {
            $protection=$this->protect->inspect(
                $request,
                $this->router->normalizePath($request->path)
            );

            if ($protection['decision']==='block') {
                return $this->complete(
                    $request,
                    Response::text('-1'),
                    ['blocked'=>true]
                );
            }
        }

        try {
            $profile=CompatibilityProfile::fromEnvironment();

            if (!$profile->allows($request->clientVersion())) {
                return $this->complete(
                    $request,
                    Response::text('-1'),
                    ['compatible'=>false]
                );
            }

            return $this->complete(
                $request,
                $this->router->dispatch($request),
                ['compatible'=>true]
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] %s %s | %s: %s | %s:%d',
                $request->method,
                $request->path,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $response=Response::text('-1');

            $this->plugins->emit('request.failed', [
                'request'=>$request,
                'error'=>$e,
                'response'=>$response,
            ]);
            ClientTrace::captureResponse($response);

            return $response;
        }
    }

    private function complete(
        Request $request,
        Response $response,
        array $metadata
    ): Response {
        $this->plugins->emit('request.completed', array_merge(
            ['request'=>$request,'response'=>$response],
            $metadata
        ));
        ClientTrace::captureResponse($response);

        return $response;
    }
}
