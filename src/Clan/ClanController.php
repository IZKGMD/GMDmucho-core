<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class ClanController
{
    public function __construct(private ClanService $service) {}

    public function create(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->create(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postString('clanName'),
                $request->postString('clanTag'),
                $request->postString('clanDescription'),
                $request->postInt('clanOpen',1)===1,
                $request->postInt('clanMaxMembers',50)
            );
        });
    }

    public function myClan(Request $request): Response
    {
        return $this->run(function() use ($request): ?array {
            return $this->service->myClan(
                $request->postInt('accountID'),
                $request->gdCredential()
            );
        });
    }

    public function get(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->get(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postInt('clanID')
            );
        });
    }

    public function search(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'clans'=>$this->service->search(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postString('query')
                ),
            ];
        });
    }

    public function join(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'joined'=>$this->service->join(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('clanID')
                ),
            ];
        });
    }

    public function leave(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'left'=>$this->service->leave(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function invite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'invited'=>$this->service->invite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function acceptInvite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'accepted'=>$this->service->acceptInvite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('inviteID')
                ),
            ];
        });
    }

    public function declineInvite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'declined'=>$this->service->declineInvite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('inviteID')
                ),
            ];
        });
    }

    public function kick(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'kicked'=>$this->service->kick(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function setRole(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'updated'=>$this->service->setRole(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID'),
                    $request->postString('role')
                ),
            ];
        });
    }

    public function invites(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'invites'=>$this->service->invites(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    private function run(callable $action): Response
    {
        try {
            return Response::json([
                'ok'=>true,
                'data'=>$action(),
            ]);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore][Clan] %s: %s',
                $e::class,
                $e->getMessage()
            ));

            return Response::json([
                'ok'=>false,
                'error'=>$e->getMessage(),
            ],400);
        }
    }
}
