<?php


requireRank(40);

$tables=$db->query('SHOW TABLES')
    ->fetchAll(PDO::FETCH_COLUMN);

$selected=(string)($_GET['table'] ?? '');

echo '<div class="row">';

foreach($tables as $t) {
    echo '<a class="btn gray" href="/admin/?page=database&table='.
        rawurlencode($t).
        '">'.h($t).'</a>';
}

echo '</div>';

if (
    $selected!=='' &&
    in_array($selected,$tables,true)
) {
    echo '<h2>'.h($selected).'</h2>';

    $rows=$db->query(
        "SELECT * FROM `$selected`
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="table"><table>';

    if($rows) {
        echo '<tr>';

        foreach(array_keys($rows[0]) as $k) {
            echo '<th>'.h($k).'</th>';
        }

        echo '</tr>';

        foreach($rows as $r) {
            echo '<tr>';

            foreach($r as $v) {
                $v=(string)$v;

                if(strlen($v)>150) {
                    $v=substr($v,0,150).'…';
                }

                echo '<td>'.h($v).'</td>';
            }

            echo '</tr>';
        }
    }

    echo '</table></div>';
}

