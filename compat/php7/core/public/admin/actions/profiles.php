<?php

declare (strict_types=1);
/*
 * MuchoCore Admin Actions — Profiles
 *
 * profile-save
 * muchoprofile-save
 * muchoprofile-token
 *
 * Extracted from verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */
if (!in_array($action, ['profile-save', 'muchoprofile-save', 'muchoprofile-token'], true)) {
    throw new RuntimeException('Invalid Profiles action.');
}
try {
    if ($action === 'profile-save') {
        requireRank(30);
        $id = (int) $_POST['id'];
        $fields = ['stars', 'moons', 'diamonds', 'secret_coins', 'user_coins', 'demons', 'creator_points'];
        $set = [];
        $args = ['id' => $id];
        foreach ($fields as $f) {
            $set[] = "`{$f}`=:{$f}";
            $args[$f] = max(0, (int) ($_POST[$f] ?? 0));
        }
        $q = $db->prepare('UPDATE profiles SET ' . implode(',', $set) . ' WHERE account_id=:id');
        $q->execute($args);
        audit($db, 'profile.save', (string) $id);
        flash('Statistics saved.');
    } elseif ($action === 'muchoprofile-save') {
        requireRank(30);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid Account ID.');
        }
        $check = $db->prepare('SELECT account_id FROM accounts
                 WHERE account_id=:id LIMIT 1');
        $check->execute(['id' => $id]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Account not found.');
        }
        $db->exec("\n                CREATE TABLE IF NOT EXISTS mucho_profile_customization (\n                    account_id BIGINT PRIMARY KEY,\n                    display_name VARCHAR(32) NOT NULL DEFAULT '',\n                    status VARCHAR(48) NOT NULL DEFAULT '',\n                    bio VARCHAR(240) NOT NULL DEFAULT '',\n                    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',\n                    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',\n                    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',\n                    title VARCHAR(48) NOT NULL DEFAULT '',\n                    badges TEXT NOT NULL,\n                    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',\n                    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',\n                    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',\n                    online_visible TINYINT NOT NULL DEFAULT 1,\n                    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',\n                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n                        ON UPDATE CURRENT_TIMESTAMP\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n            ");
        $ensure = $db->prepare("\n                INSERT INTO mucho_profile_customization\n                (\n                    account_id,\n                    badges\n                )\n                VALUES\n                (\n                    :id,\n                    '[]'\n                )\n                ON DUPLICATE KEY UPDATE\n                    account_id=VALUES(account_id)\n            ");
        $ensure->execute(['id' => $id]);
        $cut = static function (string $v, int $max): string {
            $v = trim(str_replace(["\x00", "\r"], '', $v));
            return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
        };
        $displayName = $cut((string) ($_POST['display_name'] ?? ''), 32);
        $status = $cut((string) ($_POST['status'] ?? ''), 48);
        $bio = $cut((string) ($_POST['bio'] ?? ''), 240);
        $title = $cut((string) ($_POST['title'] ?? ''), 48);
        $primary = strtoupper(trim((string) ($_POST['theme_primary'] ?? '')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $primary)) {
            $primary = '#42D9CF';
        }
        $secondary = strtoupper(trim((string) ($_POST['theme_secondary'] ?? '')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $secondary)) {
            $secondary = '#806EFF';
        }
        $banner = trim((string) ($_POST['banner'] ?? 'gradient_01'));
        if (!preg_match('/^[A-Za-z0-9_-]{0,64}$/', $banner)) {
            $banner = 'gradient_01';
        }
        $favorite = $cut((string) ($_POST['favorite_difficulty'] ?? ''), 32);
        $showcase = $cut((string) ($_POST['showcase'] ?? ''), 200);
        /*
         * Badges are controlled by administrators only.
         * Accept:
         * OWNER, IZK
         * or JSON ["OWNER","IZK"]
         */
        $badgeInput = trim((string) ($_POST['badges'] ?? ''));
        $badges = [];
        if ($badgeInput !== '') {
            if (str_starts_with($badgeInput, '[')) {
                $decoded = \mucho7_json_decode($badgeInput, true);
                if (is_array($decoded)) {
                    $badges = $decoded;
                }
            } else {
                $badges = preg_split('/[,\r\n]+/', $badgeInput) ?: [];
            }
        }
        $cleanBadges = [];
        foreach ($badges as $badge) {
            $badge = $cut((string) $badge, 32);
            if ($badge === '') {
                continue;
            }
            if (!in_array($badge, $cleanBadges, true)) {
                $cleanBadges[] = $badge;
            }
            if (count($cleanBadges) >= 8) {
                break;
            }
        }
        $badgeJson = \mucho7_json_encode($cleanBadges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pinned = [];
        foreach (preg_split('/[,\s]+/', trim((string) ($_POST['pinned_levels'] ?? ''))) ?: [] as $levelID) {
            if ($levelID !== '' && ctype_digit($levelID) && (int) $levelID > 0) {
                $pinned[] = (string) (int) $levelID;
            }
            if (count($pinned) >= 3) {
                break;
            }
        }
        $q = $db->prepare("\n                UPDATE mucho_profile_customization SET\n                    display_name=:display_name,\n                    status=:status,\n                    bio=:bio,\n                    theme_primary=:primary,\n                    theme_secondary=:secondary,\n                    banner=:banner,\n                    title=:title,\n                    badges=:badges,\n                    pinned_levels=:pinned,\n                    showcase=:showcase,\n                    favorite_difficulty=:favorite,\n                    online_visible=:online\n                WHERE account_id=:id\n            ");
        $q->execute(['display_name' => $displayName, 'status' => $status, 'bio' => $bio, 'primary' => $primary, 'secondary' => $secondary, 'banner' => $banner, 'title' => $title, 'badges' => $badgeJson, 'pinned' => implode(',', $pinned), 'showcase' => $showcase, 'favorite' => $favorite, 'online' => isset($_POST['online_visible']) ? 1 : 0, 'id' => $id]);
        audit($db, 'muchoprofile.save', (string) $id, ['title' => $title, 'badges' => $cleanBadges]);
        flash('Mucho Profile saved.');
    } elseif ($action === 'muchoprofile-token') {
        requireRank(30);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid Account ID.');
        }
        $check = $db->prepare('SELECT account_id FROM accounts
                 WHERE account_id=:id LIMIT 1');
        $check->execute(['id' => $id]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Account not found.');
        }
        $db->exec("\n                CREATE TABLE IF NOT EXISTS mucho_profile_customization (\n                    account_id BIGINT PRIMARY KEY,\n                    display_name VARCHAR(32) NOT NULL DEFAULT '',\n                    status VARCHAR(48) NOT NULL DEFAULT '',\n                    bio VARCHAR(240) NOT NULL DEFAULT '',\n                    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',\n                    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',\n                    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',\n                    title VARCHAR(48) NOT NULL DEFAULT '',\n                    badges TEXT NOT NULL,\n                    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',\n                    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',\n                    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',\n                    online_visible TINYINT NOT NULL DEFAULT 1,\n                    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',\n                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n                        ON UPDATE CURRENT_TIMESTAMP\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n            ");
        $ensure = $db->prepare("\n                INSERT INTO mucho_profile_customization\n                (account_id,badges)\n                VALUES (:id,'[]')\n                ON DUPLICATE KEY UPDATE\n                    account_id=VALUES(account_id)\n            ");
        $ensure->execute(['id' => $id]);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $q = $db->prepare("\n                UPDATE mucho_profile_customization\n                SET edit_token_hash=:hash\n                WHERE account_id=:id\n            ");
        $q->execute(['hash' => hash('sha256', $token), 'id' => $id]);
        $_SESSION['muchoprofile_issued_token'] = ['id' => $id, 'token' => $token];
        audit($db, 'muchoprofile.token.regenerate', (string) $id);
        flash('New Mucho Profile edit token generated.');
    }
} catch (Throwable $e) {
    flash('Error: ' . $e->getMessage(), 'error');
}
$return = (string) ($_POST['return'] ?? $_GET['page'] ?? 'muchoprofiles');
