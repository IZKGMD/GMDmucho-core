<?php
/**
 * Experimental PHP 7.0+ READ-ONLY native MuchoCore endpoint runtime.
 * Uses its own PDO connection and the existing MuchoCore SQL schema.
 *
 * Not a full core: no authentication, registration, level uploads, comments,
 * social, Cloud Save, moderation, or admin. Unsupported routes fail closed.
 *
 * This code MUST NOT include modern src/ classes or Composer dependencies.
 */
declare(strict_types=1);

function mucho7_path($uri)
{
    $path = parse_url((string)$uri, PHP_URL_PATH);
    if (!is_string($path)) {
        return '/';
    }
    $path = strtolower(preg_replace('~/+~', '/', $path));
    $path = '/' . ltrim($path, '/');
    while (preg_match('~^/(database|accounts|api|a)(/|$)~', $path)) {
        $path = preg_replace('~^/(database|accounts|api|a)(?=/|$)~', '', $path, 1);
        $path = '/' . ltrim($path, '/');
    }
    return rtrim(preg_replace('~\.php$~', '', $path), '/') ?: '/';
}

function mucho7_number($value, $default, $max)
{
    if (!is_scalar($value) || !preg_match('/^\d{1,10}$/D', (string)$value)) {
        return $default;
    }
    return min($max, (int)$value);
}

function mucho7_field($value, $limit)
{
    if (!is_scalar($value)) {
        return '';
    }
    $value = str_replace(array("\0", "\r", "\n", ':', '|', '#', '~'), '', (string)$value);
    return substr($value, 0, $limit);
}

function mucho7_username($value)
{
    $name = trim((string)$value);
    $name = preg_replace('/[\x00-\x1F\x7F:|#~]/', '', $name);
    $name = substr($name, 0, 20);
    return $name !== '' ? $name : 'Player';
}

function mucho7_epoch($value)
{
    if (!is_string($value) || trim($value) === '') {
        return 0;
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return max(0, $date->getTimestamp());
    } catch (Exception $e) {
        return 0;
    }
}

/** Adapted byte-for-byte field order from GdLevelListEncoder for GD 2.2/2.209. */
function mucho7_encode_levels($levels, $total, $offset, $gameVersion, $binaryVersion)
{
    if (!$levels || $total <= 0) {
        return '-2';
    }
    $parts = array();
    $userStrings = array();
    $songs = array();
    $usersSeen = array();
    $songsSeen = array();
    $hashData = '';
    foreach ($levels as $level) {
        $id = (string)$level['level_id'];
        $userId = (string)$level['user_id'];
        $accountId = (string)$level['account_id'];
        $difficulty = (int)$level['difficulty'];
        if ($difficulty >= 1 && $difficulty <= 6) {
            $difficulty *= 10;
        }
        $description = (string)$level['description'];
        if ($gameVersion >= 20) {
            $description = strtr(base64_encode($description), '+/', '-_');
        } else {
            $description = strtr($description, '+/', '-_');
        }
        $fields = array(
            1, $id, 2, mucho7_field($level['name'], 65535),
            5, (int)$level['level_version'], 6, $userId, 8, 10,
            9, $difficulty, 10, (int)$level['downloads'],
            12, (int)$level['audio_track'],
            13, (int)$level['game_version'], 14, (int)$level['likes'],
            17, (int)$level['demon'], 43, (int)$level['demon_difficulty'],
            25, (int)$level['auto_level'], 18, (int)$level['stars'],
            19, (int)$level['featured'], 42, (int)$level['epic'],
            45, (int)$level['object_count'],
            3, mucho7_field($description, 65535),
            15, (int)$level['length'], 30, (int)$level['original_level_id'],
            31, (int)$level['two_player'], 37, (int)$level['coins'],
            38, (int)$level['coins_verified'], 39, (int)$level['requested_stars'],
            46, 1, 47, 2, 40, (int)$level['ldm'],
            35, (int)$level['song_id']
        );
        if ($gameVersion >= 22 && $binaryVersion >= 51) {
            $fields[] = 62;
            $fields[] = mucho7_epoch($level['created_at']);
            $fields[] = 63;
            $fields[] = mucho7_epoch($level['updated_at']);
        }
        $parts[] = implode(':', $fields);
        if (!isset($usersSeen[$userId])) {
            $usersSeen[$userId] = true;
            $userStrings[] = implode(':', array(
                $userId, mucho7_username($level['username']), $accountId
            ));
        }
        $songId = (int)$level['song_protocol_id'];
        if ($gameVersion > 18 && $songId > 0 && !isset($songsSeen[$songId])) {
            $songsSeen[$songId] = true;
            $songs[] = implode('~|~', array(
                1, $songId, 2, mucho7_field($level['song_name'], 65535),
                3, (int)$level['song_author_id'],
                4, mucho7_field($level['song_author_name'], 65535),
                5, round((float)$level['song_size'], 2),
                6, (string)$level['song_youtube_video_id'],
                10, (string)$level['song_download_url'],
                7, (string)$level['song_youtube_channel_id'], 8, 1
            ));
        }
        $hashData .= $id[0] . $id[strlen($id) - 1]
            . max(0, (int)$level['stars']) . (int)$level['coins'];
    }
    return implode('|', $parts)
        . '#' . implode('|', $userStrings)
        . '#' . implode('~:~', $songs)
        . '#' . $total . ':' . $offset . ':10'
        . '#' . sha1($hashData . 'xI25fpAapCQg');
}

