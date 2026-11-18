<?php
/**
 * Geometry Dash 2.209 native recovery URL alias.
 * Reuse the same CSRF/session/rate-limited recovery controller as the
 * canonical /api/api/accounts/lostusername.php browser page.
 * Apache uses this physical file; Caddy also has a matching rewrite.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/api/api/accounts/lostusername.php';
