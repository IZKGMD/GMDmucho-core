<?php
declare(strict_types=1);
use MuchoCore\Plugin\PluginInterface;
use MuchoCore\Plugin\PluginContext;
use MuchoCore\Creator\CreatorHubController;
use MuchoCore\Creator\CreatorHubService;
use MuchoCore\Account\AccountAuthenticator;
return new class implements PluginInterface {
 public function register(PluginContext $context):void {
  $db=$context->db();$controller=new CreatorHubController(new AccountAuthenticator($db),new CreatorHubService($db));
  $context->route('POST','/extensions/creator-hub',[$controller,'handle']);
  $context->clientFeature('creator-hub','Creator Hub','Submit levels, track review and read moderator feedback.','/extensions/creator-hub','1.1.0');
 }
};
