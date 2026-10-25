<?php


foreach(
    [
        'friend_requests',
        'friends',
        'blocks'
    ] as $table
) {
    if (!tableExists($db,$table)) continue;

    echo '<h2>'.h($table).'</h2>';

    $rows=$db->query(
        "SELECT * FROM `$table`
         ORDER BY id DESC LIMIT 120"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '<th></th></tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                echo '<td>'.h($v).'</td>';
            }

            echo '<td>
            <form method="post">
            <input type="hidden" name="csrf" value="'.csrf().'">
            <input type="hidden" name="action" value="relation-delete">
            <input type="hidden" name="return" value="social">
            <input type="hidden" name="table" value="'.h($table).'">
            <input type="hidden" name="id" value="'.h($r['id']).'">
            <button class="red">Delete</button>
            </form>
            </td>';

            echo '</tr>';
        }
    }

    echo '</table></div>';
}
