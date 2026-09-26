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
        $gjp=$request->gdCredential();
        $version=$request->clientVersion();
        $udid=$request->postString('udid');
        $username=$request->postString('userName');

        $legacyUdidUpdate=
            $id<=0 &&
            $gjp==='' &&
            $version->effectiveGameVersion()>0 &&
            $version->effectiveGameVersion()<19 &&
            trim($udid)!=='';

        if (($id<=0 || $gjp==='') && !$legacyUdidUpdate) {
            return Response::text('-1');
        }

        try {
            $data=$request->post;
            $data['gameVersion']=$request->postInt(
                'gameVersion',
                $version->effectiveGameVersion() ?: 1
            );

            return Response::text(
                $this->service->updateScore(
                    $id,
                    $gjp,
                    $data,
                    $udid,
                    $username,
                    $request->clientIp()
                )
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s update_user_score_failed account_id=%d version=%d family=%s has_udid=%d has_gjp=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $id,
                $version->effectiveGameVersion(),
                $version->family(),
                trim($udid)!=='' ? 1 : 0,
                $gjp!=='' ? 1 : 0,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function updateSettings(Request $request): Response
    {
        $accountId=$request->postInt('accountID');
        $gjp=$request->gdCredential();

        if ($accountId<=0 || $gjp==='') {
            return Response::text('-1');
        }

        try {
            $success=$this->service->updateSettings(
                $accountId,
                $gjp,
                $request->postInt('mS'),
                $request->postInt('frS'),
                $request->postInt('cS'),
                $request->postString('yt'),
                $request->postString('twitter'),
                $request->postString('twitch'),
                $request->postString('instagram'),
                $request->postString('discord'),
                $request->postString('tiktok'),
                $request->postString('custom')
            );

            return Response::text($success ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function search(Request $request): Response
    {
        $query=$request->postString('str');
        $page=$request->postInt('page');

        if ($query==='') {
            return Response::text('-1');
        }

        try {
            return Response::text(
                $this->service->search($query,$page)
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function profile(Request $request): Response
    {
        $targetAccountId=$request->postInt('targetAccountID')
            ?: $request->postInt('accountID');

        $targetUserId=$request->postInt('targetUserID')
            ?: $request->postInt('userID');

        try {
            if ($targetAccountId<=0 && $targetUserId>0) {
                $targetAccountId=
                    $this->service->resolveAccountIdByUserId($targetUserId);
            }

            if ($targetAccountId<=0) {
                return Response::text('-1');
            }

            $viewerAccountId=$request->postInt('accountID');

            if ($viewerAccountId>0) {
                $credential=$request->gdCredential();

                if ($credential==='') {
                    return Response::text('-1');
                }

                $this->service->authenticate(
                    $viewerAccountId,
                    $credential
                );
            }

            return Response::text(
                $this->service->getProfile(
                    $targetAccountId,
                    $viewerAccountId
                )
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
        try {
            return Response::text(
                $this->service->getLeaderboard(
                    $request->postString('type'),
                    $request->postInt('accountID'),
                    $request->clientVersion()->effectiveGameVersion(),
                    100,
                    $request->gdCredential()
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
