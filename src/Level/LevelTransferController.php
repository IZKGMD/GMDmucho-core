<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class LevelTransferController
{
    public function __construct(
        private LevelTransferService $service
    ) {}

    private function credential(Request $request): string
    {
        /*
         * Старые клиенты используют gjp,
         * Geometry Dash 2.2 в ряде endpoint'ов использует gjp2.
         */
        return $request->postString('gjp')
            ?: $request->postString('gjp2');
    }

    public function upload(Request $request): Response
    {
        $accountId = $request->postInt('accountID');
        $credential = $this->credential($request);

        if ($accountId <= 0 || $credential === '') {
            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_level_rejected account_id=%d reason=missing_credentials has_gjp=%d has_gjp2=%d',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId,
                $request->postString('gjp') !== '' ? 1 : 0,
                $request->postString('gjp2') !== '' ? 1 : 0
            ));

            return Response::text('-1');
        }

        try {
            $levelId = $this->service->upload(
                $accountId,
                $credential,
                $request->post
            );

            return Response::text((string)$levelId);
        } catch (Throwable $e) {
            /*
             * Не логируем credential и levelString.
             */
            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_level_failed account_id=%d exception=%s message=%s file=%s line=%d',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return Response::text('-1');
        }
    }

    public function download(Request $request): Response
    {
        $levelId = $request->postInt('levelID');
        $gameVersion = $request->postInt('gameVersion', 22);
        $extras = $request->postInt('extras', 0) === 1;

        if (
            $levelId === 0 ||
            $levelId < -3
        ) {
            return Response::text('-1');
        }

        try {
            $result = $this->service->download(
                $levelId,
                $gameVersion,
                $extras
            );

            return Response::text($result);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s download_level_failed level_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function updateDescription(
        Request $request
    ): Response {
        $levelId = $request->postInt('levelID');
        $accountId = $request->postInt('accountID');
        $credential = $this->credential($request);
        $description = $request->postString('levelDesc');

        if (
            $levelId <= 0 ||
            $accountId <= 0 ||
            $credential === ''
        ) {
            return Response::text('-1');
        }

        try {
            $success = $this->service->updateDescription(
                $levelId,
                $accountId,
                $credential,
                $description
            );

            return Response::text(
                $success ? '1' : '-1'
            );

        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s update_level_desc_failed level_id=%d account_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $accountId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function delete(Request $request): Response
    {
        $levelId = $request->postInt('levelID');
        $accountId = $request->postInt('accountID');
        $credential = $this->credential($request);

        if (
            $levelId <= 0 ||
            $accountId <= 0 ||
            $credential === ''
        ) {
            return Response::text('-1');
        }

        try {
            $success = $this->service->delete(
                $levelId,
                $accountId,
                $credential
            );

            return Response::text($success ? '1' : '-1');
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s delete_level_failed level_id=%d account_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $accountId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }
}
