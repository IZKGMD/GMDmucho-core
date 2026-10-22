<?php
/**
 * Standalone, read-only public GD user endpoints for native PHP 7.0.
 *
 * This implements only public user fields from the isolated PHP7 schema.
 * No account details, credentials, friend lists, private states or mutable
 * account operations. Unknown/credentialed leaderboard modes fail closed.
 *
 * Keep the field order aligned with src/Protocol/GdUserEncoder.php.
 */
declare(strict_types=1);

function mucho7_user_select()
{
    return 'a.account_id, a.username, p.user_id, p.stars, p.moons,
        p.diamonds, p.secret_coins, p.user_coins, p.demons,
        p.creator_points, p.icon_id, p.icon_type, p.color1, p.color2, p.glow';
}

function mucho7_user_name($user)
{
    return mucho7_username(isset($user['username']) ? $user['username'] : 'Player');
}

function mucho7_user_profile_wire($user)
{
    $id = (int)$user['account_id'];
    $uid = (int)$user['user_id'];
    $data = array(
        '1' => mucho7_user_name($user),
        '2' => $uid,
        '13' => (int)$user['secret_coins'],
        '17' => (int)$user['user_coins'],
        '10' => (int)$user['color1'],
        '11' => (int)$user['color2'],
        '51' => 0,
        '3' => (int)$user['stars'],
        '46' => (int)$user['diamonds'],
        '52' => (int)$user['moons'],
        '4' => (int)$user['demons'],
        '8' => (int)$user['creator_points'],
        '18' => 0, '19' => 0, '50' => 0,
        '20' => '', '21' => (int)$user['icon_id'],
        '22' => 1, '23' => 1, '24' => 1, '25' => 1,
        '26' => 1, '28' => (int)$user['glow'],
        '43' => 1, '48' => 1, '53' => 1, '54' => 1,
        '30' => 0, '16' => $id, '31' => 0,
        '44' => '', '45' => '', '49' => 0,
        '55' => '', '56' => '', '57' => '', '58' => '',
        '59' => '', '60' => '', '61' => '', '29' => 1,
    );
    $fields = array();
    foreach ($data as $key => $value) {
        $fields[] = $key . ':' . $value;
    }
    return implode(':', $fields);
}

function mucho7_user_list_entry($user, $leaderboard)
{
    $id = (int)$user['account_id'];
    $uid = (int)$user['user_id'];
    $data = array(
        '1' => mucho7_user_name($user),
        '2' => $uid,
        '13' => (int)$user['secret_coins'],
        '17' => (int)$user['user_coins'],
    );
    if ($leaderboard) {
        $data['6'] = 0;  // Rank is not persisted by the portable PHP7 schema.
    }
    $data['9'] = (int)$user['icon_id'];
    $data['10'] = (int)$user['color1'];
    $data['11'] = (int)$user['color2'];
    $data['51'] = 0;
    $data['14'] = (int)$user['icon_type'];
    $data['15'] = 0;
    $data['16'] = $id;
    $data['3'] = (int)$user['stars'];
    $data['8'] = (int)$user['creator_points'];
    $data['4'] = (int)$user['demons'];
    if ($leaderboard) {
        $data['7'] = $id;
    }
    $data['46'] = (int)$user['diamonds'];
    $data['52'] = (int)$user['moons'];
    $fields = array();
    foreach ($data as $key => $value) {
        $fields[] = $key . ':' . $value;
    }
    return implode(':', $fields);
}

function mucho7_public_profile(PDO $pdo, $post)
{
    $aid = mucho7_number(isset($post['targetAccountID']) ?
        $post['targetAccountID'] : (isset($post['accountID']) ?
            $post['accountID'] : 0), 0, 2147483647);
    $uid = mucho7_number(isset($post['targetUserID']) ?
        $post['targetUserID'] : (isset($post['userID']) ?
            $post['userID'] : 0), 0, 2147483647);
    if ($aid <= 0 && $uid <= 0) {
        return '-1';
    }
    $byAccount = $aid > 0;
    $sql = 'SELECT ' . mucho7_user_select() .
        ' FROM accounts a INNER JOIN profiles p ON p.account_id=a.account_id
          WHERE a.is_active=1 AND a.is_banned=0 AND ' .
        ($byAccount ? 'a.account_id=:id' : 'p.user_id=:id') . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $byAccount ? $aid : $uid, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? mucho7_user_profile_wire($row) : '-1';
}

function mucho7_public_user_search(PDO $pdo, $post)
{
    $query = isset($post['str']) && is_string($post['str']) ?
        trim($post['str']) : '';
    if ($query === '' || strlen($query) > 100) {
        return '-1';
    }
    $page = mucho7_number(isset($post['page']) ? $post['page'] : 0, 0, 1000);
    $offset = $page * 10;
    $numeric = ctype_digit($query) && strlen($query) <= 10 ?
        (int)$query : 0;
    // Explicitly escape LIKE wildcards and backslashes, not just SQL quotes.
    $like = '%' . str_replace(
        array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $query
    ) . '%';
    $where = 'a.is_active=1 AND a.is_banned=0 AND
        (a.username LIKE :search OR p.user_id=:user_id)';
    $sql = 'SELECT ' . mucho7_user_select() .
        ' FROM accounts a INNER JOIN profiles p ON p.account_id=a.account_id
          WHERE ' . $where .
        ' ORDER BY p.stars DESC, a.account_id ASC LIMIT 10 OFFSET ' . $offset;
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM accounts a INNER JOIN profiles p
         ON p.account_id=a.account_id WHERE ' . $where
    );
    $stmt = $pdo->prepare($sql);
    foreach (array($count, $stmt) as $queryStmt) {
        $queryStmt->bindValue(':search', $like, PDO::PARAM_STR);
        $queryStmt->bindValue(':user_id', $numeric, PDO::PARAM_INT);
        $queryStmt->execute();
    }
    $total = (int)$count->fetchColumn();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$users) {
        return '-1';
    }
    $entries = array();
    foreach ($users as $user) {
        $entries[] = mucho7_user_list_entry($user, false);
    }
    return implode('|', $entries) . '#' . $total . ':' . $offset . ':10';
}

function mucho7_public_leaderboard(PDO $pdo, $post)
{
    // Credentialled, friend and relative leaderboards need full auth and
    // privacy semantics. Never silently treat them as public leaderboards.
    if (isset($post['accountID']) && (string)$post['accountID'] !== '' &&
        (string)$post['accountID'] !== '0') {
        return '-1';
    }
    $type = isset($post['type']) && is_string($post['type']) ?
        strtolower($post['type']) : 'top';
    if (!in_array($type, array('top', 'creators'), true)) {
        return '-1';
    }
    $count = mucho7_number(isset($post['count']) ? $post['count'] : 100, 100, 100);
    $count = max(1, $count);
    $order = $type === 'creators'
        ? 'p.creator_points DESC, p.stars DESC, p.user_id ASC'
        : 'p.stars DESC, p.user_id ASC';
    $stmt = $pdo->query('SELECT ' . mucho7_user_select() .
        ' FROM accounts a INNER JOIN profiles p ON p.account_id=a.account_id
         WHERE a.is_active=1 AND a.is_banned=0
         ORDER BY ' . $order . ' LIMIT ' . $count);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return '-1';
    }
    $entries = array();
    foreach ($rows as $row) {
        $entries[] = mucho7_user_list_entry($row, true);
    }
    return implode('|', $entries);
}
