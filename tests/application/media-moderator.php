<?php
declare(strict_types=1);
use MuchoCore\Media\LocalImageModerator;
spl_autoload_register(static function(string $class):void {if(str_starts_with($class,'MuchoCore\\'))require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';});
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
check(LocalImageModerator::decision(['schema_version'=>1,'local'=>true,'state'=>'approved','reason'=>'local_passed'])['state']==='approved','Valid local decision rejected');
foreach([[],['schema_version'=>1,'local'=>false,'state'=>'approved','reason'=>'local_passed'],['schema_version'=>1,'local'=>true,'state'=>'approved','reason'=>'unknown']] as $broken)check(LocalImageModerator::decision($broken)['state']==='pending','Invalid local decision approved');
check((new LocalImageModerator('/does/not/exist.sock'))->classify('','hello')['state']==='pending','Missing local worker failed open');
check((new LocalImageModerator('https://example.com'))->classify('','hello')['state']==='pending','Remote endpoint accepted as local IPC');
echo "Local-only moderation decisions and unavailable worker fail closed: OK\n";
