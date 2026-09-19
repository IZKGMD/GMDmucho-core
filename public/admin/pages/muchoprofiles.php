<?php
declare(strict_types=1);

/*
 * MuchoCore Admin — Mucho Profiles
 * Extracted verbatim from legacy admin.
 * Copyright (C) 2026 IZK
 */

if (true) {

    requireRank(30);

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_profile_customization (
            account_id BIGINT PRIMARY KEY,
            display_name VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(48) NOT NULL DEFAULT '',
            bio VARCHAR(240) NOT NULL DEFAULT '',
            theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
            theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
            banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
            title VARCHAR(48) NOT NULL DEFAULT '',
            badges TEXT NOT NULL,
            pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
            showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
            favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
            online_visible TINYINT NOT NULL DEFAULT 1,
            edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $issued=$_SESSION['muchoprofile_issued_token'] ?? null;
    unset($_SESSION['muchoprofile_issued_token']);

    if (is_array($issued)) {
        ?>
        <div class="card" style="border-color:#39d995;margin-bottom:16px">
            <b>New edit token for Account #<?=h($issued['id'])?></b>
            <p style="color:#8792a7">
                Copy it now. Only its SHA-256 hash is stored on the server.
            </p>
            <input
                style="width:100%"
                readonly
                value="<?=h($issued['token'])?>"
                onclick="this.select()"
            >
        </div>
        <?php
    }

    $q=trim((string)($_GET['q'] ?? ''));

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
                value="<?=h($q)?>"
                placeholder="Username / Account ID"
            >
            <button>Search</button>
        </form>
    </div>
    <?php

    $sql="
        SELECT
            a.account_id,
            a.username,
            a.email,
            a.role,

            m.display_name AS mp_display_name,
            m.status AS mp_status,
            m.bio AS mp_bio,
            m.theme_primary AS mp_primary,
            m.theme_secondary AS mp_secondary,
            m.banner AS mp_banner,
            m.title AS mp_title,
            m.badges AS mp_badges,
            m.pinned_levels AS mp_pinned,
            m.showcase AS mp_showcase,
            m.favorite_difficulty AS mp_favorite,
            m.online_visible AS mp_online,
            m.updated_at AS mp_updated

        FROM accounts a

        LEFT JOIN mucho_profile_customization m
            ON m.account_id=a.account_id
    ";

    $args=[];

    if ($q!=='') {
        $sql.="
            WHERE
                a.username LIKE :q
                OR a.account_id=:id
        ";

        $args=[
            'q'=>'%'.$q.'%',
            'id'=>ctype_digit($q) ? (int)$q : 0
        ];
    }

    $sql.=" ORDER BY a.account_id DESC LIMIT 100";

    $st=$db->prepare($sql);
    $st->execute($args);

    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    foreach($rows as $r) {

        $badgesRaw=(string)($r['mp_badges'] ?? '[]');
        $badgesDecoded=json_decode($badgesRaw,true);

        $badgesText=is_array($badgesDecoded)
            ? implode(', ',$badgesDecoded)
            : $badgesRaw;

        $primary=(string)(
            $r['mp_primary'] ?: '#42D9CF'
        );

        $secondary=(string)(
            $r['mp_secondary'] ?: '#806EFF'
        );

        $online=$r['mp_online'];

        if ($online===null) {
            $online=1;
        }

        ?>
        <div class="card" style="margin:14px 0">

            <div class="row" style="justify-content:space-between">
                <div>
                    <b style="font-size:18px">
                        #<?=h($r['account_id'])?>
                        <?=h($r['username'])?>
                    </b>

                    <span class="badge">
                        <?=h($r['role'])?>
                    </span>
                </div>

                <?php if(!empty($r['mp_updated'])): ?>
                    <small style="color:#8792a7">
                        Updated <?=h($r['mp_updated'])?>
                    </small>
                <?php endif ?>
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
                <input type="hidden" name="csrf" value="<?=csrf()?>">
                <input type="hidden" name="action" value="muchoprofile-save">
                <input type="hidden" name="return" value="muchoprofiles">
                <input type="hidden" name="id" value="<?=h($r['account_id'])?>">

                <label>
                    <small>Display name</small>
                    <input
                        style="width:100%"
                        name="display_name"
                        maxlength="32"
                        value="<?=h($r['mp_display_name'] ?? '')?>"
                    >
                </label>

                <label>
                    <small>Status</small>
                    <input
                        style="width:100%"
                        name="status"
                        maxlength="48"
                        value="<?=h($r['mp_status'] ?? '')?>"
                    >
                </label>

                <label>
                    <small>Title — server only</small>
                    <input
                        style="width:100%"
                        name="title"
                        maxlength="48"
                        placeholder="OWNER"
                        value="<?=h($r['mp_title'] ?? '')?>"
                    >
                </label>

                <label>
                    <small>Badges — server only</small>
                    <input
                        style="width:100%"
                        name="badges"
                        placeholder="OWNER, IZK"
                        value="<?=h($badgesText)?>"
                    >
                </label>

                <label>
                    <small>Primary color</small>
                    <input
                        style="width:100%"
                        name="theme_primary"
                        value="<?=h($primary)?>"
                        maxlength="7"
                    >
                </label>

                <label>
                    <small>Secondary color</small>
                    <input
                        style="width:100%"
                        name="theme_secondary"
                        value="<?=h($secondary)?>"
                        maxlength="7"
                    >
                </label>

                <label>
                    <small>Banner preset</small>
                    <input
                        style="width:100%"
                        name="banner"
                        maxlength="64"
                        value="<?=h($r['mp_banner'] ?: 'gradient_01')?>"
                    >
                </label>

                <label>
                    <small>Favorite difficulty</small>
                    <input
                        style="width:100%"
                        name="favorite_difficulty"
                        maxlength="32"
                        value="<?=h($r['mp_favorite'] ?? '')?>"
                    >
                </label>

                <label>
                    <small>Pinned levels — max 3 IDs</small>
                    <input
                        style="width:100%"
                        name="pinned_levels"
                        placeholder="12, 45, 99"
                        value="<?=h($r['mp_pinned'] ?? '')?>"
                    >
                </label>

                <label>
                    <small>Showcase</small>
                    <input
                        style="width:100%"
                        name="showcase"
                        maxlength="200"
                        value="<?=h(
                            $r['mp_showcase']
                            ?: 'stars,demons,creator_points'
                        )?>"
                    >
                </label>

                <label style="grid-column:1/-1">
                    <small>Bio</small>
                    <textarea
                        style="width:100%;min-height:90px"
                        name="bio"
                        maxlength="240"
                    ><?=h($r['mp_bio'] ?? '')?></textarea>
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
                        <?=$online ? 'checked' : ''?>
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
                <input type="hidden" name="csrf" value="<?=csrf()?>">
                <input type="hidden" name="action" value="muchoprofile-token">
                <input type="hidden" name="return" value="muchoprofiles">
                <input type="hidden" name="id" value="<?=h($r['account_id'])?>">

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

