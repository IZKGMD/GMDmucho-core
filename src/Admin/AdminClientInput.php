<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use InvalidArgumentException;

/**
 * Validate mutation identifiers and protocol values without changing them.
 * Clamping identifiers makes an invalid account or level target a real record.
 */
final class AdminClientInput
{
    public static function boundedInteger(
        array $input,
        string $key,
        int $minimum,
        int $maximum
    ): int {
        if ($minimum > $maximum
            || !array_key_exists($key, $input)
            || !is_int($input[$key])
            || $input[$key] < $minimum
            || $input[$key] > $maximum) {
            throw new InvalidArgumentException('Invalid integer field: ' . $key);
        }

        return $input[$key];
    }
}
