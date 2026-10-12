<?php

declare(strict_types=1);

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Plugin\PluginContext;
use MuchoCore\Plugin\PluginInterface;
use MuchoCore\Quest\DailyQuestController;
use MuchoCore\Quest\DailyQuestPolicy;
use MuchoCore\Quest\DailyQuestRepository;

return new class implements PluginInterface {
    public function register(PluginContext $context): void
    {
        if (!DailyQuestPolicy::enabled()) {
            return;
        }
        $db = $context->db();
        $controller = new DailyQuestController($db,new AccountAuthenticator($db),new DailyQuestRepository($db));
        $context->route('POST','/extensions/daily-quests',[$controller,'daily']);
        $context->route('POST','/extensions/daily-quests/claim',[$controller,'claim']);
        $context->clientFeature('daily-quests','Daily Quests','Three daily goals, claimable Quest XP and progression','/extensions/daily-quests','0.9.0');
    }
};
