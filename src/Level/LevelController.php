<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class LevelController
{
    public function __construct(
        private LevelService $service
    ) {
    }

    public function list(Request $request): Response
    {
        return Response::text(
            $this->service->getLevels($request->post)
        );
    }
}
