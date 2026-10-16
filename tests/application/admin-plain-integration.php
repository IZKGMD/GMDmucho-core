<?php
declare(strict_types=1);

require __DIR__.'/../../public/admin/core/PlainAdmin.php';
require __DIR__.'/../../public/admin/core/AdminUi.php';
require __DIR__.'/../../src/Admin/AdminRbac.php';
function h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrf(): string { return 'plain-fixture-token'; }
function tableExists(PDO $db,string $table): bool { return true; }
function check(bool $condition,string $name): void {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL;
}
function dom(string $html): DOMXPath {
    $doc=new DOMDocument();
    $old=libxml_use_internal_errors(true);
    $doc->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($old);
    return new DOMXPath($doc);
}
$fixture=<<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><style>.card{display:none}</style>
<link rel="stylesheet" href="/admin/admin-simple.css"><link rel="preload" as="style" href="/extra.css">
<script src="/muchocore-theme.js"></script><script src="/admin/admin-simple.js"></script></head>
<body style="color:red"><h1>Кланы &amp; игроки</h1><form method="post" action="/admin/?page=players">
<input name="csrf" type="hidden" value="plain-fixture-token"><input name="id" value="42">
<input name="username" value="&quot;&lt;script&gt; &amp; Игрок"><label>Clan name<input name="clan_name" value="Native form"></label><label><input type="checkbox" name="enabled" checked>Enabled</label><button name="action" value="account-save">Save</button></form>
<table><tr><th>Name</th></tr><tr><td>Native table</td></tr></table><details><summary>Statistics</summary><input name="stars" value="50"></details>
<a href="/admin/?page=players&amp;p=2">Next</a><a href="/admin/?page=players" data-styled-admin>Styled</a>
<a href="/admin/assets/gd/players.png">Asset</a><a href="https://example.test/admin/">External</a>
<script>const config={token:'<&test'};</script></body></html>
HTML;
$html=adminPlainHtml($fixture);$xpath=dom($html);
check($xpath->query('//style | //link[@rel="stylesheet"] | //link[@as="style"] | //*[@style]')->length===0,'plain response has no CSS nodes or inline styles');
check($xpath->query('//script[@src="/muchocore-theme.js"]')->length===0,'theme injection is removed');
check($xpath->query('//script[@src="/admin/admin-simple.js"]')->length===1,'form confirmation script survives');
check($xpath->query('//form[@action="/admin/plain/?page=players"]//button[@name="action" and @value="account-save"]')->length===1,'POST target and submit button survive');
check($xpath->query('//input[@name="csrf"]')->item(0)->getAttribute('value')==='plain-fixture-token','CSRF token survives unchanged');
check($xpath->query('//input[@name="username"]')->item(0)->getAttribute('value')==='"<script> & Игрок','escaped user content survives without becoming markup');
check($xpath->query('//h1')->item(0)->textContent==='Кланы & игроки','UTF-8 labels survive');
check($xpath->query('//form/p/label/br')->length===1 && $xpath->query('//form/p/label/input[@name="clan_name"]')->length===1,'plain labels gain native spacing without moving controls out of their form');
check($xpath->query('//table[@border="1" and @cellpadding="6"]//th[@scope="col"]')->length===1,'plain tables have native cell separation and scoped headers');
check($xpath->query('//details/summary')->length===1,'native expandable forms survive');
check($xpath->query('//a[@data-styled-admin]')->item(0)->getAttribute('href')==='/admin/?page=players','explicit styled switch remains available');
check(str_contains($html,'href="/admin/plain/?page=players&amp;p=2"'),'pagination stays in the plain panel');
check(str_contains($html,"const config={token:'<&test'};"),'functional inline JavaScript is unchanged');
check(adminPlainUrl('/admin/index.php?download=backup.sql')==='/admin/plain/?download=backup.sql','download route preserves plain entrypoint');
check(adminPlainUrl('/admin/assets/gd/players.png')==='/admin/assets/gd/players.png','asset path is unchanged');
check(adminPlainUrl('https://example.test/admin/')==='https://example.test/admin/','external URL is unchanged');
check(adminPlainHtml("\0PK\x03\x04download") === "\0PK\x03\x04download",'non-HTML payload is byte-identical');
check(adminPlainHtml('{"ok":true}')==='{"ok":true}','JSON is byte-identical');
check(\MuchoCore\Admin\AdminRbac::permissionForContext(null,'clans',0)==='social.manage','clans page requires social management permission');
foreach(['clan-save','clan-role','clan-kick','clan-transfer','clan-delete'] as $action) {
    check(\MuchoCore\Admin\AdminRbac::permissionForContext($action,null,0)==='social.manage',$action.' has an explicit permission');
}
echo "MUCHOCORE_ADMIN_PLAIN_OK\n";
