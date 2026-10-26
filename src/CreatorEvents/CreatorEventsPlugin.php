<?php
declare(strict_types=1);
namespace MuchoCore\CreatorEvents;

use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;

final class CreatorEventsPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $controller=new CreatorEventsController($context->db());
        $context->route('ANY','/extensions/creator-events',[$controller,'handle']);
    }
}
