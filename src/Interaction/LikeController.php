<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\LegacyEndpoint;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class LikeController
{
    public function __construct(private LikeService $service) {}

    public function like(Request $r): Response
    {
        $itemId=$r->postInt('itemID');

        if ($itemId<=0) {
            $itemId=$r->postInt('levelID');
        }

        if ($itemId<=0) {
            return Response::text('-1');
        }

        $type=$r->postInt('type',1);

        if ($r->postInt('levelID')>0) {
            $type=1;
        }

        return LegacyEndpoint::text(function() use ($r,$itemId,$type): string {
            $accountId=$r->postInt('accountID');
            $gjp=$r->gdCredential();

            if ($accountId>0 && $gjp!=='') {
                $this->service->likeItem(
                    $itemId,
                    $type,
                    $accountId,
                    $gjp,
                    $r->postInt('like',1)===1
                );
            } else {
                $this->service->likeAnonymous(
                    $itemId,
                    $type,
                    $r->clientIp()
                );
            }

            return '1';
        });
    }
}
