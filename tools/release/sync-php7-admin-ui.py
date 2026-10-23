#!/usr/bin/env python3
"""Mirror the actual VPS admin templates; only lower PHP syntax and mount URLs."""
from pathlib import Path
import hashlib,json,re,shutil
ROOT=Path(__file__).resolve().parents[2]
DEST=ROOT/'compat/php7/admin-ui'
DEST.mkdir(parents=True,exist_ok=True)
index=(ROOT/'public/admin/index.php').read_text()
manifest={}
def record(src,out,content=None):
    source=ROOT/src
    manifest[src]=hashlib.sha256(source.read_bytes()).hexdigest()
    target=DEST/out
    target.write_text((content if content is not None else source.read_text()).rstrip()+'\n')
def lower(source):
    source=re.sub(r':\s*(?:void|\?int)\b','',source)
    source=re.sub(r'catch\s*\(Throwable\)',r'catch (Throwable $error)',source)
    source=re.sub(r'\bas\s*\[([^\]]+)\]\s*\)',r'as list(\1))',source)
    source=source.replace("static fn(array $action): bool => canAdminPage($action[0])", "static function(array $action): bool { return canAdminPage($action[0]); }")
    source=source.replace("require_once __DIR__.'/AdminUi.php';","require_once __DIR__.'/bridge.php';")
    source=source.replace('mb_substr(', 'mucho7_ui_substr(').replace('mb_strtoupper(', 'mucho7_ui_upper(')
    source=source.replace('/admin/product-dashboard.js?v=2','/assets/php7-admin-dashboard.js?v=2')
    return source
for name in ['AdminChrome.php','navigation.php','plain-shell.php']:
    record('public/admin/core/'+name,name,lower((ROOT/'public/admin/core'/name).read_text()))
for name in ['dashboard.php','players.php','levels-moderation.php','songs.php','rating.php','news.php']:
    source=lower((ROOT/'public/admin/pages'/name).read_text())
    source=source.replace("require_once __DIR__.'/../core/AdminUi.php';", "require_once __DIR__.'/bridge.php';")
    if name=='rating.php':
        source=source.replace("return match (true) {\n        (int)($row['epic'] ?? 0) >= 3 => 4,\n        (int)($row['epic'] ?? 0) === 2 => 3,\n        (int)($row['epic'] ?? 0) === 1 => 2,\n        (int)($row['featured'] ?? 0) > 0 => 1,\n        default => 0,\n    };", "if ((int)($row['epic'] ?? 0)>=3) return 4;\n    if ((int)($row['epic'] ?? 0)===2) return 3;\n    if ((int)($row['epic'] ?? 0)===1) return 2;\n    return (int)($row['featured'] ?? 0)>0?1:0;")
        source=re.sub(r"return match \(\(int\)\(\$row\['demon_difficulty'\] \?\? 0\)\) \{.*?\};", "switch ((int)($row['demon_difficulty'] ?? 0)) {case 1:return 'easy-demon';case 2:return 'medium-demon';case 4:return 'insane-demon';case 5:return 'extreme-demon';default:return 'hard-demon';}", source, flags=re.S)
        source=re.sub(r"return match \(\(int\)\(\$row\['difficulty'\] \?\? 0\)\) \{.*?\};", "return mucho7_admin_difficulty($row);", source, flags=re.S)
    if name=='songs.php':
        source=source.replace('<details class="card"><summary>Upload Music</summary>', '<details class="card"><summary>Upload Music</summary>')
    if name=='dashboard.php':
        source=source.replace('<a class="btn gray" href="/dashboard" target="_blank" rel="noopener">Open Player Portal ↗</a>', '')
        source=source.replace('Create or restore a verified backup','Download a consistent SQL backup')
    if name=='rating.php':
        source=re.sub(r'\$difficultyProfileForRow = static function.*?\n};', '$difficultyProfileForRow = static function (array $row): string { return adminDifficultyProfile($row); };', source, flags=re.S)
    if name=='news.php':
        source=re.sub(r'\$nativeNewsFlag = .*?;', "$nativeNewsFlag = !empty($settings['enable_native_news']);",source)
        source=re.sub(r'\$nativeNewsUrl = .*?;', "$nativeNewsUrl = isset($settings['site_url'])?$settings['site_url']:'';",source,count=1)
        source=source.replace('mb_strimwidth(', 'mucho7_ui_trimwidth(')
        source=source.replace('/api/muchocore/news','/admin/?news_feed=1')
        source=source.replace('<input type="hidden" name="action"', '<input type="hidden" name="news_version" value="<?=h($newsService->nativeVersion())?>"><input type="hidden" name="action"')
    record('public/admin/pages/'+name,name,source)
