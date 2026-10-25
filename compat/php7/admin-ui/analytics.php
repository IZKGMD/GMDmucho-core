<?php


$charts=[];

try {
    $charts['Registrations — last 14 days']=$db->query(
        "SELECT DATE(created_at) d,COUNT(*) c
         FROM accounts
         WHERE created_at>=NOW()-INTERVAL 14 DAY
         GROUP BY DATE(created_at)
         ORDER BY d"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {}

try {
    $charts['Levels — last 14 days']=$db->query(
        "SELECT DATE(created_at) d,COUNT(*) c
         FROM levels
         WHERE created_at>=NOW()-INTERVAL 14 DAY
         GROUP BY DATE(created_at)
         ORDER BY d"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {}

foreach($charts as $title=>$data) {
    echo '<div class="card" style="margin-bottom:14px">';
    echo '<h2>'.h($title).'</h2>';

    $max=1;

    foreach($data as $x) {
        $max=max($max,(int)$x['c']);
    }

    foreach($data as $x) {
        $pct=((int)$x['c']/$max)*100;

        echo '<div style="margin:9px 0">';
        echo '<div class="row">';
        echo '<small>'.h($x['d']).'</small>';
        echo '<b>'.h($x['c']).'</b>';
        echo '</div>';
        echo '<div class="bar"><i style="width:'.
            h(round($pct,2)).
            '%"></i></div>';
        echo '</div>';
    }

    echo '</div>';
}
