<?php
/** Run without vendor, MySQL or any PHP8 classes; supports PHP 7.0. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';

function check7($value, $label)
{
    if (!$value) {
        throw new RuntimeException('PHP7 native contract: ' . $label);
    }
}

check7(mucho7_path('/a/database/getGJLevels21.php') === '/getgjlevels21',
    'normalize nested GD endpoint');
check7(mucho7_path('/database/getGJLevels22.php?token=unused') === '/getgjlevels22',
    'normalize GD 2.2 alias');
check7(mucho7_path('/api/accounts/loginGJAccount20.php') === '/logingjaccount20',
    'normalize account alias');
check7(mucho7_field("New:\nLevel|#Test~", 30) === 'NewLevelTest',
    'sanitize GD wire delimiters');
check7(mucho7_username(" A:B|C ") === 'ABC',
    'username sanitization');
check7(mucho7_epoch('2026-10-20 00:00:00') === 1792454400,
    'GD 2.209 timestamp UTC');

check7(mucho7_dispatch(null, '/health', 'GET', array()) === '1',
    'native health without upstream');
check7(mucho7_dispatch(null, '/database/getGJLevels21.php', 'GET', array()) === '-1',
    'search must require POST');
check7(mucho7_dispatch(null, '/database/getGJLevels21.php', 'POST', array()) === '-1',
    'missing DB fails closed');
check7(mucho7_dispatch(null, '/database/loginGJAccount21.php', 'POST', array()) === '-1',
    'authentication never accepts unimplemented requests');
check7(mucho7_dispatch(null, '/database/uploadGJLevel21.php', 'POST', array()) === '-1',
    'level writes never accept unimplemented requests');
check7(mucho7_dispatch(null, '/getGJScores20.php', 'POST', array()) === '-1',
    'score access never accepts unimplemented requests');

echo "PHP7_NATIVE_READONLY_PURE_OK\n";
