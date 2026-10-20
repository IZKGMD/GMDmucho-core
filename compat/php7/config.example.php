<?php
/**
 * Copy to config/php7-local.php ONLY for an explicit experimental PHP7 test.
 * Keep this file outside public/, do not commit real credentials.
 *
 * This PHP7 adapter uses your OWN MySQL database. It does not contact a VPS.
 * Currently supported: /health and read-only getGJLevels* public searches.
 * Account logins, uploads, private searches and admin are NOT implemented.
 */
return array(
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'your_muchocore_db',
    'username' => 'your_db_user',
    'password' => 'REPLACE_LOCALLY_NOT_IN_GIT'
);
