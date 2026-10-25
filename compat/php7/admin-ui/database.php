<?php


requireRank(40);
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$selected=is_string($_GET['table'] ?? null)?$_GET['table']:'';
$tableQuery=is_string($_GET['table_q'] ?? null)?substr(trim($_GET['table_q']),0,100):'';
?>
<p class="mc-page-lead">Browse your server tables. This view shows up to 100 rows per table.</p>
<div class="mc-database-layout">
<section class="card mc-database-catalog" aria-label="Database tables">
<h2>Tables <small>(<?=count($tables)?>)</small></h2>
<form method="get">
<input type="hidden" name="page" value="database">
<?php if($selected!==''): ?><input type="hidden" name="table" value="<?=h($selected)?>"><?php endif; ?>
<label for="databaseTableSearch" class="sr-only">Filter table names</label>
<input id="databaseTableSearch" name="table_q" value="<?=h($tableQuery)?>" placeholder="Find a table…" type="search">
<button type="submit" class="gray">Filter tables</button>
</form>
<nav class="mc-database-list" aria-label="Table selection">
<?php $visibleTables=0; foreach($tables as $t):
    if($tableQuery!=='' && stripos($t,$tableQuery)===false) continue;
    $visibleTables++;
?>
<a href="/admin/?page=database&amp;table=<?=rawurlencode($t)?>&amp;table_q=<?=rawurlencode($tableQuery)?>" <?=$selected===$t?'aria-current="page"':''?>><?=h($t)?></a>
<?php endforeach; ?>
<?php if(!$visibleTables): ?><p>No matching tables.</p><?php endif; ?>
</nav>
</section>
<section class="card mc-database-data">
<?php if($selected!=='' && in_array($selected,$tables,true)):
    $quotedTable=str_replace('`','``',$selected);
    $rows=$db->query("SELECT * FROM `$quotedTable` LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="mc-section-label">Table preview</div>
<h2><?=h($selected)?></h2>
<p class="mc-page-lead"><?=count($rows)?> rows shown · Long values are shortened for display.</p>
<?php if($rows): ?>
<div class="table"><table>
<thead><tr><?php foreach(array_keys($rows[0]) as $k): ?><th scope="col"><?=h($k)?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach($rows as $r): ?><tr><?php foreach($r as $v):
    $v=(string)$v;
    if(strlen($v)>150) $v=substr($v,0,150).'…';
?><td><?=h($v)?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?><div class="mc-empty-panel"><strong>This table is empty</strong><p>There are no rows to display.</p></div><?php endif; ?>
<?php else: ?>
<div class="mc-empty-panel"><strong>Select a table</strong><p>Choose a table from the list to preview its rows.</p></div>
<?php endif; ?>
</section>
</div>
<?php
