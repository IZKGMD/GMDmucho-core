<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Http\LegacyEndpoint;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class MessageController
{
    public function __construct(private MessageService $service) {}

    public function getMessages(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->getMessages(
                $r->postInt('accountID'),
                $r->gdCredential(),
                max(0,$r->postInt('page')),
                $r->postInt('getSent')===1
            )
        );
    }

    public function readMessage(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->readMessage(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('messageID')
            )
        );
    }

    public function sendMessage(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->sendMessage(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('toAccountID'),
                $r->postString('subject'),
                $r->postString('body')
            ) ? '1' : '-1'
        );
    }

    public function deleteMessage(Request $r): Response
    {
        return LegacyEndpoint::text(fn(): string =>
            $this->service->deleteMessage(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('messageID'),
                $r->postInt('isSender')===1
            ) ? '1' : '-1'
        );
    }
}
