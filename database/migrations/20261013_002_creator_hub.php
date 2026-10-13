<?php
declare(strict_types=1);
return [
"CREATE TABLE IF NOT EXISTS mucho_creator_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,level_id BIGINT UNSIGNED NOT NULL UNIQUE,account_id BIGINT UNSIGNED NOT NULL,
 state ENUM('submitted','in_review','changes_requested','approved','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
 level_name VARCHAR(64) NOT NULL,level_version INT UNSIGNED NOT NULL,content_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 author_note TEXT NOT NULL,cycle INT UNSIGNED NOT NULL DEFAULT 1,revision INT UNSIGNED NOT NULL DEFAULT 1,
 reviewer_kind ENUM('game','admin') NULL,reviewer_id BIGINT UNSIGNED NULL,reviewer_name VARCHAR(64) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(level_id) REFERENCES levels(level_id) ON DELETE CASCADE,FOREIGN KEY(account_id) REFERENCES accounts(account_id) ON DELETE CASCADE,
 INDEX(account_id,state),INDEX(state,updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS mucho_creator_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_id BIGINT UNSIGNED NOT NULL,cycle INT UNSIGNED NOT NULL,
 actor_kind ENUM('game','admin') NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,actor_name VARCHAR(64) NOT NULL,
 action VARCHAR(32) CHARACTER SET ascii NOT NULL,note TEXT NOT NULL,metadata_json TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(request_id) REFERENCES mucho_creator_requests(id) ON DELETE CASCADE,
 INDEX(request_id,id),INDEX(actor_kind,actor_id,action,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
