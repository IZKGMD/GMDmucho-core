<?php

declare (strict_types=1);
namespace MuchoCore\Social;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;
final class NotificationController
{
    public function __construct(NotificationService $service)
    {
        $this->service = $service;
    }
    public function get(Request $r): Response
    {
        try {
            return Response::text($this->service->list($r->postInt('accountID'), $r->gdCredential(), $r->postInt('page')));
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    public function claim(Request $r): Response
    {
        try {
            $id = $r->postInt('notificationID', $r->postInt('id'));
            return Response::text($this->service->claim($r->postInt('accountID'), $r->gdCredential(), $id) ? '1' : '-1');
        } catch (Throwable $__mcCaught) {
            return Response::text('-1');
        }
    }
    private $service;
}
