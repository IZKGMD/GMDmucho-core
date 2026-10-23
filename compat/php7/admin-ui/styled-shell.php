<body class="mc-admin" data-page="<?=h($page)?>" style='--brand-initial:"<?=h($brandInitial)?>";'>
<a class="mc-skip" href="#main">Skip to content</a>

<div class="mobile-overlay" id="mobileOverlay"></div>

<div class="shell">

<aside id="adminNavigation" aria-label="Admin navigation">
<!-- MUCHO_LANG_FLAGS -->
<div id="muchoLangSwitcher" class="mucho-lang-switcher">
    <button type="button" data-lang="en" title="English">EN</button>
    <button type="button" data-lang="ru" title="Русский">RU</button>
</div>
<!-- /MUCHO_LANG_FLAGS -->

<div class="brandrow">
<?php adminBrand($branding['server_name']); ?>
<button
 type="button"
 class="sidebar-toggle"
 id="sidebarToggle"
 aria-expanded="true"
 aria-controls="adminNavigation"
 title="Collapse menu"
>☰</button>
</div>

<?php require __DIR__.'/navigation.php'; ?>
</aside>

<main id="main" tabindex="-1">

<div class="head">

<button
 type="button"
 class="mobile-menu-btn"
 id="mobileMenuButton"
 aria-label="Open menu"
 aria-expanded="false"
 aria-controls="adminNavigation"
>☰</button>

<div class="head-title">
<h1><?=h($pages[$page])?></h1>
<small><?=h((string)(!empty($settings['site_url'])?$settings['site_url']:$branding['server_name']))?></small>
</div>

<?php if (canAdminPage('players') || canAdminPage('levels')): ?>
<form class="global-search" method="get" id="globalSearch">
<select name="page" aria-label="Search type">
<?php foreach (['players'=>'Players','levels'=>'Levels'] as $searchPage=>$searchLabel): ?>
<?php if (canAdminPage($searchPage)): ?><option value="<?=h($searchPage)?>" <?=$page===$searchPage?'selected':''?>><?=h($searchLabel)?></option><?php endif; ?>
<?php endforeach; ?>
</select>

<input
 id="globalSearchInput"
 name="q"
 value="<?=h((string)($_GET['q'] ?? ''))?>"
 placeholder="Quick search…"
 autocomplete="off"
>

<button type="submit">Search</button>
</form>
<?php endif; ?>


<?php adminInterfaceSwitch($_GET); ?>
<div class="mc-theme-slot"></div>
<div class="user-pill">
<div class="avatar">
<?=h(strtoupper(substr(admin()['username'],0,1)))?>
</div>

<div class="user-text">
<b><?=h(admin()['username'])?></b><br>
<small><?=h(admin()['role'])?></small>
</div>
</div>

</div>

<?php if($flash): ?>
<div class="flash <?=h($flash['type'])?>" role="<?=in_array($flash['type'],['err','error'],true)?'alert':'status'?>">
<?=h($flash['text'])?>
</div>
<?php endif ?>
