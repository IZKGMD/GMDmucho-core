<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Core\Environment;

/**
 * Opt-in, metadata-only diagnostics for GD private-message uploads.
 *
 * Never log credentials, message content, recipient identifiers or raw
 * request parameters. Disabled unless explicitly enabled on staging.
 */
final class MessageTrace
{
    private const PHASES = [
        'entry', 'protection', 'controller', 'authentication',
        'decoding', 'policy', 'storage',
    ];

    private const REASONS = [
        'seen', 'allowed', 'blocked', 'called', 'rejected', 'exception',
        'invalid_subject', 'invalid_body', 'message_banned', 'invalid_target',
        'empty_subject', 'empty_body', 'subject_too_long', 'body_too_long',
        'recipient_policy', 'stored', 'insert_rejected', 'other',
    ];

    public static function emit(string $phase, string $reason): void
    {
        if (Environment::get('MUCHO_MESSAGE_DIAGNOSTICS', '0') !== '1') {
            return;
        }

        if (!in_array($phase, self::PHASES, true)) {
            return;
        }

        // Reasons must be internal, allowlisted codes, never user text.
        if (!in_array($reason, self::REASONS, true)) {
            $reason = 'other';
        }

        $requestId = (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '');
        if (preg_match('/\A[a-f0-9]{16}\z/D', $requestId) !== 1) {
            $requestId = '-';
        }

        error_log(sprintf(
            '[MuchoCore MessageTrace] request_id=%s phase=%s reason=%s',
            $requestId,
            $phase,
            $reason
        ));
    }
}
