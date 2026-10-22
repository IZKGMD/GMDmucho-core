<?php
/** Local-only SQL generator regression; no secrets or database needed. */
declare(strict_types=1);
require dirname(__DIR__,2).'/tools/mobile/php7-account-reset.php';
function resetAssert($condition,$label) {
    if (!$condition) { throw new RuntimeException($label); }
}
$sql = mucho7_local_reset_sql('2','izk','GoodTestPassword_123');
resetAssert(strpos($sql,'WHERE account_id=2 AND username=')!==false,'targeted account');
resetAssert(strpos($sql,'GoodTestPassword_123')===false,'no plaintext in SQL');
resetAssert(substr_count($sql,'$2y$12$')===2,'two bcrypt hashes');
foreach(array(array('0','izk','LongPasswordA'),array('2',"a' OR 1=1",'LongPasswordA'),
    array('2','izk','short')) as $bad) {
    try {
        mucho7_local_reset_sql($bad[0],$bad[1],$bad[2]);
        throw new RuntimeException('Accepted invalid reset input');
    } catch (InvalidArgumentException $expected) {}
}
echo "PHP7_TERMUX_LOCAL_PASSWORD_RESET_OK\n";
