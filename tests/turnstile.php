<?php

declare(strict_types=1);

use MuchoCore\Security\Turnstile;

require dirname(__DIR__) . '/vendor/autoload.php';

$previousSite = getenv('TURNSTILE_SITEKEY');
$previousSecret = getenv('TURNSTILE_SECRET');

putenv('TURNSTILE_SITEKEY=');
putenv('TURNSTILE_SECRET=');

assert(Turnstile::siteKey() === '');
assert(Turnstile::enabled() === false);
assert(Turnstile::verify('', 'login') === false);

if ($previousSite === false) {
    putenv('TURNSTILE_SITEKEY');
} else {
    putenv('TURNSTILE_SITEKEY=' . $previousSite);
}

if ($previousSecret === false) {
    putenv('TURNSTILE_SECRET');
} else {
    putenv('TURNSTILE_SECRET=' . $previousSecret);
}

echo "Turnstile tests passed.\n";
