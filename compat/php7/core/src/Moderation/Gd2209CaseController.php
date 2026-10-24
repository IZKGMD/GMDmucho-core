<?php

declare (strict_types=1);
namespace MuchoCore\Moderation;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;
/**
 * HTTP adapter for staged 2.209 moderation/report API.
 *
 * Status: real database effects, provisional native response serializers.
 * Never treat registered routes as proof of client protocol acceptance.
 */
final class Gd2209CaseController
{
    public function __construct(Gd2209CaseService $service)
    {
        $this->service = $service;
    }
    private function respond(Request $request, callable $callback): Response
    {
        if (strtoupper($request->method) !== 'POST' || $request->postInt('accountID') <= 0 || $request->gdCredential() === '') {
            return Response::text('-1');
        }
        try {
            return Response::text((string) $callback($request->postInt('accountID'), $request->gdCredential()));
        } catch (Throwable $e) {
            // Do not log request contents, tokens or private account records.
            error_log('[MuchoCore 2.209 moderation] ' . \get_class($e));
            return Response::text('-1');
        }
    }
    private function target(Request $request): int
    {
        return $request->postInt('targetAccountID');
    }
    public function reportContent(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->reportLevel($actor, $secret, $r->postInt('type'), $r->postInt('targetID'), $r->postInt('special'), $r->postString('reasons')) ? '1' : '-1';
        });
    }
    public function userHistory(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->history($actor, $secret, $this->target($r), $r->postInt('page'));
        });
    }
    public function reportedContent(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->reported($actor, $secret, $this->target($r), $r->postInt('page'));
        });
    }
    public function abuseInfo(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->abuseInfo($actor, $secret, $this->target($r));
        });
    }
    public function accountReview(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->accountReview($actor, $secret, $this->target($r));
        });
    }
    public function clearReportedContent(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->clearReports($actor, $secret, $this->target($r), $r->postString('items')) ? '1' : '-1';
        });
    }
    public function closeCase(Request $r): Response
    {
        return $this->respond($r, function (int $actor, string $secret) use ($r): string {
            return $this->service->closeCase($actor, $secret, $this->target($r), $r->postInt('outcome')) ? '1' : '-1';
        });
    }
    private $service;
}
