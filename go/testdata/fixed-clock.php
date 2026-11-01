<?php
// Deterministic wall clock for differential tests; never loaded by a runtime.
namespace MuchoCore\Interaction {
    function time(): int { return 1700000000; }
    function gmdate(string $format, ?int $timestamp=null): string { return \gmdate($format,$timestamp??1700000000); }
    function strtotime(string $value, ?int $base=null): int|false { return \strtotime($value,$base??1700000000); }
}
namespace MuchoCore\Score {
    function time(): int { return 1700000000; }
}
