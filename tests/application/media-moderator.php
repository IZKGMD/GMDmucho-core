<?php
declare(strict_types=1);
use MuchoCore\Media\OpenAiImageModerator;
spl_autoload_register(static function(string $class):void {if(str_starts_with($class,'MuchoCore\\'))require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';});
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
$categories=['sexual'=>false,'violence'=>false,'violence/graphic'=>false,'self-harm'=>false,'self-harm/intent'=>false,'self-harm/instructions'=>false];
$types=array_fill_keys(array_keys($categories),['image','text']);
$r=['results'=>[['flagged'=>false,'categories'=>$categories,'category_applied_input_types'=>$types]]];
check(OpenAiImageModerator::decision($r)['state']==='approved','Valid safe image rejected');
$unsafe=$r;$unsafe['results'][0]['categories']['sexual']=true;check(OpenAiImageModerator::decision($unsafe)['state']==='rejected','Positive category ignored when flagged=false');
foreach([[],['results'=>[]],['results'=>[['flagged'=>false,'categories'=>[]]]]] as $broken)check(OpenAiImageModerator::decision($broken)['state']==='pending','Missing image classification approved');
$missing=$r;$missing['results'][0]['category_applied_input_types']['sexual']=['text'];check(OpenAiImageModerator::decision($missing)['state']==='pending','Text-only classification approved image');
$bad=$r;$bad['results'][0]['categories']['sexual']=0;check(OpenAiImageModerator::decision($bad)['state']==='pending','Coerced classifier boolean');
check(OpenAiImageModerator::decision(['results'=>[['flagged'=>false,'categories'=>['harassment'=>false]]]],false)['state']==='approved','Text result needs image categories');
echo "Moderation decisions fail closed with incomplete image classifications: OK\n";
