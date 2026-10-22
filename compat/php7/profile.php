<?php
/** Authenticated score/appearance updates; returns GD's userID, never a role. */
declare(strict_types=1);

function mucho7_update_score(PDO $pdo, $data)
{
    $aid = mucho7_identity($pdo, $data);
    if ($aid <= 0) { return '-1'; }
    $mapping = array(
        'stars'=>array('stars',10000000), 'moons'=>array('moons',10000000),
        'diamonds'=>array('diamonds',100000000), 'demons'=>array('demons',1000000),
        'secret_coins'=>array('coins',1000000), 'user_coins'=>array('userCoins',1000000),
        'icon_id'=>array(isset($data['icon']) ? 'icon' : 'accIcon',65535),
        'icon_type'=>array('iconType',32), 'color1'=>array('color1',65535),
        'color2'=>array('color2',65535),
        'glow'=>array(isset($data['accGlow']) ? 'accGlow' : 'glow',1)
    );
    $sets = array(); $params = array(':aid'=>$aid);
    foreach ($mapping as $column=>$definition) {
        if (!isset($data[$definition[0]])) { continue; }
        $value = $data[$definition[0]];
        if (!is_scalar($value) || !preg_match('/^\d{1,10}$/D', (string)$value)) { return '-1'; }
        $params[':'.$column] = min($definition[1], (int)$value);
        $sets[] = $column . '=:' . $column;
    }
    if ($sets) {
        $query = $pdo->prepare('UPDATE profiles SET ' . implode(',', $sets)
            . ',updated_at=CURRENT_TIMESTAMP WHERE account_id=:aid');
        $query->execute($params);
    }
    $find = $pdo->prepare('SELECT user_id FROM profiles WHERE account_id=?');
    $find->execute(array($aid));
    $uid = (int)$find->fetchColumn();
    return $uid > 0 ? (string)$uid : '-1';
}
