<?php
/** Original additive News migrations, generated for PHP7. */
return array(
<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_news_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(140) NOT NULL,
    body TEXT NOT NULL,
    external_url VARCHAR(512) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    author_admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    KEY idx_news_published (is_published, is_pinned, published_at),
    KEY idx_news_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_news_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    version BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
INSERT IGNORE INTO mucho_news_state (id, version) VALUES (1, 1000000)
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_news_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('image','youtube') NOT NULL,
    filename VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    target_url VARCHAR(512) NULL,
    image_data MEDIUMBLOB NOT NULL,
    KEY idx_news_media_post (post_id,id),
    KEY idx_news_media_filename (filename),
    CONSTRAINT fk_news_media_post FOREIGN KEY (post_id)
        REFERENCES mucho_news_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
