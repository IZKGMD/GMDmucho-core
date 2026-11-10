<?php

declare (strict_types=1);
namespace MuchoCore\Account;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/**
 * Native Geometry Dash user-info submission endpoint.
 */
final class UserInfoController
{
    public function __construct(UserInfoSubmissionService $service)
    {
        $this->service = $service;
    }
    public function submit(Request $request): Response
    {
        $levelsInfo = $request->postString('levelsInfo');
        try {
            $accepted = $this->service->submit($request->postInt('accountID'), $request->gdCredential(), $levelsInfo);
            return Response::text($accepted ? '1' : '-2');
        } catch (Throwable $e) {
            return Response::text('-1');
        }
    }
}
