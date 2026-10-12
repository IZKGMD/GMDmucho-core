<?php

declare(strict_types=1);

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;

return new class implements PluginInterface {
    public function register(PluginContext $context): void
    {
        $title = $context->config('title', 'MuchoGDPS News');
        $message = $context->config('message', 'Welcome to MuchoGDPS!');
        if (!is_string($title) || trim($title) === '' || strlen($title) > 128 ||
            !is_string($message) || trim($message) === '' || strlen($message) > 4096) {
            throw new RuntimeException('News needs a title (1–128 bytes) and message (1–4096 bytes).');
        }
        $context->clientFeature(
            'news',
            'Server News',
            'Announcements and event details from your GDPS',
            '/extensions/news'
        );
        $context->route(
            'GET',
            '/extensions/news',
            static fn(Request $request): Response => Response::json([
                'schema_version' => 1,
                'feature_id' => 'news',
                'title' => $title,
                'message' => $message,
            ])
        );
    }
};