# Preserve the original table-page markup; only map native storage names.
comments=index[index.index("elseif($page==='comments') {")+len("elseif($page==='comments') {"):index.index('/* =========================================================\n   MESSAGES')].rstrip()
comments=comments[:comments.rfind('}')]
comments=comments.replace("'comments'=>'Level comments'", "'php7_comments'=>'Level comments'").replace("'account_comments'=>'Profile comments'", "'php7_account_comments'=>'Profile comments'")
comments=comments.replace("if (!tableExists($db,$table)) continue;", "$kind=$table==='php7_comments'?1:0; if (!tableExists($db,'php7_comments')) continue;")
comments=comments.replace('SELECT * FROM `$table`', 'SELECT * FROM php7_comments WHERE kind=$kind')
record('public/admin/index.php','comments.php','<?php\n'+lower(comments))
messages=index[index.index("elseif($page==='messages') {")+len("elseif($page==='messages') {"):index.index('/* =========================================================\n   SOCIAL')].rstrip()
messages=messages[:messages.rfind('}')].replace("tableExists($db,'messages')", "tableExists($db,'php7_messages')").replace('FROM messages m','FROM php7_messages m')
record('public/admin/index.php','messages.php','<?php\n'+lower(messages))
audit=index[index.index("elseif($page==='audit') {")+len("elseif($page==='audit') {"):index.index('?>\n\n<?php adminFooter($branding)',index.index("elseif($page==='audit') {"))].rstrip()
audit=audit[:audit.rfind('}')]
audit=audit.replace('SELECT *\n     FROM admin_audit_logs\n     ORDER BY id DESC', "SELECT c.created_at,a.username,c.action,c.target_id AS target,NULL AS ip,c.details AS metadata FROM php7_admin_audit c LEFT JOIN accounts a ON a.account_id=c.account_id ORDER BY c.id DESC")
audit=audit.replace("'SELECT c.created_at,a.username,c.action,c.target_id AS target,NULL AS ip,c.details AS metadata FROM php7_admin_audit c LEFT JOIN accounts a ON a.account_id=c.account_id ORDER BY c.id DESC\n     LIMIT 400'", "mucho7_admin_audit_rows_sql($db).' LIMIT 400'")
record('public/admin/index.php','audit.php','<?php\n'+lower(audit))
settings=index[index.index("elseif($page==='settings') {")+len("elseif($page==='settings') {"):index.index('/* =========================================================\n   SYSTEM')].rstrip()
settings=settings[:settings.rfind('}')].replace('CONTROL_DIR', "$root.'/config'")
record('public/admin/index.php','settings.php','<?php\n'+lower(settings))
login=index[index.index('<body class="admin-login mc-workspace"'):index.index("require_once __DIR__.'/core/AdminRouter.php';")]
# Keep original core stylesheet blocks external under strict CSP.
style_blocks=re.findall(r'<style(?:\s[^>]*)?>(.*?)</style>',index,re.S)
login_css=next(s for s in style_blocks if '--sidebar-width:245px' in s)
start=index.index('<style>',index.index("require __DIR__.'/core/plain-shell.php';"))
end=index.index('<link rel="stylesheet" href="/admin/admin-i18n.css',start)
styles='\n'.join(re.findall(r'<style(?:\s[^>]*)?>(.*?)</style>',index[start:end],re.S))
(ROOT/'public/assets/php7-admin-base.css').write_text(styles.rstrip()+'\n')
(ROOT/'public/assets/php7-admin-login.css').write_text(login_css.rstrip()+'\n')
# Original source shell, unchanged apart from the scoped include location and dynamic brand style.
start=index.index('<body class="mc-admin"')
end=index.index("} // Styled shell; page modules below",start)
shell=index[start:end]
shell=shell[:shell.rfind('<?php')]
shell=shell.replace("require __DIR__.'/core/navigation.php';", "require __DIR__.'/navigation.php';")
shell=shell.replace("(string)((Environment::get('MUCHO_ACCOUNT_URL', '') ?: $branding['server_name']))", "(string)(!empty($settings['site_url'])?$settings['site_url']:$branding['server_name'])")
record('public/admin/index.php','styled-shell.php',shell)
record('public/admin/core/AdminUi.php','list-ui.php',lower((ROOT/'public/admin/core/AdminUi.php').read_text().split('function adminDifficultyProfile')[0]))
# Keep the real public asset paths. Their bytes are not recombined or restyled.
assets=['public/admin/admin-ui-v2.css','public/admin/admin-i18n.css','public/admin/admin-i18n.js','public/admin/admin-simple.css','public/admin/admin-simple.js','public/admin/admin-polish.css','public/admin/product-admin.css','public/assets/product-ui.css','public/muchocore-theme.css','public/muchocore-theme.js']
for path in assets: manifest[path]=hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
# JavaScript's original admin mount is the sole frontend portability change.
product=(ROOT/'public/assets/product-ui.js').read_text()
product=product.replace("url.pathname !== '/admin/'", "![ '/admin/', document.body.dataset.adminPath ].includes(url.pathname)")
product=product.replace("return '/admin/?page='+url.searchParams.get('page');", "return (document.body.dataset.adminPath || '/admin/')+'?page='+url.searchParams.get('page');")
(ROOT/'public/assets/php7-admin-product.js').write_text(product)
audit=(ROOT/'public/admin/product-dashboard.js').read_text().replace("fetch('/admin/?audit_feed=1'", "fetch((document.body.dataset.adminPath || '/admin/')+'?audit_feed=1'")
(ROOT/'public/assets/php7-admin-dashboard.js').write_text(audit)
record('public/admin/config/pages.php','pages.php')
# Mirror the same News domain code, lowering only syntax unsupported by PHP 7.0.
news_dest=ROOT/'compat/php7/news'
news_dest.mkdir(exist_ok=True)
for name in ['NewsService','NewsMediaService','NewsImage','NewsValidationException','YouTubeVideo','NativeNewsFormat','NewsImageResponse']:
    path='src/News/'+name+'.php'
    source=(ROOT/path).read_text()
    source=source.replace('final readonly class','final class')
    source=re.sub(r'\b(?:private|public) const ', 'const ',source)
    source=source.replace('public function __construct(private readonly PDO $db) {}', 'private $db;\n    private $expectedVersion;\n    public function __construct(PDO $db, $expectedVersion=null) { $this->db=$db; $this->expectedVersion=$expectedVersion; }')
    source=source.replace('public function __construct(private PDO $db) {}','private $db;\n    public function __construct(PDO $db) { $this->db=$db; }')
    if name=='NewsImage':
        source=re.sub(r'    private function __construct\(.*?\) \{\}', '''    private $bytes;
    public $width;
    public $height;
    public $filename;
    private function __construct($bytes,$width,$height,$filename) {
        $this->bytes=$bytes;$this->width=$width;$this->height=$height;$this->filename=$filename;
    }''',source,flags=re.S)
    if name=='NewsService':
        # Lock the revision before touching posts/media, and reject stale forms.
        source=source.replace('        try {\n', '        try {\n        $this->lockRevision();\n')
        offset=source.index('    private function bumpNativeVersion')
        source=source[:offset]+'''    private function lockRevision() {
        $version=$this->db->query('SELECT version FROM mucho_news_state WHERE id=1 FOR UPDATE')->fetchColumn();
        if ($version===false || ($this->expectedVersion!==null && (string)$version!==(string)$this->expectedVersion)) {
            throw new NewsValidationException('News changed in another session. Reload the page before saving.');
        }
    }

'''+source[offset:]
    source=re.sub(r':\s*(?:void|never|\?self|\?string)\b','',source)
    source=re.sub(r'\?(?:array|string|callable) (\$\w+)',r'\1',source)
    source=re.sub(r'catch\s*\(\\Throwable\)',r'catch (\\Throwable $error)',source)
    source=source.replace('$fetch ??= static function', '$fetch = $fetch ?? static function')
    # Tests and minimal shared hosts need a UTF-8 length fallback without mbstring.
    source=source.replace("mb_strlen($title, 'UTF-8')", "\\mucho7_news_length($title)").replace("mb_strlen($body, 'UTF-8')", "\\mucho7_news_length($body)")
    record(path,'../news/'+name+'.php',source)
