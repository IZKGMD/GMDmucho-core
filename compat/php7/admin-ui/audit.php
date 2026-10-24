<?php


$rows=$db->query(
    mucho7_admin_audit_rows_sql($db).' LIMIT 400'
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
