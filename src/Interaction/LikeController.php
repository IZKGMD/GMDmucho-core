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
        // A malformed declared account must never be treated as an
        // anonymous actor. postInt() returns -1 for invalid/overflow.
        $hasAccount = array_key_exists('accountID', $r->post);
        $accountId = $hasAccount ? $r->postInt('accountID', -1) : 0;
        if ($accountId < 0) {
            return Response::text('-1');
        }

        $hasItem = array_key_exists('itemID', $r->post);
        $itemId = $hasItem ? $r->postInt('itemID', -1) : 0;
        $type = $r->postInt('type', 1);
        $isLike = $r->postInt('like', 1);

        if ($itemId < 0 || !in_array($type, [1, 2, 3, 4], true)
            || !in_array($isLike, [0, 1], true)) {
            return Response::text('-1');
        }

        // levelID is a fallback only for old level votes. A comment vote
        // can carry levelID as context without changing its item type.
        if (!$hasItem || $itemId === 0) {
            if ($type !== 1) {
                return Response::text('-1');
            }
            $itemId = $r->postInt('levelID', -1);
        }
        if ($itemId <= 0) {
            return Response::text('-1');
        }

        try {
            $gjp = $r->gdCredential();
            if ($accountId > 0) {
                if ($gjp === '') {
                    return Response::text('-1');
                }
                $success = $this->service->likeItem(
                    $itemId, $type, $accountId, $gjp, $isLike === 1
                );
            } else {
                // A request with credentials but no account number must
                // not bypass authentication via anonymous voting.
                if ($gjp !== '') {
                    return Response::text('-1');
                }
                $success = $this->service->likeAnonymous(
                    $itemId, $type, $r->clientIp(), $isLike === 1
                );
            }
            return Response::text($success ? '1' : '-1');
        } catch (Throwable $e) {
            return Response::text('-1');
        }
    }
}
