<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Http\LegacyEndpoint;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class RelationshipController
{
    public function __construct(private RelationshipService $service) {}

    public function send(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->send(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('toAccountID'),
                $r->postString('comment')
            ) ? '1' : '-1'
        );
    }

    public function get(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->get(
                $r->postInt('accountID'),
                $r->gdCredential(),
                max(0,$r->postInt('page')),
                $r->postInt('getSent')===1
            )
        );
    }

    public function read(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->read(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('requestID')
            ) ? '1' : '-1'
        );
    }

    public function accept(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->accept(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('requestID')
            ) ? '1' : '-1'
        );
    }

    public function delete(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->delete(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('targetAccountID'),
                $r->postInt('isSender')===1
            ) ? '1' : '-1'
        );
    }

    public function remove(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->remove(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('targetAccountID')
            ) ? '1' : '-1'
        );
    }

    public function block(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->block(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('targetAccountID')
            ) ? '1' : '-1'
        );
    }

    public function unblock(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->unblock(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('targetAccountID')
            ) ? '1' : '-1'
        );
    }

    public function userList(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->userList(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('type')
            )
        );
    }
}
