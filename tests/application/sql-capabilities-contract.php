<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Database/SqlCapabilities.php';

use MuchoCore\Database\SqlCapabilities;

foreach ([
    '5.7.44' => false,
    '8.0.29' => true,
    '8.4.3' => true,
    '5.5.5-10.4.32-MariaDB' => true,
    '10.4.32-MariaDB' => true,
    '10.1.48-MariaDB' => false,
    'unknown' => false,
] as $version => $expected) {
    if (SqlCapabilities::supportsWindowFunctionsForVersion($version) !== $expected) {
        throw new RuntimeException('Unexpected SQL capability for ' . $version);
    }
}
echo "sql-capabilities-contract: OK\n";
