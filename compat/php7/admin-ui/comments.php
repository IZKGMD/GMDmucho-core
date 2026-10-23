<?php


foreach(
    [
        'php7_comments'=>'Level comments',
        'php7_account_comments'=>'Profile comments'
    ] as $table=>$label
) {
    $kind=$table==='php7_comments'?1:0; if (!tableExists($db,'php7_comments')) continue;

    echo '<h2>'.h($label).'</h2>';

    $rows=$db->query(
        "SELECT * FROM php7_comments WHERE kind=$kind
         ORDER BY id DESC LIMIT 120"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if ($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '<th></th></tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                $v=(string)$v;

                if (strlen($v)>120) {
                    $v=substr($v,0,120).'…';
                }

                echo '<td>'.h($v).'</td>';
            }

            echo '<td>
            <form method="post">
            <input type="hidden" name="csrf" value="'.csrf().'">
            <input type="hidden" name="action" value="comment-delete">
            <input type="hidden" name="return" value="comments">
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
