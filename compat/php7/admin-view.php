<?php
/** Exact upstream admin shell and page templates with a native PHP7 host bridge. */
declare(strict_types=1);
$script=isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/public/php7-admin.php';
$publicBase=rtrim(str_replace('\\','/',dirname($script)),'/.');
if(substr($script,-10)==='/admin.php')$publicBase.='/public';
$assetBase=$publicBase.'/assets';
require_once __DIR__.'/admin-ui/bridge.php';
$branding=array('server_name'=>!empty($settings['server_name'])?$settings['server_name']:'MuchoCore','server_by_name'=>isset($settings['server_by_name'])?$settings['server_by_name']:'','social_url'=>isset($settings['social_url'])?$settings['social_url']:'');
$brandInitial=strtoupper(substr($branding['server_name'],0,1));
$pages=require $root.'/compat/php7/admin-ui/pages.php';
unset($pages['clients']);$pages['clientpatcher']='Client patcher';
if($page==='overview')$page='dashboard';
$pageAliases=array('updates'=>'system','clients'=>'clientpatcher','backups'=>'dbbackups');if(isset($pageAliases[$page]))$page=$pageAliases[$page];
$isOwner=$user && $user['role']==='owner';$isElder=$user && in_array($user['role'],array('owner','elder_moderator'),true);
$flash=$error!==''?array('type'=>'err','text'=>$error):($notice!==''?array('type'=>'ok','text'=>$notice):null);
$db=$pdo;
ob_start();
if(!$user || !adminIsPlain()):
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($branding['server_name'])?> Control</title>
<link rel="icon" href="/assets/muchocore-icon.png">
<link rel="stylesheet" href="/assets/php7-admin-<?=$user?'base':'login'?>.css">
<link rel="stylesheet" href="/admin/admin-i18n.css?v=2">
<?php if($user):?><link rel="stylesheet" href="/admin/admin-ui-v2.css?v=4"><?php endif ?>
<link rel="stylesheet" href="/muchocore-theme.css?v=3"><script src="/muchocore-theme.js?v=5" defer></script>
<link rel="stylesheet" href="/assets/product-ui.css?v=2"><link rel="stylesheet" href="/admin/product-admin.css?v=1">
<?php if($user):?><script src="/assets/php7-admin-product.js" defer></script><?php endif ?><link rel="stylesheet" href="/admin/admin-simple.css?v=4">
<script src="/admin/admin-simple.js?v=2" defer></script><link rel="stylesheet" href="/admin/admin-polish.css?v=5"><link rel="stylesheet" href="/admin/admin-workspace.css?v=6">
<link rel="stylesheet" href="/admin/admin-motion.css?v=2"><script src="/admin/admin-motion.js?v=1" defer></script>
</head>
<?php endif;if(!$user):?>
<body class="admin-login mc-workspace" data-page=""><main class="admin-auth-card"><header><?php adminBrand($branding['server_name']);adminInterfaceSwitch();?></header>
<h1><?=$hasOwner?'Administrator sign in':'First owner'?></h1>
<?php if($error!==''):?><p class="err" role="alert"><?=h($error)?></p><?php endif ?>
<?php if($pdo):?><form method="post" id="adminLoginForm"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="<?=$hasOwner?'login':'bootstrap'?>">
<?php if(!$hasOwner):?><p class="admin-auth-field"><label for="dbpass">Password database</label><input id="dbpass" type="password" name="db_password" required autocomplete="off"></p><?php endif ?>
<div id="passwordLoginFields"><p class="admin-auth-field"><label for="adminUsername">Username</label><br><input id="adminUsername" name="userName" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="32" required></p>
<p class="admin-auth-field"><label for="adminPassword">Password</label><br><input id="adminPassword" type="password" name="password" autocomplete="<?=$hasOwner?'current-password':'new-password'?>" required></p></div>
<?php if(!$hasOwner):?><p class="admin-auth-field"><label for="email">Email</label><input id="email" type="email" name="email" required maxlength="254"></p><?php endif ?>
<?php if($hasOwner):?><p id="otpLoginField" class="admin-auth-field"><label for="adminOtp">2FA or recovery code</label><br><input id="adminOtp" name="otp" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" maxlength="32" aria-describedby="adminOtpHelp"><br><small id="adminOtpHelp">Leave empty if two-factor authentication is disabled.</small></p><?php endif ?>
<?php if($freshInstall):?><p class="admin-auth-field"><label for="setup-domain">HTTPS-server domain</label><input id="setup-domain" name="site_url" type="url" required value="<?=h(isset($settings['site_url'])?$settings['site_url']:'')?>"></p><p><label><input type="checkbox" name="enable_features" value="1" checked> Enable game features</label></p><?php endif ?>
<button id="normalSignInButton" type="submit"><?=$hasOwner?'Sign in':'Create owner'?></button></form><?php endif ?>
<?php if($hasOwner&&$pdo&&tableExists($pdo,'php7_admin_passkeys'))require __DIR__.'/admin-ui/passkeys.php'; ?>
<?php adminFooter($branding);?></main></body></html>
<?php else:
if(adminIsPlain())require __DIR__.'/admin-ui/plain-shell.php';else{
    // Include the original PHP shell; source mirrors are generated, not evaluated.
    require __DIR__.'/admin-ui/styled-shell.php';
}
if($schemaReady && canAdminPage($page)){
    if($page==='system'){mucho7_core_render($db,$root);if($isOwner&&is_file($root.'/config/php7-complete.on'))require __DIR__.'/admin-ui/shared-worker.php';}
    if(in_array($page,array('clans','contentpacks','social','plugins','muchoprofiles','analytics','database','endpoints','intelligence','clientfeatures','securitycenter','monitoring','advanced','migration'),true)){
        if(!is_file($root.'/config/php7-complete.on')){echo '<p>Complete the full core migration on the Server page first.</p>';}
        else{require_once $root.'/compat/php7/domain.php';mucho7_domain_configure($settings,$root,$db);
            if(in_array($page,array('social','analytics','database','endpoints'),true))require __DIR__.'/admin-ui/'.$page.'.php';
            elseif($page==='intelligence'){require mucho7_compiled_include($root.'/public/admin/pages/intelligence.php');ob_start();renderIntelligenceScalePage($db);$intelligence=ob_get_clean();$intelligence=preg_replace('~<pre>sudo .*?</pre>~s','<p>Use Server for the task queue, search index rebuild, provider cron and verified backups.</p>',$intelligence);echo str_replace('Run the rebuild command from the operator CLI.','Use Server → Search index rebuild.',$intelligence);}
            elseif($page==='advanced'){if(is_file(MUCHO7_COMPILED_ROOT.'/public/admin/advanced-module.php'))require mucho7_compiled_include($root.'/public/admin/advanced-module.php');else echo '<p>Install the uploaded build on the Server page to enable Control v4.</p>';}
            elseif($page==='migration'){if(is_file(MUCHO7_COMPILED_ROOT.'/public/admin/pages/migration.php'))require __DIR__.'/migration.php';else echo '<p>Install the uploaded build on the Server page to enable Migration Center.</p>';}
            elseif($page==='clientfeatures'){require mucho7_compiled_include($root.'/public/admin/client-features-module.php');renderClientFeaturesPage($db);}
            elseif($page==='securitycenter'||$page==='monitoring'){require mucho7_compiled_include($root.'/public/admin/security-monitoring-module.php');renderSecurityMonitoringPage($db);}
            else{require mucho7_compiled_include($root.'/public/admin/pages/'.$page.'.php');if($page==='plugins')renderCustomPluginsPage($db);}
        }
    }
    elseif($page==='dashboard'){require_once __DIR__.'/admin-ui/dashboard.php';renderAdminDashboard($db);}
    elseif($page==='clientpatcher')require __DIR__.'/admin-ui/clientpatcher.php';
    elseif($page==='players')require __DIR__.'/admin-ui/players.php';
    elseif($page==='levels'||$page==='moderation')require __DIR__.'/admin-ui/levels-moderation.php';
    elseif($page==='rating')require __DIR__.'/admin-ui/rating.php';
    elseif(in_array($page,array('songs','comments','messages','audit','settings','news','admins','roles'),true)){
        if($page!=='admins'||canPermission('self.security'))require __DIR__.'/admin-ui/'.$page.'.php';
        if($page==='admins'){require __DIR__.'/admin-ui/admin-management.php';require __DIR__.'/admin-ui/passkeys.php';}
        if($page==='settings'){$titles=$pages;require __DIR__.'/admin-ui/native-pages.php';require __DIR__.'/admin-ui/webhooks.php';}
    }
    elseif($page==='dbbackups'){require_once __DIR__.'/admin-backups.php';renderDbBackupCenter($db);}
    else{$titles=$pages;$oldPage=$page;if($page==='system')$page='diagnostics';require __DIR__.'/admin-ui/native-pages.php';$page=$oldPage;}
}else{$titles=$pages;require __DIR__.'/admin-ui/native-pages.php';}
adminFooter($branding);
?></main><?php if(!adminIsPlain()):?></div><script src="/admin/admin-i18n.js?v=105"></script><?php else:?><script src="/assets/php7-admin-product.js" defer></script><?php endif ?><script src="/assets/php7-admin-host.js" defer></script></body></html>
<?php endif;
$html=ob_get_clean();
$html=preg_replace('~<body\b~','<body data-admin-language="en"',$html,1);
if($user)$html=preg_replace('~<body\b~','<body data-csrf="'.h(csrf()).'" data-admin-path="'.h($script).'"',$html,1);
echo mucho7_admin_ui_html($html);
