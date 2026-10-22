<?php
/**
 * Native PHP 7.0 GD friend requests and friendships.
 *
 * Isolated storage: never reuses/migrates the PHP8 or Cvolton social tables.
 * Only authenticated users can read or change relationship state.
 */
declare(strict_types=1);

function mucho7_social_schema()
{
    return array(
        "CREATE TABLE IF NOT EXISTS php7_friend_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sender_id BIGINT UNSIGNED NOT NULL,
            receiver_id BIGINT UNSIGNED NOT NULL,
            comment VARCHAR(600) NOT NULL DEFAULT '',
            is_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_native_request(sender_id,receiver_id),
            KEY idx_native_inbox(receiver_id,is_new,id),
            KEY idx_native_sent(sender_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS php7_friendships (
            account_low BIGINT UNSIGNED NOT NULL,
            account_high BIGINT UNSIGNED NOT NULL,
            low_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
            high_new TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(account_low,account_high),
            KEY idx_native_friends_high(account_high)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function mucho7_upgrade_social(PDO $pdo)
{
    // Installer / explicit SQL migration only; never run schema DDL on requests.
    foreach (mucho7_social_schema() as $statement) { $pdo->exec($statement); }
}

function mucho7_social_id($data, $key)
{
    return mucho7_number(isset($data[$key]) ? $data[$key] : 0, 0, 2147483647);
}

function mucho7_social_comment($raw)
{
    if (!is_string($raw) || strlen($raw) > 1024 ||
        !preg_match('~^[A-Za-z0-9+/_-]*={0,2}$~D', $raw)) {
        return false;
    }
    $encoded = strtr($raw, '-_', '+/');
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $text = base64_decode($encoded, true);
    if (!is_string($text) || preg_match('//u', $text) !== 1 ||
        preg_match('/[\x00-\x1f\x7f]/', $text)) { return false; }
    $length = preg_match_all('/./us', $text, $unused);
    return $length !== false && $length <= 150 ? $text : false;
}

function mucho7_social_user_wire($row)
{
    return implode(':', array(
        1, mucho7_username($row['username']),
        2, (int)$row['user_id'], 9, (int)$row['icon_id'],
        10, (int)$row['color1'], 11, (int)$row['color2'],
        14, (int)$row['icon_type'], 15, 0,
        16, (int)$row['account_id']
    ));
}

function mucho7_social_request_wire($row, $binaryVersion)
{
    $comment = strtr(base64_encode($row['comment']), '+/', '-_');
    $time = gmdate('d/m/Y G.i', mucho7_epoch($row['created_at']));
    return mucho7_social_user_wire($row) . ':32:' . (int)$row['id']
        . ':35:' . $comment . ':41:' . ((int)$row['is_new'] === 1 ? 1 : 0)
        . ':37:' . $time;
}

function mucho7_social_requests(PDO $pdo, $data, $aid)
{
    $sent = mucho7_number(isset($data['getSent']) ? $data['getSent'] : 0, 0, 2);
    if ($sent === 1) {
        $where = 'r.sender_id=:aid';
        $other = 'r.receiver_id';
    } else {
        $where = 'r.receiver_id=:aid AND r.is_new' . ($sent === 2 ? '=2' : '<>2');
        $other = 'r.sender_id';
    }
    $page = mucho7_number(isset($data['page']) ? $data['page'] : 0, 0, 1000);
    $offset = $page * 10;
    $from = ' FROM php7_friend_requests r JOIN accounts a ON a.account_id='
        . $other . ' JOIN profiles p ON p.account_id=a.account_id'
        . ' WHERE ' . $where . ' AND a.is_active=1 AND a.is_banned=0';
    $count = $pdo->prepare('SELECT COUNT(*)' . $from);
    $count->execute(array(':aid'=>$aid));
    $total = (int)$count->fetchColumn();
    if ($total <= 0) { return '-2'; }
    $query = $pdo->prepare('SELECT r.id,r.comment,r.is_new,r.created_at,'
        . ' a.account_id,a.username,p.user_id,p.icon_id,p.icon_type,p.color1,p.color2'
        . $from . ' ORDER BY r.id DESC LIMIT 10 OFFSET ' . $offset);
    $query->execute(array(':aid'=>$aid));
    $result = array();
    $bv = mucho7_number(isset($data['binaryVersion']) ? $data['binaryVersion'] : 0, 0, 1000);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[] = mucho7_social_request_wire($row, $bv);
    }
    return $result ? implode('|', $result) . '#' . $total . ':' . $offset . ':10' : '-2';
}

function mucho7_social_send(PDO $pdo, $data, $aid)
{
    $to = mucho7_social_id($data, 'toAccountID');
    $comment = mucho7_social_comment(isset($data['comment']) ? $data['comment'] : '');
    if ($to <= 0 || $to === $aid || $comment === false) { return '-1'; }
    $low = min($aid, $to); $high = max($aid, $to);
    $pdo->beginTransaction();
    try {
        // Lock in stable order so reversed concurrent requests cannot race.
        $lock = $pdo->prepare('SELECT account_id FROM accounts WHERE account_id IN (?,?)'
            . ' AND is_active=1 AND is_banned=0 ORDER BY account_id FOR UPDATE');
        $lock->execute(array($low,$high));
        if (count($lock->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
            $pdo->rollBack(); return '-1';
        }
        $friend = $pdo->prepare('SELECT 1 FROM php7_friendships'
            . ' WHERE account_low=? AND account_high=? LIMIT 1');
        $friend->execute(array($low,$high));
        if ($friend->fetchColumn()) { $pdo->rollBack(); return '-1'; }
        $pending = $pdo->prepare('SELECT 1 FROM php7_friend_requests'
            . ' WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)'
            . ' LIMIT 1');
        $pending->execute(array($aid,$to,$to,$aid));
        if ($pending->fetchColumn()) { $pdo->rollBack(); return '-1'; }
        $recent = $pdo->prepare('SELECT COUNT(*) FROM php7_friend_requests'
            . ' WHERE sender_id=? AND created_at > ?');
        $recent->execute(array($aid,gmdate('Y-m-d H:i:s',time()-600)));
        if ((int)$recent->fetchColumn() >= 10) { $pdo->rollBack(); return '-1'; }
        $insert = $pdo->prepare('INSERT INTO php7_friend_requests'
            . '(sender_id,receiver_id,comment) VALUES(?,?,?)');
        $insert->execute(array($aid,$to,$comment));
        $pdo->commit(); return '1';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function mucho7_social_accept(PDO $pdo, $data, $aid)
{
    $rid = mucho7_social_id($data, 'requestID');
    if ($rid <= 0) { return '-1'; }
    $pdo->beginTransaction();
    try {
        $get = $pdo->prepare('SELECT sender_id,receiver_id FROM php7_friend_requests'
            . ' WHERE id=? FOR UPDATE');
        $get->execute(array($rid));
        $request = $get->fetch(PDO::FETCH_ASSOC);
        if (!$request || (int)$request['receiver_id'] !== $aid) {
            $pdo->rollBack(); return '-1';
        }
        $sender = (int)$request['sender_id'];
        $active = $pdo->prepare('SELECT COUNT(*) FROM accounts'
            . ' WHERE account_id IN (?,?) AND is_active=1 AND is_banned=0');
        $active->execute(array($sender,$aid));
        if ((int)$active->fetchColumn() !== 2) { $pdo->rollBack(); return '-1'; }
        $low = min($sender,$aid); $high = max($sender,$aid);
        $insert = $pdo->prepare('INSERT IGNORE INTO php7_friendships'
            . '(account_low,account_high) VALUES(?,?)');
        $insert->execute(array($low,$high));
        $remove = $pdo->prepare('DELETE FROM php7_friend_requests'
            . ' WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)');
        $remove->execute(array($sender,$aid,$aid,$sender));
        $pdo->commit(); return '1';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function mucho7_social_delete_request(PDO $pdo, $data, $aid)
{
    $target = mucho7_social_id($data, 'targetAccountID');
    if ($target <= 0 || $target === $aid) { return '-1'; }
    $isSender = isset($data['isSender']) && (string)$data['isSender'] === '1';
    $sql = $isSender
        ? 'DELETE FROM php7_friend_requests WHERE sender_id=? AND receiver_id=?'
        : 'UPDATE php7_friend_requests SET is_new=2 WHERE receiver_id=? AND sender_id=?';
    $query = $pdo->prepare($sql);
    $query->execute(array($aid,$target));
    return $query->rowCount() > 0 ? '1' : '-1';
}

function mucho7_social_friends(PDO $pdo, $data, $aid)
{
    if (!isset($data['type']) || (string)$data['type'] !== '0') {
        // Blocks/follows/mutes need their own private permission semantics.
        return '-2';
    }
    $query = $pdo->prepare('SELECT a.account_id,a.username,p.user_id,'
        . 'p.icon_id,p.icon_type,p.color1,p.color2,'
        . 'CASE WHEN f.account_low=? THEN f.high_new ELSE f.low_new END AS is_new'
        . ' FROM php7_friendships f'
        . ' JOIN accounts a ON a.account_id=CASE WHEN f.account_low=?'
        . ' THEN f.account_high ELSE f.account_low END'
        . ' JOIN profiles p ON p.account_id=a.account_id'
        . ' WHERE (f.account_low=? OR f.account_high=?)'
        . ' AND a.is_active=1 AND a.is_banned=0 ORDER BY a.username LIMIT 1000');
    $query->execute(array($aid,$aid,$aid,$aid));
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { return '-2'; }
    $entries = array();
    foreach ($rows as $row) {
        $entries[] = mucho7_social_user_wire($row)
            . ':18:0:41:' . (int)$row['is_new'];
    }
    $clear = $pdo->prepare('UPDATE php7_friendships'
        . ' SET low_new=CASE WHEN account_low=? THEN 0 ELSE low_new END,'
        . ' high_new=CASE WHEN account_high=? THEN 0 ELSE high_new END'
        . ' WHERE account_low=? OR account_high=?');
    $clear->execute(array($aid,$aid,$aid,$aid));
    return implode('|', $entries);
}

function mucho7_social_remove_friend(PDO $pdo, $data, $aid)
{
    $target = mucho7_social_id($data,'targetAccountID');
    if ($target <= 0 || $target === $aid) { return '-1'; }
    $query = $pdo->prepare('DELETE FROM php7_friendships'
        . ' WHERE account_low=? AND account_high=?');
    $query->execute(array(min($aid,$target),max($aid,$target)));
    return $query->rowCount() > 0 ? '1' : '-1';
}

function mucho7_social_dispatch(PDO $pdo, $path, $post, $options)
{
    if (empty($options['enable_accounts']) || empty($options['enable_social'])) {
        return '-1';
    }
    $aid = mucho7_identity($pdo,$post);
    if ($aid <= 0) { return '-1'; }
    if (preg_match('~^/getgjfriendrequests(?:20|21|22)?$~D',$path)) {
        return mucho7_social_requests($pdo,$post,$aid);
    }
    if (preg_match('~^/getgjuserlist(?:20|21|22)?$~D',$path)) {
        return mucho7_social_friends($pdo,$post,$aid);
    }
    if (empty($options['enable_writes'])) { return '-1'; }
    if (preg_match('~^/uploadfriendrequest(?:20|21|22)?$~D',$path)) {
        return mucho7_social_send($pdo,$post,$aid);
    }
    if (preg_match('~^/acceptgjfriendrequest(?:20|21|22)?$~D',$path)) {
        return mucho7_social_accept($pdo,$post,$aid);
    }
    if (preg_match('~^/deletegjfriendrequests(?:20|21|22)?$~D',$path)) {
        return mucho7_social_delete_request($pdo,$post,$aid);
    }
    if (preg_match('~^/removegjfriend(?:20|21|22)?$~D',$path)) {
        return mucho7_social_remove_friend($pdo,$post,$aid);
    }
    return '-1';
}
