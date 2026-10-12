<?php

declare(strict_types=1);

namespace MuchoCore\Quest;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDO;
use PDOException;
use Throwable;

final readonly class DailyQuestController
{
    public function __construct(private PDO $pdo, private AccountAuthenticator $auth, private DailyQuestRepository $repository) {}

    public function daily(Request $request): Response { return $this->run($request, false); }
    public function claim(Request $request): Response { return $this->run($request, true); }

    private function run(Request $request, bool $claim): Response
    {
        if (strtoupper($request->method) !== 'POST') {
            return Response::json(['ok'=>false,'error'=>'Daily Quests requires MuchoClient 0.9.0 and an authenticated POST request.'],405);
        }
        try {
            $account = $request->postInt('accountID');
            $this->auth->authenticate($account, $request->gdCredential());
            $this->pdo->beginTransaction();
            $calendar = $this->repository->prepare($account);
            $data = $claim
                ? $this->repository->claim($account,$request->postString('day'),$request->postString('questID'),$calendar)
                : $this->repository->snapshot($account,$calendar);
            $this->pdo->commit();
            return Response::json(['ok'=>true,'data'=>$data]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $storage = $e instanceof PDOException;
            $unauthorized = $e->getMessage() === 'Unauthorized.';
            error_log('[MuchoCore][DailyQuests] ' . $e::class);
            return Response::json(['ok'=>false,'error'=>$storage
                ? 'Daily Quest storage is unavailable. Update MuchoCore and run its migrations.'
                : ($unauthorized ? 'Unauthorized.' : $e->getMessage())],$storage ? 503 : ($unauthorized ? 401 : 400));
        }
    }
}
