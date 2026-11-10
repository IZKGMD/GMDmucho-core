<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/**
 * Native Geometry Dash user-info submission endpoint.
 */
final readonly class UserInfoController
{
    public function __construct(
        private UserInfoSubmissionService $service
    ) {}

    public function submit(Request $request): Response
    {
        $levelsInfo = $request->postString('levelsInfo');

        if ($levelsInfo === '') {
            return Response::text('-2');
        }

        try {
            $this->service->submit(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $levelsInfo
            );

            return Response::text('1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
