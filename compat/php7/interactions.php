<?php
/** Local comments, profile posts, history and authenticated votes on PHP 7.0. */
declare(strict_types=1);

function mucho7_comment_text($value, $gameVersion)
{
    if (!is_string($value) || strlen($value) > 4096) { return false; }
    if ($gameVersion >= 20) {
        if (!preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D', $value)) { return false; }
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $value = base64_decode($value, true);
    }
    if (!is_string($value) || preg_match('//u', $value) !== 1
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        return false;
    }
    $value = trim($value);
    $characters = preg_match_all('/./us', $value, $unused);
    return $value !== '' && $characters !== false && $characters <= 300
        ? $value : false;
}

function mucho7_comment_wire($row, $gameVersion, $binaryVersion, $history = false)
{
    $text = $gameVersion >= 20
        ? strtr(base64_encode($row['content']), '+/', '-_')
        : mucho7_field($row['content'], 4096);
    $fields = array(2, $text, 3, (int)$row['user_id'], 4, (int)$row['likes'],
        5, 0, 7, (int)$row['is_spam'], 9,
        gmdate('d/m/Y G.i', mucho7_epoch($row['created_at'])), 6, (int)$row['id']);
    if ((int)$row['kind'] === 1) {
        $fields[] = 10; $fields[] = (int)$row['percent'];
    }
    if ($gameVersion >= 22 && $binaryVersion >= 51) {
        $fields[] = 15; $fields[] = mucho7_epoch($row['created_at']);
    }
    if ($history) { $fields = array_merge(array(1, (int)$row['level_id']), $fields); }
    if ((int)$row['kind'] === 1 && ($gameVersion >= 22 || $binaryVersion > 31)) {
        $badge = in_array($row['role'], array('owner', 'elder_moderator'), true)
            ? 2 : ($row['role'] === 'moderator' ? 1 : 0);
        $fields[] = 11; $fields[] = $badge;
        if ($badge > 0) { $fields[] = 12; $fields[] = '255,255,255'; }
        return implode('~', $fields) . ':' . implode('~', array(
            1, mucho7_username($row['username']), 7, 1, 9, (int)$row['icon_id'],
            10, (int)$row['color1'], 11, (int)$row['color2'],
            14, (int)$row['icon_type'], 15, (int)$row['glow'],
            16, (int)$row['account_id']));
    }
    return implode('~', $fields);
}

function mucho7_comments_read(PDO $pdo, $data, $kind, $history = false, $options = array())
{
    $id = mucho7_number(isset($data[$kind === 1 ? 'levelID' : 'accountID'])
        ? $data[$kind === 1 ? 'levelID' : 'accountID'] : 0, 0, 2147483647);
    $where = 'c.kind=:kind AND a.is_active=1 AND a.is_banned=0';
    $params = array(':kind'=>$kind);
    if ($kind === 1) {
        $where .= ' AND l.is_deleted=0 AND l.is_unlisted=0 AND l.unlisted2=0';
    }
    if ($history) {
        $id = mucho7_number(isset($data['userID']) ? $data['userID'] : 0, 0, 2147483647);
        if(function_exists('mucho7_details')) {
            $target=$pdo->prepare('SELECT account_id FROM profiles WHERE user_id=?');
            $target->execute(array($id)); $targetId=(int)$target->fetchColumn();
            $details=mucho7_details($pdo,$targetId);
            $state=isset($details['comment_history_state'])?(int)$details['comment_history_state']:0;
            if($state>0) {
                $viewer=!empty($options['enable_accounts'])?mucho7_identity($pdo,$data):0;
                if($viewer!==$targetId && ($viewer<=0 || $state===2 || empty($options['enable_social'])
                    || !mucho7_social_friend($pdo,$viewer,$targetId)
                    || mucho7_social_blocked($pdo,$viewer,$targetId))){return '-1';}
            }
        }
        $where .= ' AND p.user_id=:id';
    } else {
        $where .= $kind === 1 ? ' AND c.level_id=:id' : ' AND c.account_id=:id';
    }
    if ($id <= 0) { return '-1'; }
    $params[':id'] = $id;
    $limit = max(1, mucho7_number(isset($data['count']) ? $data['count'] : 10, 10, 100));
    $offset = mucho7_number(isset($data['page']) ? $data['page'] : 0, 0, 1000) * $limit;
    $from = ' FROM php7_comments c JOIN accounts a ON a.account_id=c.account_id
        JOIN profiles p ON p.account_id=c.account_id
        LEFT JOIN roles r ON r.id=a.role_id
        LEFT JOIN levels l ON l.level_id=c.level_id WHERE ' . $where;
    $count = $pdo->prepare('SELECT COUNT(*)' . $from);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    if ($total === 0) { return '-2'; }
    $order = !empty($data['mode']) ? 'c.likes DESC,c.id DESC' : 'c.id DESC';
    $query = $pdo->prepare('SELECT c.*,p.user_id,p.icon_id,p.icon_type,p.color1,
        p.color2,p.glow,a.username,COALESCE(r.code,\'user\') AS role' . $from
        . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if(function_exists('mucho7_enrich_users')){$rows=mucho7_enrich_users($pdo,$rows);}
    if (!$rows) { return '-2'; }
    $gv = mucho7_number(isset($data['gameVersion']) ? $data['gameVersion'] : 22, 22, 22);
    $bv = mucho7_number(isset($data['binaryVersion']) ? $data['binaryVersion'] : 0, 0, 1000);
    $entries = array(); $users = array();
    foreach ($rows as $row) {
        $entries[] = mucho7_comment_wire($row, $gv, $bv, $history);
        $users[$row['user_id']] = $row['user_id'] . ':'
            . mucho7_username($row['username']) . ':' . $row['account_id'];
    }
    return implode('|', $entries)
        . ($kind === 1 && $bv < 32 ? '#' . implode('|', $users) : '')
        . '#' . $total . ':' . $offset . ':' . $limit;
}

function mucho7_comments_write(PDO $pdo, $data, $kind, $delete = false)
{
    $aid = mucho7_identity($pdo, $data);
    if ($aid <= 0) { return '-1'; }
    if ($delete) {
        $id = mucho7_number(isset($data['commentID']) ? $data['commentID'] : 0, 0, 2147483647);
        $query = $pdo->prepare('DELETE FROM php7_comments
            WHERE id=:id AND kind=:kind AND account_id=:aid');
        $query->execute(array(':id'=>$id, ':kind'=>$kind, ':aid'=>$aid));
        return $query->rowCount() === 1 ? '1' : '-1';
    }
    $gv = mucho7_number(isset($data['gameVersion']) ? $data['gameVersion'] : 22, 22, 22);
    $text = mucho7_comment_text(isset($data['comment']) ? $data['comment'] : '', $gv);
    if ($text === false) { return '-1'; }
    // Do not silently store unsupported moderation commands as public comments.
    if ($text[0] === '!') { return '-1'; }
    $lid = $kind === 1 ? mucho7_number(isset($data['levelID']) ? $data['levelID'] : 0, 0, 2147483647) : 0;
    $pdo->beginTransaction();
    try {
        // Serialize each author's cooldown check, including concurrent requests.
        $lock = $pdo->prepare('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE');
        $lock->execute(array($aid));
        if ($kind === 1) {
            $level = $pdo->prepare('SELECT 1 FROM levels WHERE level_id=?
                AND is_deleted=0 AND is_unlisted=0 AND unlisted2=0');
            $level->execute(array($lid));
            if (!$level->fetchColumn()) { $pdo->rollBack(); return '-1'; }
        }
        $recent = $pdo->prepare('SELECT COUNT(*) FROM php7_comments
            WHERE account_id=? AND created_at>?');
        $recent->execute(array($aid, gmdate('Y-m-d H:i:s', time()-10)));
        if ((int)$recent->fetchColumn() > 0) { $pdo->rollBack(); return '-1'; }
        $insert = $pdo->prepare('INSERT INTO php7_comments
            (kind,level_id,account_id,content,percent) VALUES(?,?,?,?,?)');
        $insert->execute(array($kind, $lid, $aid, $text,
            $kind === 1 ? mucho7_number(isset($data['percent']) ? $data['percent'] : 0, 0, 100) : 0));
        $id = (string)$pdo->lastInsertId();
        $pdo->commit();
        return $kind === 1 && $gv >= 21 ? $id : '1';
    } catch (Exception $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function mucho7_vote(PDO $pdo, $data)
{
    $aid = mucho7_identity($pdo, $data);
    $id = mucho7_number(isset($data['itemID']) ? $data['itemID'] :
        (isset($data['levelID']) ? $data['levelID'] : 0), 0, 2147483647);
    $type = mucho7_number(isset($data['type']) ? $data['type'] : 1, 1, 99);
    $value = isset($data['like']) && (string)$data['like'] === '0' ? -1 : 1;
    if ($aid <= 0 || $id <= 0 || !in_array($type, array(1,2,3), true)) { return '-1'; }
    $table = $type === 1 ? 'levels' : 'php7_comments';
    $pk = $type === 1 ? 'level_id' : 'id';
    $condition = $type === 1 ? 'is_deleted=0 AND is_unlisted=0 AND unlisted2=0' : 'kind=' . ($type-1);
    if ($type === 2) {
        $condition .= ' AND EXISTS(SELECT 1 FROM levels l WHERE l.level_id=php7_comments.level_id
            AND l.is_deleted=0 AND l.is_unlisted=0 AND l.unlisted2=0)';
    }
    $pdo->beginTransaction();
    try {
        // Lock the target before reading its votes; duplicates cannot inflate it.
        $target = $pdo->prepare('SELECT likes FROM ' . $table . ' WHERE '
            . $pk . '=? AND ' . $condition . ' FOR UPDATE');
        $target->execute(array($id));
        if ($target->fetchColumn() === false) { $pdo->rollBack(); return '-1'; }
        $old = $pdo->prepare('SELECT value FROM php7_votes WHERE type=? AND item_id=? AND account_id=?');
        $old->execute(array($type, $id, $aid));
        $previous = $old->fetchColumn();
        $delta = $value - ($previous === false ? 0 : (int)$previous);
        $upsert = $pdo->prepare('INSERT INTO php7_votes(type,item_id,account_id,value)
            VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $upsert->execute(array($type, $id, $aid, $value));
        if ($delta !== 0) {
            $update = $pdo->prepare('UPDATE ' . $table . ' SET likes=likes+? WHERE ' . $pk . '=?');
            $update->execute(array($delta, $id));
        }
        $pdo->commit();
        return '1';
    } catch (Exception $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}
