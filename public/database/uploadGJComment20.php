<?php
declare(strict_types=1);

/*
 * Legacy Geometry Dash comment endpoint.
 *
 * Route all comment/moderation commands through the canonical application
 * so authentication and moderator role checks are applied consistently.
 */

$_SERVER['REQUEST_URI'] = '/uploadGJComment21';
require __DIR__ . '/../index.php';
