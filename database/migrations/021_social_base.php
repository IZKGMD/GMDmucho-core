<?php
declare(strict_types=1);

/** Canonical social persistence was previously supplied only by legacy imports. */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS friend_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    comment VARCHAR(600) NOT NULL DEFAULT '',
    is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_friend_request_pair (account_id,to_account_id),
    KEY idx_friend_request_inbox (to_account_id,id),
    CONSTRAINT fk_friend_request_from FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_friend_request_to FOREIGN KEY (to_account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS friends (
    account_id BIGINT UNSIGNED NOT NULL,
    friend_account_id BIGINT UNSIGNED NOT NULL,
    is_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id,friend_account_id),
    CONSTRAINT fk_friend_owner FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_friend_peer FOREIGN KEY (friend_account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS blocks (
    account_id BIGINT UNSIGNED NOT NULL,
    blocked_account_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (account_id,blocked_account_id),
    KEY idx_blocks_target (blocked_account_id,account_id),
    CONSTRAINT fk_block_owner FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_block_peer FOREIGN KEY (blocked_account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_sender_deleted TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_receiver_deleted TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_message_inbox (to_account_id,is_receiver_deleted,id),
    KEY idx_message_outbox (account_id,is_sender_deleted,id),
    CONSTRAINT fk_message_sender FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_receiver FOREIGN KEY (to_account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
