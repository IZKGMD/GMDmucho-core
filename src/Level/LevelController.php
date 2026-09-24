<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class LevelController
{
    public function __construct(
        private LevelService $service,
        private AccountAuthenticator $auth
    ) {
    }

    public function list(Request $request): Response
    {
        $type = $request->postInt('type', 0);

        /* GD 2.1 Friends Levels (type=13) requires authenticated account context. */
        if ($type === 13) {
            $accountId = $request->postInt('accountID', 0);
            $credential = $request->gdCredential();

            if ($accountId <= 0 || $credential === '') {
                return Response::text('-1');
            }

            try {
                $this->auth->authenticate($accountId, $credential);
            } catch (\Throwable) {
                return Response::text('-1');
            }
        }

        try {
            return Response::text(
                $this->service->getLevels($request->post)
            );
        } catch (\Throwable) {
            return Response::text('-1');
        }
    }
}
