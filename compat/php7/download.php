<?php
/**
 * Experimental PHP7 read-only public level download. Requires the real
 * MuchoCore "levels" and "profiles" schema; never increments counters.
 * Private/unlisted/banned levels are denied without session impersonation.
 */
declare(strict_types=1);

function mucho7_level_hash($data)
{
    $length = strlen($data);
    if ($length < 41) {
        return sha1($data . 'xI25fpAapCQg');
    }
    $step = (int)floor($length / 40);
    $sample = '';
    for ($index = 0; $index < 40; $index++) {
        $sample .= $data[$index * $step];
    }
    return sha1($sample . 'xI25fpAapCQg');
}

function mucho7_copy_password($password)
{
    if ($password === '' || $password === '0') {
        return '0';
    }
    $key = '26364';
    $out = '';
    for ($index = 0; $index < strlen($password); $index++) {
        $out .= chr(ord($password[$index]) ^ ord($key[$index % strlen($key)]));
    }
    return base64_encode($out);
}

function mucho7_encode_download($level, $gameVersion, $binaryVersion)
{
    $data = (string)$level['level_data'];
    if ($gameVersion > 18 && substr($data, 0, 3) === 'kS1') {
        $packed = gzcompress($data);
        if ($packed === false) {
            return '-1';
        }
        $data = strtr(base64_encode($packed), '/+', '_-');
    }
    $difficulty = (int)$level['difficulty'];
    if ($difficulty >= 1 && $difficulty <= 6) {
        $difficulty *= 10;
    }
    $plainPassword = (string)$level['copy_password'];
    $password = $gameVersion > 19
        ? mucho7_copy_password($plainPassword) : $plainPassword;
    $description = (string)$level['description'];
    if ($gameVersion >= 20) {
        $description = strtr(base64_encode($description), '+/', '-_');
    }
    $dateFormat = 'd-m-Y G-i';
    $created = new DateTimeImmutable((string)$level['created_at']);
    $updated = new DateTimeImmutable((string)$level['updated_at']);
    $fields = array(
        1, (int)$level['level_id'],
        2, mucho7_field($level['name'], 64),
        3, mucho7_field($description, 8192),
        4, $data,
        5, (int)$level['level_version'],
        6, (int)$level['user_id'],
        8, 10,
        9, $difficulty,
        10, (int)$level['downloads'],
        11, 1,
        12, (int)$level['audio_track'],
        13, (int)$level['game_version'],
        14, (int)$level['likes'],
        17, (int)$level['demon'],
        43, (int)$level['demon_difficulty'],
        25, (int)$level['auto_level'],
        18, (int)$level['stars'],
        19, (int)$level['featured'],
        42, (int)$level['epic'],
        45, (int)$level['object_count'],
        15, (int)$level['length'],
        30, (int)$level['original_level_id'],
        31, (int)$level['two_player'],
        28, $created->format($dateFormat),
        29, $updated->format($dateFormat),
        35, (int)$level['song_id'],
        36, (string)$level['extra_string'],
        37, (int)$level['coins'],
        38, (int)$level['coins_verified'],
        39, (int)$level['requested_stars'],
        46, (int)$level['wt'],
        47, (int)$level['wt2'],
        48, isset($level['settings_string']) ? (string)$level['settings_string'] : '',
        40, (int)$level['ldm'],
        27, $password,
        52, isset($level['song_ids']) ? (string)$level['song_ids'] : '',
        53, isset($level['sfx_ids']) ? (string)$level['sfx_ids'] : '',
        57, (int)$level['ts']
    );
    if ($gameVersion >= 22 && $binaryVersion >= 51) {
        $fields[] = 62;
        $fields[] = mucho7_epoch($level['created_at']);
        $fields[] = 63;
        $fields[] = mucho7_epoch($level['updated_at']);
    }

    $metadata = (string)$level['user_id']
        . (string)$level['stars']
        . (string)$level['demon']
        . (string)$level['level_id']
        . (string)$level['coins']
        . (string)$level['featured']
        . $plainPassword . ',0';
    return implode(':', $fields)
        . '#' . mucho7_level_hash($data)
        . '#' . sha1($metadata . 'xI25fpAapCQg');
}

function mucho7_download(PDO $pdo, $input)
{
    $rawId = isset($input['levelID']) ? (string)$input['levelID'] : '';
    if (!preg_match('/^[1-9][0-9]{0,13}$/D', $rawId)) {
        return '-1';
    }
    $gameVersion = mucho7_number(
        isset($input['gameVersion']) ? $input['gameVersion'] : 22,
        22, 22
    );
    if ($gameVersion < 20) {
        // Legacy download formats have not been certified in this adapter.
        return '-1';
    }
    $binaryVersion = mucho7_number(
        isset($input['binaryVersion']) ? $input['binaryVersion'] : 0,
        0, 1000
    );
    if (isset($input['extras']) && (string)$input['extras'] === '1') {
        // Extra-only fields are not yet implemented.
        return '-1';
    }
    $query = $pdo->prepare(
        'SELECT l.*,COALESCE(p.user_id,l.account_id) AS user_id
         FROM levels l LEFT JOIN profiles p ON p.account_id=l.account_id
         WHERE l.level_id=:id AND l.is_deleted=0 AND l.is_unlisted=0
           AND l.unlisted2=0 AND length(l.level_data)<=1048576
         LIMIT 1'
    );
    $query->execute(array(':id' => (int)$rawId));
    $level = $query->fetch(PDO::FETCH_ASSOC);
    if (!$level) {
        return '-1';
    }
    return mucho7_encode_download($level, $gameVersion, $binaryVersion);
}
