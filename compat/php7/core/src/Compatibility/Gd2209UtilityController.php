<?php

declare (strict_types=1);
namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;
final class Gd2209UtilityController
{
    public function __construct(Gd2209UtilityService $service)
    {
        $this->service = $service;
    }
    private function run(Request $r, callable $handler): Response
    {
        if (strtoupper($r->method) !== 'POST') {
            return Response::text('-1');
        }
        try {
            return Response::text((string) $handler());
        } catch (Throwable $e) {
            error_log('[MuchoCore GD2.209 utilities] ' . \get_class($e));
            return Response::text('-1');
        }
    }
    public function audioSize(Request $r): Response
    {
        return $this->run($r, function () use ($r): string {
            return $this->service->audioSizes($r->postString('songIDs'), $r->postString('sfxIDs'));
        });
    }
    public function contentStatus(Request $r): Response
    {
        if ($r->postInt('accountID') <= 0 || $r->gdCredential() === '') {
            return Response::text('-1');
        }
        return $this->run($r, function () use ($r): string {
            return $this->service->contentStatus($r->postInt('accountID'), $r->gdCredential(), $r->postInt('special'));
        });
    }
    public function openedRegister(Request $r): Response
    {
        return $this->run($r, function () use ($r): string {
            return $this->service->openedRegister($r->postString('secret'));
        });
    }
    public function levelRateInfo(Request $r): Response
    {
        foreach (['accountID', 'levelID'] as $key) {
            $value = $r->post[$key] ?? null;
            if (!is_string($value) && !is_int($value) || preg_match('/^[0-9]{1,18}$/D', (string) $value) !== 1 || (int) $value <= 0) {
                return Response::text('-1');
            }
        }
        return $this->run($r, function () use ($r): string {
            return $this->service->levelRateInfo($r->postInt('accountID'), $r->gdCredential(), $r->postInt('levelID'));
        });
    }
    public function savedLevelRatings(Request $r): Response
    {
        return $this->run($r, function () use ($r): string {
            return $this->service->savedLevelRatings($r->postString('secret'));
        });
    }
    private $service;
}
