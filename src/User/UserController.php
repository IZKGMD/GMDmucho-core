<?php

declare(strict_types=1);

namespace MuchoCore\User;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class UserController
{
    public function __construct(
        private UserService $service
    ) {}

    public function requestAccess(Request $request): Response
    {
        $id=$request->postInt('accountID');
        $gjp=$request->gdCredential();

        if ($id<=0 || $gjp==='') {
            return Response::text('-1');
        }

        try {
            return Response::text(
                $this->service->requestAccess($id,$gjp)
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function updateScore(Request $request): Response
    {
        $id=$request->postInt('accountID');
        $gjp=$request->postString('gjp')
            ?: $request->postString('gjp2');

        if ($id<=0 || $gjp==='') {
            return Response::text('-1');
        }

        try {
            return Response::text(
                $this->service->updateScore(
                    $id,
                    $gjp,
                    !empty($request->post)
                        ? $request->post
                        : $_POST
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function updateSettings(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = (string)($request->gdCredential() ?: ($_POST["gjp"] ?? $_POST["gjp2"] ?? ""));
        $mS = (int)($request->postInt("mS", 0) ?: ($_POST["mS"] ?? 0));
        $frS = (int)($request->postInt("frS", 0) ?: ($_POST["frS"] ?? 0));
        $cS = (int)($request->postInt("cS", 0) ?: ($_POST["cS"] ?? 0));
        $yt = (string)($request->postString("yt") ?: ($_POST["yt"] ?? ""));
        $twitter = (string)($request->postString("twitter") ?: ($_POST["twitter"] ?? ""));
        $twitch = (string)($request->postString("twitch") ?: ($_POST["twitch"] ?? ""));
        $instagram = (string)($request->postString("instagram") ?: ($_POST["instagram"] ?? ""));
        $discord = (string)($request->postString("discord") ?: ($_POST["discord"] ?? ""));
        $tiktok = (string)($request->postString("tiktok") ?: ($_POST["tiktok"] ?? ""));
        $custom = (string)($request->postString("custom") ?: ($_POST["custom"] ?? ""));

        if ($accountId <= 0 || $gjp === "") {
            return Response::text("-1");
        }

        try {
            $success = $this->service->updateSettings(
                $accountId,
                $gjp,
                $mS,
                $frS,
                $cS,
                $yt,
                $twitter,
                $twitch,
                $instagram,
                $discord,
                $tiktok,
                $custom
            );
            return Response::text($success ? "1" : "-1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function search(Request $request): Response
    {
        $query = (string)($request->postString("str") ?: ($_POST["str"] ?? ""));
        $page = (int)($request->postInt("page", 0) ?: ($_POST["page"] ?? 0));

        if ($query === "") {
            return Response::text("-1");
        }

        try {
            $result = $this->service->search($query, $page);
            return Response::text($result);
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function profile(Request $request): Response
    {
        $targetAccountId = $request->postInt('targetAccountID');

        /* Some clients use accountID for the first self-profile request. */
        if ($targetAccountId <= 0) {
            $targetAccountId = $request->postInt('accountID');
        }

        $targetUserId = $request->postInt('targetUserID');

        if ($targetUserId <= 0) {
            $targetUserId = $request->postInt('userID');
        }

        try {
            if ($targetAccountId <= 0 && $targetUserId > 0) {
                $targetAccountId =
                    $this->service->resolveAccountIdByUserId(
                        $targetUserId
                    );
            }

            if ($targetAccountId <= 0) {
                return Response::text('-1');
            }

            return Response::text(
                $this->service->getProfile($targetAccountId)
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore][profile] account=%d user=%d | %s: %s',
                $targetAccountId,
                $targetUserId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function scores(Request $request): Response
    {
        $type=$request->postString('type');
        $accountId=$request->postInt('accountID');

        try {
            return Response::text(
                $this->service->getLeaderboard(
                    $type,
                    $accountId
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
