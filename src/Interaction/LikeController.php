<?php
declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class LikeController
{
    public function __construct(private LikeService $service) {}

    public function like(Request $r): Response
    {
        $gjp = $r->gdCredential();

        $itemId = $r->postInt('itemID', 0);

        if ($itemId <= 0) {
            $itemId = $r->postInt('levelID', 0);
        }

        $accountId = $r->postInt('accountID', 0);

        if ($itemId <= 0) {
            return Response::text('-1');
        }

        try {
            if ($accountId > 0 && $gjp !== '') {
                $this->service->likeItem(
                    $itemId,
                    $r->postInt('type', 1),
                    $accountId,
                    $gjp,
                    $r->postInt('like', 1) === 1
                );
            } else {
                $this->service->likeAnonymous(
                    $itemId,
                    $r->postInt('type', 1),
                    $r->clientIp()
                );
            }

            return Response::text('1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    private function unused(): void
    {
        /*
         * Kept intentionally empty so old integrations that reflect this
         * controller do not encounter a missing private symbol.
         */
    }

    /*
     * Legacy implementation retained below for source compatibility.
     */
    private function legacyLike(Request $r): Response
    {
        try {
            $this->service->likeItem(
                $itemId,
                $r->postInt('type'),
                $r->postInt('accountID'),
                $gjp,
                $r->postInt('like', 1) === 1
            );
            return Response::text('1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
