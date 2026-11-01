<?php
declare(strict_types=1);
// All passwords and hashes in this file are synthetic test data.
$cases=[];
foreach(['test-password',str_repeat('a',72).'tail'] as $password){
    $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>4]);
    $cases[]=['password'=>$password,'hash'=>$hash,'valid'=>true];
    $cases[]=['password'=>'wrong','hash'=>$hash,'valid'=>false];
}
foreach([PASSWORD_ARGON2I,PASSWORD_ARGON2ID] as $algorithm){
    $hash=password_hash('test-password',$algorithm,['memory_cost'=>8192,'time_cost'=>1,'threads'=>1]);
    $cases[]=['password'=>'test-password','hash'=>$hash,'valid'=>true];
    $cases[]=['password'=>'wrong','hash'=>$hash,'valid'=>false];
}
echo json_encode($cases,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
