<?php

declare(strict_types=1);

namespace MuchoCore\Http;

use Throwable;

final class LegacyEndpoint
{
    public static function text(callable $action): Response
    {
        try {
            return Response::text((string)$action());
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
