<?php

declare (strict_types=1);
namespace MuchoCore\Appearance;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDOException;
use Throwable;
final class AppearanceController
{
    public function __construct(AppearanceService $service)
    {
        $this->service = $service;
    }
    public function batch(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            return ['players' => $this->service->batch($request->postString('accountIDs'))];
        });
    }
    public function mine(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            return $this->service->mine($request->postInt('accountID'), $request->gdCredential());
        });
    }
    public function save(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            return $this->service->save($request->postInt('accountID'), $request->gdCredential(), $request->postString('nicknameColor'), array_key_exists('prefixColor', $request->post) ? $request->postString('prefixColor') : null);
        });
    }
    private function run(Request $request, callable $action): Response
    {
        if (strtoupper($request->method) !== 'POST') {
            return Response::json(['ok' => false, 'error' => 'Use POST.'], 405);
        }
        try {
            return Response::json(['ok' => true, 'data' => $action()]);
        } catch (Throwable $error) {
            error_log('[MuchoCore][Appearance] ' . \get_class($error) . ': ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => $error instanceof PDOException ? 'Appearance storage is unavailable. Update MuchoCore and check the server logs.' : $error->getMessage()], 400);
        }
    }
    private $service;
}
