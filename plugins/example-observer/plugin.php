<?php

declare(strict_types=1);

use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;

return new class implements PluginInterface {
    public function register(PluginContext $context): void
    {
        $context->on(
            'request.completed',
            static function (array $payload): void {
                $request = $payload['request'] ?? null;
                $response = $payload['response'] ?? null;

                if (!$request || !$response) {
                    return;
                }

                error_log(
                    '[example-observer] ' .
                    $request->method . ' ' . $request->path .
                    ' -> ' . $response->status
                );
            }
        );
    }
};
