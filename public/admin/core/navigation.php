<?php
declare(strict_types=1);

require_once __DIR__.'/AdminUi.php';
$primary=['dashboard','players','levels','rating','songs','news','clients','settings'];
$extra=array_diff(array_keys($pages),$primary);
$extra=array_values(array_filter($extra,'canAdminPage'));
$navLink=static function(string $key) use ($page,$pages): void {
    if (!canAdminPage($key)) return;
    $icon=in_array($key,['dashboard','players','levels','rating','songs','news','settings','plugins','admins'],true) ? $key : 'plugins';
    if ($key==='clients') $icon='downloads';
    echo '<a href="/admin/?page='.h($key).'" title="'.h($pages[$key]).'" aria-current="'.($page===$key?'page':'false').'" class="'.($page===$key?'on':'').'">';
    echo adminGdIcon($icon).'<span>'.h($pages[$key]).'</span></a>';
};
?>
<nav class="admin-nav" aria-label="Main">
<?php foreach($primary as $key) $navLink($key); ?>
<?php if($extra): ?>
<details class="admin-nav-extra" <?=in_array($page,$extra,true)?'open':''?>>
<summary>More tools</summary>
<?php foreach($extra as $key) $navLink($key); ?>
</details>
<?php endif ?>
<a class="logout" href="/admin/?logout=1"><?=adminGdIcon('admins')?><span>Logout</span></a>
</nav>
