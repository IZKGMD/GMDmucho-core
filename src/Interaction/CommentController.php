<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class CommentController
{
    public function __construct(
        private CommentService $service
    ) {}

    private function getPostParam(Request $request, string $key): string
    {
        return (string)($request->postString($key) ?: ($_POST[$key] ?? ''));
    }

    public function uploadLevelComment(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $request->gdCredential();
        $levelId = $request->postInt("levelID", 0) ?: (int)($_POST["levelID"] ?? 0);
        $content = $this->getPostParam($request, "comment");
        $percent = $request->postInt("percent", 0) ?: (int)($_POST["percent"] ?? 0);

        if ($accountId <= 0 || $levelId <= 0 || $content === "") {
            return Response::text("-1");
        }

        try {
            $id = $this->service->uploadLevelComment($levelId, $accountId, $gjp, $content, $percent);
            return Response::text($id > 0 ? (string)$id : "1");
        } catch (Throwable $e) {
            return Response::text("-1");
        }
    }

    public function getLevelComments(Request $request): Response
    {
        $levelId = $request->postInt("levelID", 0) ?: (int)($_POST["levelID"] ?? 0);
        $page = $request->postInt("page", 0) ?: (int)($_POST["page"] ?? 0);

        try {
            return Response::text($this->service->getLevelComments($levelId, $page));
        } catch (Throwable) {
            return Response::text("#0:0:10");
        }
    }

    public function uploadAccountComment(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $this->getPostParam($request, "gjp") ?: $this->getPostParam($request, "gjp2");
        $content = $this->getPostParam($request, "comment");

        if ($accountId <= 0 || $content === "") {
            return Response::text("-1");
        }

        try {
            $id = $this->service->uploadAccountComment($accountId, $gjp, $content);
            return Response::text($id > 0 ? (string)$id : "1");
        } catch (Throwable $e) {
            return Response::text("-1");
        }
    }

    public function getAccountComments(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $page = $request->postInt("page", 0) ?: (int)($_POST["page"] ?? 0);

        try {
            return Response::text($this->service->getAccountComments($accountId, $page));
        } catch (Throwable) {
            return Response::text("#0:0:10");
        }
    }

    public function deleteComment(Request $request): Response
    {
        $commentId = $request->postInt("commentID", 0) ?: (int)($_POST["commentID"] ?? 0);
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $this->getPostParam($request, "gjp") ?: $this->getPostParam($request, "gjp2");

        try {
            return Response::text($this->service->deleteComment($commentId, $accountId, $gjp) ? "1" : "-1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function deleteAccountComment(Request $request): Response
    {
        $commentId = $request->postInt("commentID", 0) ?: (int)($_POST["commentID"] ?? 0);
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $this->getPostParam($request, "gjp") ?: $this->getPostParam($request, "gjp2");

        try {
            return Response::text($this->service->deleteAccountComment($commentId, $accountId, $gjp) ? "1" : "-1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }
}
