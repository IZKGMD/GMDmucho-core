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

    public function apply(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'applied'=>$this->service->apply(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('clanID'),
                    $request->postString('message')
                ),
            ];
        });
    }

    public function applications(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'applications'=>$this->service->applications(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function clanApplications(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'applications'=>$this->service->clanApplications(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function acceptApplication(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'accepted'=>$this->service->acceptApplication(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('applicationID')
                ),
            ];
        });
    }

    public function declineApplication(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'declined'=>$this->service->declineApplication(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('applicationID')
                ),
            ];
        });
    }

    public function cancelApplication(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'cancelled'=>$this->service->cancelApplication(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('applicationID')
                ),
            ];
        });
    }

    public function stats(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->stats(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postInt('clanID')
            );
        });
    }

    public function rankings(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->rankings(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postString('metric','stars'),
                $request->postInt('limit',25)
            );
        });
    }

    public function permissions(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->permissions(
                $request->postInt('accountID'),
                $request->gdCredential()
            );
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

    public function updateSettings(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->updateSettings(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postInt('clanID'),
                $request->postString('clanName'),
                $request->postString('clanTag'),
                $request->postString('clanDescription'),
                $request->postInt('clanOpen',1)===1,
                $request->postInt('clanMaxMembers',50)
            );
        });
    }

    public function transferOwnership(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'transferred'=>$this->service->transferOwnership(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function disband(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'disbanded'=>$this->service->disband(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function delete(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'deleted'=>$this->service->delete(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function revokeInvite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'revoked'=>$this->service->revokeInvite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('inviteID')
                ),
            ];
        });
    }

    public function ban(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'banned'=>$this->service->ban(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID'),
                    $request->postString('reason')
                ),
            ];
        });
    }

    public function unban(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'unbanned'=>$this->service->unban(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function bans(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'bans'=>$this->service->bans(
                    $request->postInt('accountID'),
                    $request->gdCredential()
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
