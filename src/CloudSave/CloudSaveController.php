<?php

declare(strict_types=1);

namespace MuchoCore\CloudSave;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/*
 * MuchoCore Secure Cloud Save Controller
 * Copyright (C) 2026 IZK
 */
final readonly class CloudSaveController
{
    public function __construct(
        private CloudSaveService $service
    ) {}

    public function backup(Request $request): Response
    {
        return $this->run('cloud_backup_failed', $request, fn(array $data): string =>
            $this->service->backup($data)
        );
    }

    public function sync(Request $request): Response
    {
        return $this->run('cloud_sync_failed', $request, fn(array $data): string =>
            $this->service->sync($data)
        );
    }

    private function run(
        string $event,
        Request $request,
        callable $action
    ): Response {
        $data=$request->post;

        try {
            return Response::text($action($data));
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s %s account_id=%d exception=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $event,
                (int)($data['accountID'] ?? 0),
                $e::class
            ));

            return Response::text('-1');
        }
    }
}
