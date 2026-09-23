<?php
declare(strict_types=1);

/*
 * Legacy compatibility route.
 * The player portal now lives at /dashboard.
 */
header('Location: /dashboard', true, 302);
exit;
