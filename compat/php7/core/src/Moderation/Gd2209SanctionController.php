<?php

declare (strict_types=1);
namespace MuchoCore\Moderation;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;
/** Strictly authenticated HTTP adapters for four persisted 2.209 ban pairs. */
final class Gd2209SanctionController
{
    public function __construct(Gd2209SanctionService $service)
    {
        $this->service = $service;
    }
    public function apply(Request $r, string $kind, bool $enabled): Response
    {
        if (strtoupper($r->method) !== 'POST' || !$this->integerField($r, 'accountID') || !$this->integerField($r, 'targetAccountID') || !$this->integerField($r, 'durationMinutes', true) || $r->postInt('accountID') < 1 || $r->gdCredential() === '') {
            return Response::text('-1');
        }
        try {
            return Response::text($this->service->change($r->postInt('accountID'), $r->gdCredential(), $r->postInt('targetAccountID'), $kind, $enabled, $r->postInt('durationMinutes')) ? '1' : '-1');
        } catch (Throwable $e) {
            error_log('[MuchoCore 2.209 sanctions] ' . \get_class($e));
            return Response::text('-1');
        }
    }
    private function integerField(Request $r, string $key, bool $optional = false): bool
    {
        if (!array_key_exists($key, $r->post)) {
            return $optional;
        }
        $value = $r->post[$key];
        if (!is_int($value) && !is_string($value)) {
            return false;
        }
        $value = (string) $value;
        return preg_match('/^[0-9]{1,18}$/D', $value) === 1 && (int) $value >= 0;
    }
    private $service;
}