statements=[]
for name in ['20261016_001_news_board.php','20261016_002_native_news_state.php','20261017_001_news_media.php']:
    path='database/migrations/'+name
    manifest[path]=hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
    statements+=re.findall(r"<<<'SQL'\n(.*?)\nSQL",(ROOT/path).read_text(),re.S)
(news_dest/'schema.php').write_text("<?php\n/** Original additive News migrations, generated for PHP7. */\nreturn array(\n"+"\n,\n".join("<<<'SQL'\n"+s+"\nSQL" for s in statements)+"\n);\n")
source=(ROOT/'public/admin/actions/news.php').read_text()
source=re.sub(r"if \(\(string\)\(admin\(\)\['role'\].*?requireRank\(40\);",'',source,flags=re.S)
source=source.replace('new NewsService($db)', "new NewsService($db,$data['news_version'] ?? '')")
source=source.replace('$_POST','$data').replace('$_FILES','$files').replace("(int)(admin()['id'] ?? 0)",'$aid')
source=source.replace('mb_substr(', 'mucho7_ui_substr(')
source=source.replace("flash($error->getMessage(),'err');\n        return;", 'throw $error;')
source=re.sub(r"audit\(\$db, ('news\.\w+'), \(string\)\$id\);",r'mucho7_admin_audit($db,$aid,\1,$id);',source)
source=re.sub(r"    flash\(.*?\);\n",'',source)
record('public/admin/actions/news.php','news-actions.php',source)
# Original authenticator/recovery card and local QR renderer.
# End explicitly at the provisioning helper's closing brace.
security=index[index.index('function b32decode('):index.index("'&period=30';",index.index('function totpProvisioningUri('))+len("'&period=30';")]+"\n}\n"
security=security.replace('admin_recovery_codes','php7_admin_recovery_codes').replace('admin_user_id','account_id')
security=security.replace('function totp(string $secret,int $offset=0)', 'function totp(string $secret,int $offset=0,$now=null)').replace('intdiv(time(),30)+$offset','intdiv($now===null?time():$now,30)+$offset')
record('public/admin/index.php','security-source.php','<?php\n'+lower(security))
start=index.index('<div class="boxgrid">',index.index("elseif($page==='admins')"))
end=index.index("<?php\n$passkeyRows=",start)
card=index[start:end]
start=index.index('<div class="card admin-recovery-card"',end)
end=index.index("<?php\n$adminSetupInvite=",start)
card+=index[start:end]
# Require credentials again before creating a new factor, disabling it or
# replacing recovery codes. The original layout and classes stay intact.
password='<label class="admin-auth-field">Current password<input type="password" name="current_password" autocomplete="current-password" required></label>'
otp='<label class="admin-auth-field">2FA or recovery code<input name="otp" autocomplete="one-time-code" required></label>'
for action in ['2fa-generate','2fa-disable','recovery-generate']:
    needle='<input type="hidden" name="action" value="'+action+'">'
    card=card.replace(needle,needle+password+(otp if action!='2fa-generate' else ''))