/**
 * Only the safe public search types are implemented. Types that depend on
 * authenticated identity, moderator privileges, friends or per-user filters
 * are rejected rather than leaking records.
 */
function mucho7_search(PDO $pdo, $data)
{
    $type = mucho7_number(isset($data['type']) ? $data['type'] : 0, 0, 999);
    if (!in_array($type, array(0, 1, 2, 6, 11, 15, 16, 17), true)) {
        return '-1';
    }
    foreach (array(
        'accountID', 'followed', 'completedLevels', 'gauntlet',
        'uncompleted', 'onlyCompleted'
    ) as $personal) {
        if (isset($data[$personal]) && (string)$data[$personal] !== ''
            && (string)$data[$personal] !== '0') {
            return '-1';
        }
    }
    $search = isset($data['str']) && is_string($data['str'])
        ? trim($data['str']) : '';
    if (strlen($search) > 100) {
        return '-1';
    }
    $page = mucho7_number(isset($data['page']) ? $data['page'] : 0, 0, 1000);
    $gameVersion = mucho7_number(isset($data['gameVersion']) ? $data['gameVersion'] : 22, 22, 22);
    if ($gameVersion < 19) {
        // Old client hash/description variants are not certified yet.
        return '-1';
    }
    $binary = mucho7_number(isset($data['binaryVersion']) ? $data['binaryVersion'] : 0, 0, 1000);
    $offset = $page * 10;
    $where = array('l.is_deleted=0', 'l.is_unlisted=0', 'l.game_version<=:gv');
    $params = array(':gv' => $gameVersion);
    if ($type === 0 || $type === 15) {
        if ($search !== '') {
            if (ctype_digit($search)) {
                $where[] = 'l.level_id=:id';
                $params[':id'] = (int)$search;
            } else {
                $where[] = 'l.name LIKE :search';
                $params[':search'] = '%' . $search . '%';
            }
        }
        $order = 'l.likes DESC, l.level_id DESC';
    } elseif ($type === 1) {
        $order = 'l.downloads DESC, l.level_id DESC';
    } elseif ($type === 6 || $type === 17) {
        $where[] = $gameVersion > 21
            ? '(l.featured>0 OR l.epic>0)' : 'l.featured>0';
        $order = 'l.updated_at DESC, l.level_id DESC';
    } elseif ($type === 16) {
        $where[] = 'l.epic>0';
        $order = 'l.updated_at DESC, l.level_id DESC';
    } else {
        if ($type === 11) {
            $where[] = 'l.stars>0';
            $order = 'l.updated_at DESC, l.level_id DESC';
        } else {
            $order = 'l.likes DESC, l.level_id DESC';
        }
    }
    if (isset($data['diff']) && (string)$data['diff'] !== '-') {
        $diff = (string)$data['diff'];
        if ($diff === '-2') {
            $where[] = 'l.demon=1';
        } elseif ($diff === '-1') {
            $where[] = 'l.difficulty=0 AND l.demon=0';
        } elseif ($diff === '-3') {
            $where[] = 'l.auto_level=1';
        } elseif (preg_match('/^\d{1,2}$/D', $diff)) {
            $difficulty = (int)$diff;
            if ($difficulty >= 10 && $difficulty % 10 === 0) {
                $difficulty = (int)($difficulty / 10);
            }
            $where[] = 'l.difficulty=:difficulty';
            $params[':difficulty'] = $difficulty;
        } else {
            return '-1';
        }
    }
    foreach (array('coins' => 'l.coins_verified=1 AND l.coins<>0',
                   'original' => 'l.original_level_id=0',
                   'twoPlayer' => 'l.two_player=1',
                   'star' => 'l.stars>0', 'noStar' => 'l.stars=0',
                   'featured' => 'l.featured>0') as $name => $expr) {
        if (isset($data[$name]) && (string)$data[$name] === '1') {
            $where[] = $expr;
        }
    }
    if (isset($data['song']) && (string)$data['song'] !== '0'
        && (string)$data['song'] !== '') {
        if (!preg_match('/^\d{1,8}$/D', (string)$data['song'])) {
            return '-1';
        }
        $song = (int)$data['song'];
        if (isset($data['customSong']) && (string)$data['customSong'] === '1') {
            $where[] = 'l.song_id=:song';
            $params[':song'] = $song;
        } else {
            $where[] = 'l.song_id=0 AND l.audio_track=:song';
            $params[':song'] = max(0, $song - 1);
        }
    }
    foreach (array('len','epic','mythic','legendary','demonFilter') as $unimplemented) {
        if (isset($data[$unimplemented]) && (string)$data[$unimplemented] !== ''
            && (string)$data[$unimplemented] !== '0'
            && (string)$data[$unimplemented] !== '-') {
            return '-1';
        }
    }
    $condition = implode(' AND ', $where);
    $counter = $pdo->prepare('SELECT COUNT(*) FROM levels l WHERE ' . $condition);
    $counter->execute($params);
    $total = (int)$counter->fetchColumn();
    if ($total === 0) {
        return '-2';
    }
    $sql = 'SELECT l.level_id,l.account_id,l.name,l.description,l.level_version,
           l.game_version,l.length,l.difficulty,l.downloads,l.likes,
           l.audio_track,l.demon,l.demon_difficulty,l.auto_level,l.stars,
           l.featured,l.epic,l.object_count,l.original_level_id,l.two_player,
           l.coins,l.coins_verified,l.requested_stars,l.ldm,l.song_id,
           l.created_at,l.updated_at,
           COALESCE(a.username, \'Player\') AS username,
           COALESCE(p.user_id, l.account_id) AS user_id,
           COALESCE(s.id,0) AS song_protocol_id,
           COALESCE(s.name,\'\') AS song_name,
           COALESCE(s.author_id,0) AS song_author_id,
           COALESCE(s.author_name,\'\') AS song_author_name,
           COALESCE(s.size,0) AS song_size,
           COALESCE(s.download_url,\'\') AS song_download_url,
           COALESCE(s.youtube_video_id,\'\') AS song_youtube_video_id,
           COALESCE(s.youtube_channel_id,\'\') AS song_youtube_channel_id
           FROM levels l
           LEFT JOIN accounts a ON a.account_id=l.account_id
           LEFT JOIN profiles p ON p.account_id=l.account_id
           LEFT JOIN songs s ON s.id=l.song_id AND s.is_verified=1
           WHERE ' . $condition . ' ORDER BY ' . $order .
           ' LIMIT 10 OFFSET ' . $offset;
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return mucho7_encode_levels(
        $query->fetchAll(PDO::FETCH_ASSOC), $total, $offset, $gameVersion, $binary
    );
}

function mucho7_dispatch($pdo, $uri, $method, $post)
{
    $path = mucho7_path($uri);
    if (($path === '/health' || $path === '/checkifserveronline')
        && strtoupper($method) === 'GET') {
        return '1';
    }
    if (strtoupper($method) !== 'POST') {
        return '-1';
    }
    if (in_array($path, array(
        '/getgjlevels', '/getgjlevels19', '/getgjlevels20',
        '/getgjlevels21', '/getgjlevels22'
    ), true)) {
        return $pdo instanceof PDO ? mucho7_search($pdo, $post) : '-1';
    }
    return '-1';
}
