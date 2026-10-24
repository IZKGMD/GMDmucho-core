<?php
/**
 * Copy to config/php7-local.php and fill your own database credentials.
 * Keep this file outside public/, do not commit real credentials.
 *
 * This PHP7 adapter uses your OWN MySQL database. It does not contact a VPS.
 * Local accounts, public levels/users/comments, writes and cloud saves.
 * Open /mc/admin.php to initialize an EMPTY database or claim the first owner
 * of an existing native installation using the private database password.
 */
return array(
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'your_muchocore_db',
    'username' => 'your_db_user',
    'password' => 'REPLACE_LOCALLY_NOT_IN_GIT',
    'auth_pepper' => '', // Generated automatically when assigning the first owner.
    'enable_accounts' => false,
    'enable_registration' => false,
    'enable_writes' => false,
    'enable_cloudsave' => false,
    'enable_social' => false,
    // Native News is independent of the player's GDPS domain.
    // Enabling changes the custom content CDN base; check other game assets.
    'enable_native_news' => false,
    'site_url' => 'https://YOUR-OWN-GDPS.5v.pl'
);
