<?php

declare(strict_types=1);

namespace MuchoCore\Release;

/**
 * Prevent publication of a 1.1.0 stable tag while endpoint certification
 * remains a partial/draft exercise. Checks metadata only; CI validates the
 * referenced evidence and real-client fixtures separately.
 */
final class V110EndpointGate
{
    private const REQUIRED_P0 = [
        'account.login',
        'levels.search',
        'levels.upload',
        'levels.download',
        'users.leaderboard',
        'comments.read',
    ];

    /** @return list<string> */
    public static function issues(array $registry): array
    {
        $issues = [];
        $policy = $registry['policy'] ?? null;
        $contracts = $registry['contracts'] ?? null;

        if (
            ($registry['schema_version'] ?? null) !== 1 ||
            !is_array($policy) ||
            !is_array($contracts) ||
            !array_is_list($contracts)
        ) {
            return ['Endpoint registry schema is invalid.'];
        }

        if (($policy['target_release'] ?? '') !== '1.1.0') {
            $issues[] = 'Endpoint registry target_release must be 1.1.0.';
        }

        // Explicit attestation must be made only after the entire route
        // registry and real-client certification are complete.
        if (($policy['certification_complete'] ?? false) !== true) {
            $issues[] = 'Full endpoint coverage has not been certified.';
        }

        $required = $policy['required_dimensions'] ?? null;
        if (!is_array($required) || !array_is_list($required)) {
            return [...$issues, 'Required quality dimensions are absent.'];
        }

        foreach ([
            'request_contract', 'wire_contract', 'auth_contract',
            'negative_cases', 'db_integrity', 'concurrency',
            'performance_budget', 'real_client',
        ] as $dimension) {
            if (!in_array($dimension, $required, true)) {
                $issues[] = 'Missing required dimension: ' . $dimension;
            }
        }

        $seen = [];
        foreach ($contracts as $contract) {
            if (!is_array($contract) || !is_string($contract['id'] ?? null)) {
                $issues[] = 'Malformed endpoint contract.';
                continue;
            }
            $id = $contract['id'];
            if (isset($seen[$id])) {
                $issues[] = 'Duplicate endpoint contract: ' . $id;
            }
            $seen[$id] = true;

            if (($contract['release_gate'] ?? false) !== true) {
                $issues[] = $id . ' is not release-gated.';
            }

            foreach ($required as $dimension) {
                if (!is_string($dimension)) {
                    $issues[] = 'Invalid quality dimension.';
                    continue;
                }
                $quality = $contract[$dimension] ?? null;
                $status = is_array($quality) ? ($quality['status'] ?? null) : null;
                if ($status !== 'covered' && $status !== 'n/a') {
                    $issues[] = $id . '/' . $dimension . ' is not certified.';
                }
            }

            foreach (['performance_budget', 'real_client'] as $hardGate) {
                if (($contract[$hardGate]['status'] ?? null) !== 'covered') {
                    $issues[] = $id . '/' . $hardGate . ' needs measured evidence.';
                }
            }
        }

        foreach (self::REQUIRED_P0 as $id) {
            if (!isset($seen[$id])) {
                $issues[] = 'Required P0 endpoint missing: ' . $id;
            }
        }

        return array_values(array_unique($issues));
    }
}
