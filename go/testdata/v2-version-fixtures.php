<?php
declare(strict_types=1);
$versions=['','0','1','1.0','1.0.0','1.0-dev','1.0alpha1','1.0beta2','1.0RC1','1.0pl1','2.0','01.00.00','1_0+2','garbage','1.','1..0','#N#','1.0.99999999999999999999','v1.0.0','1.2foo'];
$result=[];
foreach($versions as $a)foreach($versions as $b)$result[]=['a'=>$a,'b'=>$b,'result'=>version_compare($a,$b)];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
