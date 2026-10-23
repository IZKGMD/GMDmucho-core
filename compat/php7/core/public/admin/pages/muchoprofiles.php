<?php

declare (strict_types=1);
/*
 * MuchoCore Admin — Mucho Profiles
 * Extracted verbatim from legacy admin.
 * Copyright (C) 2026 IZK
 */
if (true) {
    requireRank(30);
    $db->exec("\n        CREATE TABLE IF NOT EXISTS mucho_profile_customization (\n            account_id BIGINT PRIMARY KEY,\n            display_name VARCHAR(32) NOT NULL DEFAULT '',\n            status VARCHAR(48) NOT NULL DEFAULT '',\n            bio VARCHAR(240) NOT NULL DEFAULT '',\n            theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',\n            theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',\n            banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',\n            title VARCHAR(48) NOT NULL DEFAULT '',\n            badges TEXT NOT NULL,\n            pinned_levels VARCHAR(96) NOT NULL DEFAULT '',\n            showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',\n            favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',\n            online_visible TINYINT NOT NULL DEFAULT 1,\n            edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n                ON UPDATE CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
    $issued = $_SESSION['muchoprofile_issued_token'] ?? null;
    unset($_SESSION['muchoprofile_issued_token']);
    if (is_array($issued)) {
        ?>
        <div class="card" style="border-color:#39d995;margin-bottom:16px">
            <b>New edit token for Account #<?php 
        echo h($issued['id']);
        ?></b>
            <p style="color:#8792a7">
                Copy it now. Only its SHA-256 hash is stored on the server.
            </p>
            <input
                style="width:100%"
                readonly
                value="<?php 
        echo h($issued['token']);
        ?>"
                onclick="this.select()"
            >
        </div>
        <?php 
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    ?>
    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Mucho Profiles</h2>
        <p style="color:#8792a7">
            Server-synced profile personalization.
            Title and badges are administrator-controlled.
        </p>

        <form class="search">
            <input type="hidden" name="page" value="muchoprofiles">
            <input
                name="q"
                value="<?php 
    echo h($q);
    ?>"
                placeholder="Username / Account ID"
            >
            <button>Search</button>
        </form>
    </div>
    <?php 
    $sql = "\n        SELECT\n            a.account_id,\n            a.username,\n            a.email,\n            a.role,\n\n            m.display_name AS mp_display_name,\n            m.status AS mp_status,\n            m.bio AS mp_bio,\n            m.theme_primary AS mp_primary,\n            m.theme_secondary AS mp_secondary,\n            m.banner AS mp_banner,\n            m.title AS mp_title,\n            m.badges AS mp_badges,\n            m.pinned_levels AS mp_pinned,\n            m.showcase AS mp_showcase,\n            m.favorite_difficulty AS mp_favorite,\n            m.online_visible AS mp_online,\n            m.updated_at AS mp_updated\n\n        FROM accounts a\n\n        LEFT JOIN mucho_profile_customization m\n            ON m.account_id=a.account_id\n    ";
    $args = [];
    if ($q !== '') {
        $sql .= "\n            WHERE\n                a.username LIKE :q\n                OR a.account_id=:id\n        ";
        $args = ['q' => '%' . $q . '%', 'id' => ctype_digit($q) ? (int) $q : 0];
    }
    $sql .= " ORDER BY a.account_id DESC LIMIT 100";
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $badgesRaw = (string) ($r['mp_badges'] ?? '[]');
        $badgesDecoded = \mucho7_json_decode($badgesRaw, true);
        $badgesText = is_array($badgesDecoded) ? implode(', ', $badgesDecoded) : $badgesRaw;
        $primary = (string) ($r['mp_primary'] ?: '#42D9CF');
        $secondary = (string) ($r['mp_secondary'] ?: '#806EFF');
        $online = $r['mp_online'];
        if ($online === null) {
            $online = 1;
        }
        ?>
        <div class="card" style="margin:14px 0">

            <div class="row" style="justify-content:space-between">
                <div>
                    <b style="font-size:18px">
                        #<?php 
        echo h($r['account_id']);
        ?>
                        <?php 
        echo h($r['username']);
        ?>
                    </b>

                    <span class="badge">
                        <?php 
        echo h($r['role']);
        ?>
                    </span>
                </div>

                <?php 
        if (!empty($r['mp_updated'])) {
            ?>
                    <small style="color:#8792a7">
                        Updated <?php 
            echo h($r['mp_updated']);
            ?>
                    </small>
                <?php 
        }
        ?>
            </div>

            <form
                method="post"
                style="
                    margin-top:16px;
                    display:grid;
                    gap:12px;
                    grid-template-columns:
                    repeat(auto-fit,minmax(220px,1fr))
                "
            >
                <input type="hidden" name="csrf" value="<?php 
        echo csrf();
        ?>">
                <input type="hidden" name="action" value="muchoprofile-save">
                <input type="hidden" name="return" value="muchoprofiles">
                <input type="hidden" name="id" value="<?php 
        echo h($r['account_id']);
        ?>">

                <label>
                    <small>Display name</small>
                    <input
                        style="width:100%"
                        name="display_name"
                        maxlength="32"
                        value="<?php 
        echo h($r['mp_display_name'] ?? '');
        ?>"
                    >
                </label>

                <label>
                    <small>Status</small>
                    <input
                        style="width:100%"
                        name="status"
                        maxlength="48"
                        value="<?php 
        echo h($r['mp_status'] ?? '');
        ?>"
                    >
                </label>

                <label>
                    <small>Title — server only</small>
                    <input
                        style="width:100%"
                        name="title"
                        maxlength="48"
                        placeholder="OWNER"
                        value="<?php 
        echo h($r['mp_title'] ?? '');
        ?>"
                    >
                </label>

                <label>
                    <small>Badges — server only</small>
                    <input
                        style="width:100%"
                        name="badges"
                        placeholder="OWNER, IZK"
                        value="<?php 
        echo h($badgesText);
        ?>"
                    >
                </label>

                <label>
                    <small>Primary color</small>
                    <input
                        style="width:100%"
                        name="theme_primary"
                        value="<?php 
        echo h($primary);
        ?>"
                        maxlength="7"
                    >
                </label>

                <label>
                    <small>Secondary color</small>
                    <input
                        style="width:100%"
                        name="theme_secondary"
                        value="<?php 
        echo h($secondary);
        ?>"
                        maxlength="7"
                    >
                </label>

                <label>
                    <small>Banner preset</small>
                    <input
                        style="width:100%"
                        name="banner"
                        maxlength="64"
                        value="<?php 
        echo h($r['mp_banner'] ?: 'gradient_01');
        ?>"
                    >
                </label>

                <label>
                    <small>Favorite difficulty</small>
                    <input
                        style="width:100%"
                        name="favorite_difficulty"
                        maxlength="32"
                        value="<?php 
        echo h($r['mp_favorite'] ?? '');
        ?>"
                    >
                </label>

                <label>
                    <small>Pinned levels — max 3 IDs</small>
                    <input
                        style="width:100%"
                        name="pinned_levels"
                        placeholder="12, 45, 99"
                        value="<?php 
        echo h($r['mp_pinned'] ?? '');
        ?>"
                    >
                </label>

                <label>
                    <small>Showcase</small>
                    <input
                        style="width:100%"
                        name="showcase"
                        maxlength="200"
                        value="<?php 
        echo h($r['mp_showcase'] ?: 'stars,demons,creator_points');
        ?>"
                    >
                </label>

                <label style="grid-column:1/-1">
                    <small>Bio</small>
                    <textarea
                        style="width:100%;min-height:90px"
                        name="bio"
                        maxlength="240"
                    ><?php 
        echo h($r['mp_bio'] ?? '');
        ?></textarea>
                </label>

                <label
                    style="
                        display:flex;
                        gap:8px;
                        align-items:center
                    "
                >
                    <input
                        type="checkbox"
                        name="online_visible"
                        <?php 
        echo $online ? 'checked' : '';
        ?>
                    >
                    Online visibility
                </label>

                <div>
                    <button type="submit">
                        Save Mucho Profile
                    </button>
                </div>
            </form>

            <form
                method="post"
                style="margin-top:12px"
                onsubmit="return confirm('Generate a new edit token? The old token will immediately stop working.')"
            >
                <input type="hidden" name="csrf" value="<?php 
        echo csrf();
        ?>">
                <input type="hidden" name="action" value="muchoprofile-token">
                <input type="hidden" name="return" value="muchoprofiles">
                <input type="hidden" name="id" value="<?php 
        echo h($r['account_id']);
        ?>">

                <button type="submit" class="btn">
                    Regenerate edit token
                </button>
            </form>

        </div>
        <?php 
    }
}
/* /MUCHO_PROFILE_ADMIN_V1 */
/* =========================================================
   PLAYERS
========================================================= */
