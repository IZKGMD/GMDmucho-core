<?php
declare(strict_types=1);

// Same authentication, CSRF, permissions, actions and audit trail as /admin/.
define('MUCHO_ADMIN_PLAIN', true);
require dirname(__DIR__).'/index.php';
