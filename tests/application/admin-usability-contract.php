<?php
declare(strict_types=1);

require __DIR__.'/../../public/admin/core/AdminUi.php';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function assertWorkflow(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Admin UX: '.$message);
}

$state=adminListState(['q'=>' <unsafe> ','filter'=>'pending','p'=>5]);
assertWorkflow($state['q']==='<unsafe>' && $state['p']===5,'list state parsing');
ob_start();
adminListSummary($state,25,true,'tracks');
adminListPager('songs',$state,true);
$html=(string)ob_get_clean();
assertWorkflow(str_contains($html,'101–125 tracks · More available'),'summary shows real page range');
assertWorkflow(str_contains($html,'q=%3Cunsafe%3E') && str_contains($html,'filter=pending'),'page links preserve search and filters');
assertWorkflow(str_contains($html,'rel="prev"') && str_contains($html,'rel="next"'),'pager exposes meaningful navigation');
assertWorkflow(str_contains($html,'aria-current="page"'),'pager identifies the current page');
ob_start();
adminListSummary(['p'=>1],0,false,'levels');
adminListPager('levels',['q'=>'','filter'=>'','p'=>1],false);
$empty=(string)ob_get_clean();
assertWorkflow(str_contains($empty,'No results') && !str_contains($empty,'rel="next"'),'empty page does not claim extra results');

$root=dirname(__DIR__,2);
$source=static fn(string $path): string => (string)file_get_contents($root.'/'.$path);
$news=$source('public/admin/pages/news.php');
assertWorkflow(str_contains($news,'id="newsEditorForm"') && str_contains($news,'newsPreviewTitle'),'news preview is available');
assertWorkflow(str_contains($news,'data-max-file-mib="5"') && str_contains($news,'data-max-files="6"'),'image limits exposed before upload');
assertWorkflow(!str_contains($news,'onsubmit="return confirm('),'news deletion shares central confirmation');
assertWorkflow(str_contains($source('public/admin/pages/songs.php'),'data-max-file-mib="20"'),'music upload exposes size check');
assertWorkflow(str_contains($source('public/admin/pages/players.php'),'admin-danger-note'),'player deletion describes consequences');
assertWorkflow(str_contains($source('public/admin/pages/levels-moderation.php'),'l.requested_stars>0 AND l.stars=0'),
    'pending queue excludes already rated levels');

$api=$source('public/admin/v5-api.php');
assertWorkflow(str_contains($api,"AdminRbac::can(\$db, \$currentAdmin, 'monitoring.view')"),
    'monitoring API rechecks RBAC each request');
assertWorkflow(str_contains($api,"AdminRbac::can(\$db,\$currentAdmin,'system.manage')"),
    'host logs require system management');
assertWorkflow(!str_contains($api,'CREATE TABLE IF NOT EXISTS admin_notifications'),'read-only API performs no schema writes');
assertWorkflow(!str_contains($api,"p.last_ip"),'review endpoint excludes sensitive player IPs');
assertWorkflow(str_contains($api,"'method'=>\$path==='/health' ? 'GET' : 'POST'"),
    '/health is requested with GET');
$monitorScript=$source('public/admin/admin-monitoring.js');
assertWorkflow(!str_contains($monitorScript,'.innerHTML') && str_contains($monitorScript,'replaceChildren'),
    'monitoring renders untrusted response data as text nodes');
assertWorkflow(str_contains($monitorScript,'healthBusy') && str_contains($monitorScript,'document.hidden'),
    'monitoring prevents overlapping background checks');
$workflows=$source('public/admin/admin-workflows.js');
assertWorkflow(str_contains($workflows,'textContent') && !str_contains($workflows,'.innerHTML'),
    'news live preview does not interpret user HTML');
assertWorkflow(str_contains($source('public/admin/core/plain-shell.php'),'admin-workflows.js'),
    'plain admin retains form usability checks');
assertWorkflow(str_contains($source('public/admin/index.php'),'admin-workflows.js'),
    'styled admin includes workflow usability checks');

ob_start();
function canPermission(string $permission): bool
{
    global $hostLogsAllowed;
    return $permission==='system.manage' && $hostLogsAllowed;
}
$hostLogsAllowed=false;
require $root.'/public/admin/monitoring-module.php';
$restricted=(string)ob_get_clean();
assertWorkflow(!str_contains($restricted,'id="v5Logs"') && str_contains($restricted,'restricted'),
    'monitoring hides host logs when permission is missing');
$hostLogsAllowed=true;
ob_start();
require $root.'/public/admin/monitoring-module.php';
$admin=(string)ob_get_clean();
assertWorkflow(str_contains($admin,'id="v5Logs"') && str_contains($admin,'admin-monitoring.js'),
    'system operator receives explicit log controls');

echo "MUCHOCORE_ADMIN_USABILITY_OK\n";
