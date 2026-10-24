<?php
/** Host adapter for the ORIGINAL admin templates. PHP 7.0 syntax only. */
declare(strict_types=1);
function h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf(){return $_SESSION['csrf'];}
function admin(){return $GLOBALS['user'];}
function adminIsPlain(){return (isset($_GET['ui']) && $_GET['ui']==='plain') || (isset($_POST['ui']) && $_POST['ui']==='plain');}
function canAdminPage($page){
    $user=admin();if(!$user)return false;
    $pages=array('dashboard','players','levels','comments','messages','rating','moderation','songs','news','settings','system','audit','dbbackups','admins','roles','clans','contentpacks','social','plugins','muchoprofiles','analytics','database','endpoints','intelligence','clientfeatures','clientpatcher','securitycenter','monitoring');
    if(!in_array($page,$pages,true))return false;
    if(isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && mucho7_admin_registry_ready($GLOBALS['pdo'])){
        if($page==='news')return $user['role']==='owner';
        if($page==='admins')return mucho7_admin_can($GLOBALS['pdo'],$user,'self.security')||mucho7_admin_can($GLOBALS['pdo'],$user,'admins.manage');
        return mucho7_admin_can($GLOBALS['pdo'],$user,\MuchoCore\Admin\AdminRbac::permissionForContext(null,$page,0));
    }
    if(in_array($page,array('dashboard','players','levels','comments','moderation','system','admins'),true))return true;
    if($page==='rating')return in_array($user['role'],array('owner','elder_moderator'),true);
    return $user['role']==='owner';
}
function canPermission($permission){
    $user=admin();if(!$user)return false;
    if(!isset(mucho7_admin_supported_permissions()[$permission]))return false;
    if(isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && mucho7_admin_registry_ready($GLOBALS['pdo']))return mucho7_admin_can($GLOBALS['pdo'],$user,$permission);
    if($permission==='players.delete')return false;
    if(in_array($permission,array('levels.manage','levels.rate'),true))return in_array($user['role'],array('owner','elder_moderator'),true);
    return $user['role']==='owner';
}
function requireRank($rank){
    $user=admin();
    if(isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && mucho7_admin_registry_ready($GLOBALS['pdo'])){
        $permission=\MuchoCore\Admin\AdminRbac::permissionForContext(isset($_POST['action'])&&is_string($_POST['action'])?$_POST['action']:null,isset($GLOBALS['page'])?$GLOBALS['page']:null,$rank);
        if(!mucho7_admin_can($GLOBALS['pdo'],$user,$permission))throw new RuntimeException('Insufficient permissions.');return;
    }
    $ranks=array('moderator'=>10,'elder_moderator'=>20,'owner'=>40,'admin'=>30,'viewer'=>10);if(!$user||!isset($ranks[$user['role']])||$ranks[$user['role']]<$rank)throw new RuntimeException('Нет доступа.');
}
function requirePermission($permission){if(!canPermission($permission))throw new RuntimeException('Insufficient permissions.');}
function mucho7_admin_supported_permissions(){
    $keys=array('dashboard.view','players.view','players.manage','players.password_reset','levels.view','levels.manage','levels.rate','moderation.manage','comments.manage','messages.manage','music.manage','backups.view','backups.download','settings.manage','system.manage','admins.manage','roles.manage','audit.view','self.security','social.manage','contentpacks.manage','plugins.view','analytics.view','database.view','tools.endpoint_test','monitoring.view','client.manage','security.view');
    $all=\MuchoCore\Admin\AdminRbac::permissions();$result=array();foreach($keys as $key)$result[$key]=$all[$key];
    $result['moderation.manage']='View moderation queue';$result['system.manage']='View host diagnostics';$result['backups.download']='Create and download database backups';
    return $result;
}
function mucho7_admin_utf8_length($value,$encoding='UTF-8'){return function_exists('mb_strlen')?mb_strlen($value,$encoding):preg_match_all('/./us',$value,$unused);}
function tableExists(PDO $db,$table){if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$q=$db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute(array($table));return (bool)$q->fetchColumn();}$q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute(array($table));return (bool)$q->fetchColumn();}
function countTable(PDO $db,$table){if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)||!tableExists($db,$table))return 0;return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function mucho7_admin_difficulty($row){
    if(!empty($row['auto_level']))return 'auto';
    $value=(int)$row['difficulty'];if($value>=10)$value=(int)($value/10);
    switch($value){case 1:return 'easy';case 2:return 'normal';case 3:return 'hard';case 4:return 'harder';case 5:case 6:return 'insane';default:return 'unrated';}
}
function adminDifficultyProfile($row){
    if(!empty($row['auto_level']))return 'auto';
    if(!empty($row['demon'])){switch((int)$row['demon_difficulty']){case 1:case 3:return 'easy-demon';case 2:case 4:return 'medium-demon';case 5:return 'insane-demon';case 6:return 'extreme-demon';default:return 'hard-demon';}}
    return mucho7_admin_difficulty($row);
}
require_once __DIR__.'/list-ui.php';
require_once __DIR__.'/AdminChrome.php';
function mucho7_admin_ui_url($url){
    $script=$GLOBALS['script'];$public=$GLOBALS['publicBase'];
    if(strpos($url,'/admin/assets/')===0)return $public.$url;
    if(strpos($url,'/admin/')===0 && !preg_match('~^/admin/(?:plain/)?(?:\?|$)~',$url))return $public.$url;
    if(strpos($url,'/assets/')===0)return $public.$url;
    if(strpos($url,'/muchocore-theme.')===0)return $public.$url;
    if(strpos($url,'/admin/plain/')===0){$rest=substr($url,13);return $script.'?ui=plain'.($rest!==''?'&'.ltrim($rest,'?'):'');}
    if(strpos($url,'/admin/')===0){$rest=substr($url,7);return $script.($rest!==''?$rest:'?page=dashboard');}
    return $url;
}
function mucho7_admin_ui_html($html){
    $plain=adminIsPlain();$nonce=$GLOBALS['adminNonce'];$styles=array();
    $html=preg_replace_callback('~\s+onsubmit="return confirm\(\x27([^\x27]*)\x27\)"~',function($match){return ' data-confirm="'.h($match[1]).'"';},$html);
    $html=preg_replace_callback('~\b(href|src|action|data-face-url)=(["\'])(.*?)\2~s',function($match){
        $url=mucho7_admin_ui_url(html_entity_decode($match[3],ENT_QUOTES,'UTF-8'));
        if(preg_match('~\.(?:css|js)(?:\?|$)~',$url) && strpos($url,$GLOBALS['publicBase'].'/')===0)$url.=(strpos($url,'?')===false?'?':'&').'mcui=20261023-roles4';
        return $match[1].'='.$match[2].h($url).$match[2];
    },$html);
    // Keep the upstream inline layout exactly, but authorize it with a nonce.
    $html=preg_replace_callback('~<([A-Za-z][^<>]*?)\sstyle=(["\'])(.*?)\2([^<>]*?)>~s',function($match)use(&$styles){
        $id='mucho7-layout-'.count($styles);$styles[]='#'.$id.'{'.str_replace('</','<\\/',html_entity_decode($match[3],ENT_QUOTES,'UTF-8')).'}';
        if(preg_match('~\bid=(["\'])(.*?)\1~',$match[1].$match[4],$old)){
            $styles[count($styles)-1]='#'.preg_replace('/[^A-Za-z0-9_-]/','',$old[2]).'{'.html_entity_decode($match[3],ENT_QUOTES,'UTF-8').'}';
            return '<'.$match[1].$match[4].'>';
        }
        return '<'.$match[1].' id="'.$id.'"'.$match[4].'>';
    },$html);
    $html=preg_replace('~<(script|style)(?![^>]*\bnonce=)([^>]*)>~','<$1 nonce="'.h($nonce).'"$2>',$html);
    if($styles)$html=str_replace('</head>','<style nonce="'.h($nonce).'">'.implode("\n",$styles).'</style></head>',$html);
    if($plain){
        // Upstream list/reset/edit links use /admin/. Keep the chosen native
        // mode while leaving the explicit Styled switch free to change it.
        $html=preg_replace_callback('~<a\b[^>]*>~s',function($tag){
            if(strpos($tag[0],'data-styled-admin')!==false)return $tag[0];
            return preg_replace_callback('~\bhref=(["\'])(.*?)\1~s',function($attribute){
                $url=html_entity_decode($attribute[2],ENT_QUOTES,'UTF-8');
                if(strpos($url,$GLOBALS['script'].'?')!==0 && strpos($url,'?')!==0)return $attribute[0];
                $parts=explode('#',$url,2);
                if(!preg_match('~(?:[?&])ui=~',$parts[0]))$parts[0].='&ui=plain';
                return 'href='.$attribute[1].h(implode('#',$parts)).$attribute[1];
            },$tag[0]);
        },$html);
        $html=preg_replace('~<style\b[^>]*>.*?</style>~s','',$html);
        $html=preg_replace('~<link\b[^>]*rel="stylesheet"[^>]*>~','',$html);
        $html=str_replace('<table','<table border="1" cellpadding="6"',$html);
        $html=preg_replace('~(<form\b[^>]*>)~','$1<input type="hidden" name="ui" value="plain">',$html);
    }
    return $html;
}
function mucho7_admin_h($value){return h($value);}
function mucho7_admin_csrf(){echo '<input type="hidden" name="csrf" value="'.h(csrf()).'">';}
function mucho7_admin_hidden($name,$value){echo '<input type="hidden" name="'.h($name).'" value="'.h($value).'">';}
function mucho7_admin_select($name,$options,$selected){echo '<select name="'.h($name).'" aria-label="'.h($name).'">';foreach($options as $key=>$label)echo '<option value="'.h($key).'"'.((string)$key===(string)$selected?' selected':'').'>'.h($label).'</option>';echo '</select>';}
function mucho7_ui_substr($value,$start,$length,$encoding='UTF-8'){return function_exists('mb_substr')?mb_substr($value,$start,$length,$encoding):substr($value,$start,$length);}
function mucho7_ui_upper($value,$encoding='UTF-8'){return function_exists('mb_strtoupper')?mb_strtoupper($value,$encoding):strtoupper($value);}
function mucho7_ui_trimwidth($value,$start,$width,$marker='',$encoding='UTF-8'){
    if(function_exists('mb_strimwidth'))return mb_strimwidth($value,$start,$width,$marker,$encoding);
    preg_match_all('/./us',$value,$chars);$chars=isset($chars[0])?$chars[0]:array();
    return implode('',array_slice($chars,$start,$width)).(count($chars)>$start+$width?$marker:'');
}
require_once __DIR__.'/../admin-registry.php';

function audit(PDO $db,$action,$target='',$details=array()){mucho7_admin_audit($db,(int)admin()['account_id'],$action,(int)$target,json_encode($details));}
function flash($text,$type='ok'){$_SESSION['notice']=(string)$text;if(in_array($type,array('err','error'),true))throw new RuntimeException((string)$text);}

function checkCsrf(){if(!isset($_POST['csrf'])||!is_string($_POST['csrf'])||!hash_equals(csrf(),$_POST['csrf']))throw new RuntimeException('Форма устарела.');}
function rank($role){$ranks=array('owner'=>40,'admin'=>30,'elder_moderator'=>20,'moderator'=>10,'viewer'=>0);return isset($ranks[$role])?$ranks[$role]:0;}
function mucho7_admin_header($value){if(stripos($value,'Location:')===0)$value='Location: '.mucho7_admin_ui_url(trim(substr($value,9)));header($value);}
