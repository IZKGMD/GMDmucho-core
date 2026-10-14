<?php
declare(strict_types=1);
namespace MuchoCore\Media;
interface ImageModerator {
    /** approved, rejected, or pending. Never publish a transport/provider failure. */
    public function classify(string $jpeg,string $text): array;
}
