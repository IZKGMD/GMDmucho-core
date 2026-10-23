<?php

declare (strict_types=1);
namespace MuchoCore\Clan;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
final class ClanQuestController
{
    public function __construct(PDO $pdo, AccountAuthenticator $auth, ClanQuestRepository $repository)
    {
        $this->pdo = $pdo;
        $this->auth = $auth;
        $this->repository = $repository;
    }
    public function quests(Request $request): Response
    {
        return $this->run($request, 'quests');
    }
    public function create(Request $request): Response
    {
        return $this->run($request, 'create');
    }
    public function cancel(Request $request): Response
    {
        return $this->run($request, 'cancel');
    }
    public function contributions(Request $request): Response
    {
        return $this->run($request, 'contributions');
    }
    public function activity(Request $request): Response
    {
        return $this->run($request, 'activity');
    }
    private function run(Request $request, string $action): Response
    {
        if ($request->method !== 'POST') {
            return Response::json(['ok' => false, 'error' => 'POST required.'], 405);
        }
        try {
            $account = $request->postInt('accountID');
            $this->auth->authenticate($account, $request->gdCredential());
            $this->pdo->beginTransaction();
            $member = $this->repository->lockForAccount($account);
            if (!$member) {
                throw new RuntimeException('You are not in a clan.');
            }
            if (in_array($action, ['create', 'cancel'], true) && !in_array($member['role'], ['owner', 'officer'], true)) {
                throw new RuntimeException('Clan officer permission required.');
            }
            $clan = (int) $member['clan_id'];
            $offset = max(0, min(100000, $request->postInt('offset')));
            $limit = max(1, min(50, $request->postInt('limit', 20)));
            $data = (function () use (&$action, &$clan, &$account, &$request, &$offset, &$limit) {
                $__mcMatch1 = $action;
                if ($__mcMatch1 === 'create') {
                    return $this->repository->create($clan, $account, $request->postString('metric'), $request->postInt('target'));
                }
                if ($__mcMatch1 === 'cancel') {
                    return $this->repository->cancel($clan, $account, $request->postInt('questID'));
                }
                if ($__mcMatch1 === 'contributions') {
                    return $this->repository->contributions($clan, $request->postInt('questID'), $offset, $limit);
                }
                if ($__mcMatch1 === 'activity') {
                    return $this->repository->activity($clan, $offset, $limit);
                }
                return $this->repository->quests($clan);
            })();
            $data['can_manage'] = in_array($member['role'], ['owner', 'officer'], true);
            $this->pdo->commit();
            return Response::json(['ok' => true, 'data' => $data]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[MuchoCore][ClanQuest] ' . \get_class($e) . ': ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => $e instanceof PDOException ? 'Clan quests are unavailable. Update MuchoCore and run migrations.' : $e->getMessage()], 400);
        }
    }
    private $pdo;
    private $auth;
    private $repository;
}
