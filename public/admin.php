<?php

declare(strict_types=1);

/*
 * MuchoCore legacy admin entrypoint.
 * Kept for old bookmarks and integrations.
 * The maintained admin panel lives under /admin/.
 * Copyright (C) 2026 IZK
 */

header('Cache-Control: no-store');
header('Location: /admin/?page=dashboard', true, 302);
exit;
