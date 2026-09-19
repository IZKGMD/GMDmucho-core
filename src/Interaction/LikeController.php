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

        if (
            $r->postInt('itemID') <= 0 ||
            $r->postInt('accountID') <= 0 ||
            $gjp === ''
        ) {
            return Response::text('-1');
        }

        try {
            $this->service->likeItem(
                $r->postInt('itemID'),
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
