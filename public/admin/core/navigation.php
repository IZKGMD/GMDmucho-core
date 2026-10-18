<?php
declare(strict_types=1);

require_once __DIR__.'/AdminUi.php';

$primary=adminPrimaryPages();
$extra=array_values(array_filter(
    array_diff(array_keys($pages),$primary),
    'canAdminPage'
));
$navLink=static function(string $key) use ($page,$pages): void {
    if (!canAdminPage($key)) return;
    $icon=in_array($key,['dashboard','players','levels','rating','songs','news','settings','plugins','admins'],true) ? $key : 'plugins';
    if ($key==='clients') $icon='downloads';
    echo '<li><a href="/admin/?page='.h($key).'" title="'.h($pages[$key]).'" aria-current="'.($page===$key?'page':'false').'" class="'.($page===$key?'on':'').'">';
    echo (adminIsPlain()?'':adminGdIcon($icon)).'<span>'.h($pages[$key]).'</span></a></li>';
};

// Never display inaccessible pages or even the heading of an empty group.
// Keep future pages reachable under Other tools without editing this file.
$groups=[];
$listed=[];
foreach (adminExtraGroups() as $label=>$keys) {
    $visible=array_values(array_intersect($keys,$extra));
    if (!$visible) continue;
    $groups[$label]=$visible;
    array_push($listed,...$visible);
}
$other=array_values(array_diff($extra,$listed));
if ($other) $groups['Other tools']=$other;
?>
<nav class="admin-nav" aria-label="Main">
<ul><?php foreach($primary as $key) $navLink($key); ?></ul>
<?php if($groups): ?>
<details class="admin-nav-extra" <?=in_array($page,$extra,true)?'open':''?>>
<summary>More tools</summary>
<?php foreach ($groups as $label=>$keys): ?>
<details class="admin-nav-group" <?=in_array($page,$keys,true)?'open':''?>>
<summary><?=h($label)?> <span class="admin-nav-group-count" aria-hidden="true"><?=count($keys)?></span></summary>
<ul><?php foreach($keys as $key) $navLink($key); ?></ul>
</details>
<?php endforeach ?>
</details>
<?php endif ?>
<a class="logout" href="/admin/?logout=1"><?=adminIsPlain()?'':adminGdIcon('admins')?><span>Logout</span></a>
</nav>
