<?php

declare(strict_types=1);

use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;

return new class implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->route(
            'GET',
            '/extensions/welcome',
            static fn(): string => 'Welcome to MuchoCore!'
        );
    }
};
