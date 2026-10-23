<?php


$rows=$db->query(
    'SELECT c.created_at,a.username,c.action,c.target_id AS target,NULL AS ip,c.details AS metadata FROM php7_admin_audit c LEFT JOIN accounts a ON a.account_id=c.account_id ORDER BY c.id DESC
     LIMIT 400'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Metadata</th></tr>';

foreach($rows as $r) {
    echo '<tr>';
    echo '<td>'.h($r['created_at']).'</td>';
    echo '<td>'.h($r['username']).'</td>';
    echo '<td>'.h($r['action']).'</td>';
    echo '<td>'.h($r['target']).'</td>';
    echo '<td>'.h($r['ip']).'</td>';
    echo '<td>'.h($r['metadata']).'</td>';
    echo '</tr>';
}

echo '</table></div>';
