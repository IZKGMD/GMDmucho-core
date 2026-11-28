<?php

declare(strict_types=1);

/**
 * Administrator recovery storage must exist before the first browser request.
 * Creating schema from the public forgot-password route is not reliable on
 * least-privilege hosting accounts and can fail midway through recovery.
 *
 * This migration is additive for upgraded installations. Existing verified
 * email addresses, pending confirmations and reset tokens are preserved.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_email_recovery (
    admin_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    verified_email VARCHAR(254) NULL UNIQUE,
    verified_at DATETIME NULL,
    pending_email VARCHAR(254) NULL,
    verify_token_hash CHAR(64) NULL,
    verify_expires_at DATETIME NULL,
    reset_token_hash CHAR(64) NULL,
    reset_expires_at DATETIME NULL,
    reset_sent_at DATETIME NULL,
    KEY idx_verify (verify_token_hash),
    KEY idx_reset (reset_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
];
