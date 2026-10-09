<?php

declare(strict_types=1);

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;

return new class implements PluginInterface {
    public function register(PluginContext $context): void
    {
        // One menu item appears in every MuchoClient installation.
        $context->clientFeature(
            'welcome',
            'Welcome',
            'A live server-powered test of the single-Geode plugin system',
            '/extensions/welcome'
        );

        // JSON-only: MuchoClient never downloads or executes PHP.
        $context->route(
            'GET',
            '/extensions/welcome',
            static fn(Request $request): Response => Response::json([
                'schema_version' => 1,
                'feature_id' => 'welcome',
                'title' => 'MuchoCore Connected',
                'message' => 'This content comes from a live server plugin. ' .
                    'No additional Geode mod is needed. MuchoClient is your ' .
                    'bridge to server extensions!',
            ])
        );
    }
};