card=card.replace("script.src='/admin/assets/qrcode.min.js?v=20260925'", "script.src="+"<?=json_encode(mucho7_admin_ui_url('/admin/assets/qrcode.min.js?v=20260925'))?>")
record('public/admin/index.php','admins.php','''<?php
$me=mucho7_admin_security_row($db,(int)$user['account_id']);
$me['username']=$user['username'];
$recoveryCodes=isset($_SESSION['new_recovery_codes'])?$_SESSION['new_recovery_codes']:array();
unset($_SESSION['new_recovery_codes']);
$unusedRecoveryCodes=countUnusedAdminRecoveryCodes($db,(int)$user['account_id']);
?>
'''+lower(card))
path='public/admin/assets/qrcode.min.js'
manifest[path]=hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
# Original RBAC implementation and the actual administrator/role forms.
source=(ROOT/'src/Admin/AdminRbac.php').read_text()
source=source.replace('private static ?bool $tablesReady = null;', 'private static $tablesReady = null;')
source=source.replace('private static array $permissionCache = [];', 'private static $permissionCache = [];')
source=source.replace('public const ', 'const ').replace('private const ', 'const ')
source=re.sub(r'\?(?:array|string) (\$\w+)',r'\1',source)
source=re.sub(r':\s*\?string\b','',source)
source=re.sub(r'catch\s*\(\\Throwable\)',r'catch (\\Throwable $error)',source)
source=source.replace('static fn($value): string => self::normalizePermission((string)$value)', 'static function($value): string { return self::normalizePermission((string)$value); }')
source=source.replace('static fn(string $value): bool => isset(self::PERMISSIONS[$value])', 'static function(string $value): bool { return isset(self::PERMISSIONS[$value]); }')
source=re.sub(r'return match \(\$code\) \{.*?\};', "return isset(array('owner'=>'Owner','admin'=>'Administrator','moderator'=>'Moderator','viewer'=>'Viewer')[$code])?array('owner'=>'Owner','admin'=>'Administrator','moderator'=>'Moderator','viewer'=>'Viewer')[$code]:$code;",source,flags=re.S)
# Authorize against current storage even when multiple actions run in a test or
# long-lived process; revocation must not be hidden by an old role cache.
source=re.sub(r'        if \(isset\(self::\$permissionCache\[\$code\]\)\) \{.*?\n        \}\n','',source,flags=re.S)
source=source.replace('self::$permissionCache[$code] = ', '')
source=source.replace('admin_role_permissions','php7_admin_role_permissions').replace('admin_roles','php7_admin_roles').replace('admin_users','php7_admin_users')
source=source.replace('private static $tablesReady = null;', 'private static $tablesReady = null;')
start=source.index('        if (self::$tablesReady !== null)')
end=source.index('    public static function roleExists',start)
source=source[:start]+"        return \\tableExists($db,'php7_admin_roles') && \\tableExists($db,'php7_admin_role_permissions');\n    }\n\n"+source[end:]
source=source.replace('mb_strlen(', '\\mucho7_admin_utf8_length(')
record('src/Admin/AdminRbac.php','AdminRbac.php',lower(source))
roles=index[index.index("elseif($page==='roles') {")+len("elseif($page==='roles') {"):index.index('/* =========================================================\n   ADMIN USERS + 2FA')].rstrip()
roles=roles[:roles.rfind('}')].replace('admin_role_permissions','php7_admin_role_permissions').replace('admin_roles','php7_admin_roles')
record('public/admin/index.php','roles.php',"<?php\nuse MuchoCore\\Admin\\AdminRbac;\n"+lower(roles))
start=index.index("<?php\n$adminSetupInvite=")
end=index.index('/* =========================================================\n   AUDIT',start)
management=index[start:end].rstrip()
management=management[:management.rfind('}')]
start=management.index("<?php\n$newRecoveryCodes=")
end=management.index("<?php\n\n$admins=",start)
management=management[:start]+management[end:]
management=management.replace('FROM admin_users','FROM php7_admin_users')
management=management.replace('SELECT id,username,role,is_active,\n            totp_secret,created_at', "SELECT u.id,COALESCE(a.username,u.username) AS username,u.role,u.is_active,u.created_at,s.totp_secret")
management=management.replace('FROM php7_admin_users\n     ORDER BY id', 'FROM php7_admin_users u LEFT JOIN accounts a ON a.account_id=u.account_id LEFT JOIN php7_admin_security s ON s.account_id=u.id\n     ORDER BY u.id')
management=management.replace('onclick="navigator.clipboard?.writeText(this.previousElementSibling.value);this.textContent=\'Copied\';setTimeout(()=>this.textContent=\'Copy link\',1200)"', 'data-copy-invite')
management=management.replace("(int)admin()['id']", "(int)admin()['account_id']")
# Add role reassignment using the same original table/form classes.
needle="        echo '<form method=\"post\">"
management=management.replace(needle,"""        echo '<form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="admin-role"><input type="hidden" name="id" value="'.h($a['id']).'"><select name="role" aria-label="Role for '.h($a['username']).'">';
        foreach(AdminRbac::roles($db) as $code=>$name)if(AdminRbac::canAssignRole($db,admin(),$code))echo '<option value="'.h($code).'"'.($code===$a['role']?' selected':'').'>'.h($name).'</option>';
        echo '</select><button>Save role</button></form>';
"""+needle)
record('public/admin/index.php','admin-management.php',"<?php use MuchoCore\\Admin\\AdminRbac; if(mucho7_admin_registry_ready($db) && canPermission('admins.manage')): ?>\n"+lower(management)+"\nendif; ?>")
# Preserve the dedicated original password setup document.
start=index.index('<!doctype html>',index.index('function muchAdminRenderPasswordSetup'))
end=index.index("<?php\n    exit;",start)
setup=index[start:end].replace('href="/admin/"','href="<?=h($script)?>"')
setup=setup.replace('<input type="hidden" name="setup"','<input type="hidden" name="action" value="admin-password-setup"><input type="hidden" name="setup"')
record('public/admin/index.php','password-setup.php',setup)

