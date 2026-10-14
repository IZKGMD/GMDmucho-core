<?php
declare(strict_types=1);
namespace MuchoCore\Media;
interface ImageModerator {
    /** approved, rejected, or pending. Never publish a transport/worker failure. */
    public function classify(string $image,string $text): array;
}
