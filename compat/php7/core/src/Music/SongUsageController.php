<?php

declare (strict_types=1);
namespace MuchoCore\Music;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;
/** GD 2.209 public statistics for usage of Newgrounds songs. */
final class SongUsageController
{
    public function __construct(SongUsageService $service)
    {
        $this->service = $service;
    }
    public function get(Request $request): Response
    {
        $songId = $request->postInt('songID');
        if ($songId <= 0) {
            return Response::text('-1');
        }
        try {
            return Response::text($this->service->usage($songId));
        } catch (Throwable $e) {
            error_log('[MuchoCore] getGJSongUsage: ' . \get_class($e));
            return Response::text('-1');
        }
    }
    private $service;
}
