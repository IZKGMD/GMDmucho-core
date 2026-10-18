<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use RuntimeException;

final class MigrationPreflightGuard
{
    /**
     * Fail before any backup, schema migration, or import write if source data
     * would otherwise be silently lost. Missing keys are compatible with
     * adapters that do not expose these particular Cvolton diagnostics.
     *
     * @param array<string, mixed> $preflight
     */
    public static function assertSafe(array $preflight): void
    {
        foreach ([
            'levels_missing_data' => 'levels have no levelString in SQL; copy the source level files before migrating',
            'levels_without_account' => 'levels refer to accounts that are not present in the source accounts table',
            'ambiguous_profile_links' => 'accounts have multiple matching source profiles',
        ] as $key => $reason) {
            $count = (int)($preflight[$key] ?? 0);

            if ($count > 0) {
                throw new RuntimeException(
                    'Unsafe GDPS migration: ' . $count . ' ' . $reason .
                    '. The destination was not modified by this import.'
                );
            }
        }
    }
}
