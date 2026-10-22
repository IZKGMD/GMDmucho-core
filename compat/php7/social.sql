-- MuchoCore PHP7 native friends additive upgrade for EXISTING MySQL database.
-- Execute once in phpMyAdmin in the existing database. Safe to re-import.
-- Never clears player accounts or existing php7_* tables.
CREATE TABLE IF NOT EXISTS php7_friend_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_id BIGINT UNSIGNED NOT NULL,
    receiver_id BIGINT UNSIGNED NOT NULL,
    comment VARCHAR(600) NOT NULL DEFAULT '',
    is_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_native_request(sender_id,receiver_id),
    KEY idx_native_inbox(receiver_id,is_new,id),
    KEY idx_native_sent(sender_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS php7_friendships (
    account_low BIGINT UNSIGNED NOT NULL,
    account_high BIGINT UNSIGNED NOT NULL,
    low_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
    high_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(account_low,account_high),
    KEY idx_native_friends_high(account_high)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
