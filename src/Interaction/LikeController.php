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

        $itemId = $r->postInt('itemID');
        $levelId = $r->postInt('levelID');

        if ($levelId > 0) {
            $itemId = $levelId;
        }

        if (
            $itemId <= 0 ||
            $r->postInt('accountID') <= 0 ||
            $gjp === ''
        ) {
            return Response::text('-1');
        }

        $type = $levelId > 0
            ? 1
            : $r->postInt('type');

        try {
            $this->service->likeItem(
                $itemId,
                $type,
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
