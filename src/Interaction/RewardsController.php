<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\LegacyEndpoint;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class RewardsController
{
    public function __construct(
        private RewardsService $service
    ) {}

    public function getRewards(Request $request): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->rewards(
                $request->postInt('accountID'),
                $request->postString('udid'),
                $request->postString('chk'),
                $request->gdCredential(),
                $request->postInt('rewardType')
            )
        );
    }

    public function getSecretReward(Request $request): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->secretReward(
                $request->postInt('accountID'),
                $request->postString('udid'),
                $request->postString('chk'),
                $request->gdCredential(),
                $request->postString('rewardKey'),
                $request->postString('secret'),
                $request->clientIp()
            )
        );
    }

    public function getChallenges(Request $request): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->challenges(
                $request->postInt('accountID'),
                $request->postString('udid'),
                $request->postString('chk'),
                $request->gdCredential()
            )
        );
    }
}
