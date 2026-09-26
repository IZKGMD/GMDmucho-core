<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use MuchoCore\Http\LegacyEndpoint;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class SongController
{
    public function __construct(
        private SongService $service
    ) {}

    public function info(Request $request): Response
    {
        $songId=$request->postInt('songID');

        if ($songId<=0) {
            return Response::text('-1');
        }

        return LegacyEndpoint::text(
            fn(): string => $this->service->getSongInfo($songId)
        );
    }
}
