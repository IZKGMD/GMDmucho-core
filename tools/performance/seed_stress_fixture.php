<?php

declare(strict_types=1);

// Synthetic accounts are valid only in the dedicated mucho_stress database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ((string) getenv('DB_NAME') !== 'mucho_stress') {
    fwrite(STDERR, "Refusing to seed any non-stress database\n");
    exit(1);
}

$db = (new \MuchoCore\Database\Database())->connection();
$db->beginTransaction();
try {
    $db->exec("INSERT IGNORE INTO accounts
      (account_id,username,email,password_hash)
      VALUES (1,'StressRobot','stress-robot@example.invalid','disabled')");
    $db->exec('INSERT IGNORE INTO profiles (account_id) VALUES (1)');
    $db->exec("INSERT IGNORE INTO levels
      (level_id,account_id,name,description,level_data,game_version)
      VALUES (1,1,'Synthetic Stress Level','Benchmark data only','1,2,3;',22)");
    $db->commit();
    echo "STRESS_FIXTURE_OK\n";
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
