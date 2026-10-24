<?php

declare (strict_types=1);
namespace MuchoCore\Music;

use MuchoCore\Protocol\GdSongEncoder;
final class SongService
{
    public function __construct(SongRepository $repository, GdSongEncoder $encoder)
    {
        $this->repository = $repository;
        $this->encoder = $encoder;
    }
    public function getSongInfo(int $songId): string
    {
        $song = $this->repository->findSong($songId);
        if (!$song) {
            return '-1';
        }
        return $this->encoder->encode($song);
    }
    private $repository;
    private $encoder;
}