# Database Backup Center: same shared-host service and original page layout.
source=(ROOT/'src/Backup/DatabaseBackupService.php').read_text()
source=source.replace('private const ', 'const ')
source=re.sub(r'    public function __construct\(.*?\n    \}', '    private $pdo;\n    private $directory;\n    public function __construct(PDO $pdo,string $directory) { $this->pdo=$pdo;$this->directory=$directory; }',source,count=1,flags=re.S)
source=source.replace('fn(string $column): string => $this->quoteIdentifier($column)', 'function(string $column): string { return $this->quoteIdentifier($column); }')
source=re.sub(r'catch\s*\(Throwable\)',r'catch (Throwable $error)',source)
source=source.replace('mixed $value','$value')
source=source.replace('str_contains(', '\\mucho7_admin_contains(')
source=source.replace("gmdate('Ymd_His') . '.sql.gz'", "gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql.gz'")
source=source.replace('mkdir($this->directory, 0750, true)','mkdir($this->directory, 0700, true)')
source=source.replace("$gzip = gzopen($temporary, 'wb9');", "$gzip = gzopen($temporary, 'wb6');\n            @chmod($temporary,0600);")
source=source.replace('$this->pdo->beginTransaction();', "$this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');")
source=source.replace('$startedTransaction && $this->pdo->inTransaction()', '$startedTransaction')
source=source.replace('$this->pdo->commit();', "$this->pdo->exec('COMMIT');")
source=source.replace('$this->pdo->rollBack();', "$this->pdo->exec('ROLLBACK');")
source=source.replace('$this->write($gzip, "\\n");\n        } finally {', '$query->closeCursor();\n            $this->write($gzip, "\\n");\n        } finally {')
source=source.replace('$this->verifyChecksum($final, $checksumFile);', '@chmod($checksumFile,0600);\n            $this->verifyChecksum($final, $checksumFile);')
record('src/Backup/DatabaseBackupService.php','DatabaseBackupService.php',lower(source))
source=(ROOT/'public/admin/db-backup-center-module.php').read_text()
source=source[source.index('function renderDbBackupCenter('):]
source=re.sub(r"\$canManage=\s*admin\(\) &&\s*rank\(\s*\(string\)admin\(\)\['role'\]\s*\)>=30;", "$canManage=canPermission('backups.download');",source)
source=re.sub(r"\$isOwner=\s*admin\(\) &&\s*rank\(\s*\(string\)admin\(\)\['role'\]\s*\)>=40;", "$isOwner=admin() && admin()['role']==='owner';",source)
source=source.replace('static fn($a,$b)=>\n            $b[\'mtime\'] <=>\n            $a[\'mtime\']', "static function($a,$b){return $b['mtime'] <=> $a['mtime'];}")
source=source.replace('3 automatic backups/day ·\n            14-day retention ·', 'Manual backups ·')
source=source.replace('14 days', 'Manual')
record('public/admin/db-backup-center-module.php','dbbackups.php','<?php\n'+lower(source))
(DEST/'upstream-sources.json').write_text(json.dumps(manifest,indent=2)+'\n')
print('Original admin mirrored:',len(manifest),'upstream sources')
