<?php
declare(strict_types=1);

/*
 * Legacy admin entrypoint kept only for backwards compatibility.
 * The supported admin panel lives at /admin/.
 */

header('Location: /admin/', true, 302);
exit;
