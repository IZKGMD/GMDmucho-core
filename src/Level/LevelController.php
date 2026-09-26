<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\LegacyEndpoint;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class LevelController
{
    public function __construct(
        private LevelService $service,
        private AccountAuthenticator $auth
    ) {}

    public function list(Request $request): Response
    {
        $type=$request->postInt('type');

        if ($type===13) {
            $accountId=$request->postInt('accountID');
            $credential=$request->gdCredential();

            if ($accountId<=0 || $credential==='') {
                return Response::text('-1');
            }

            try {
                $this->auth->authenticate($accountId,$credential);
            } catch (\Throwable) {
                return Response::text('-1');
            }
        }

        return LegacyEndpoint::text(
            fn(): string => $this->service->getLevels($request->post)
        );
    }
}
