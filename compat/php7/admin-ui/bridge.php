<?php
/** Host adapter for the ORIGINAL admin templates. PHP 7.0 syntax only. */
declare(strict_types=1);
function h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf(){return $_SESSION['csrf'];}
function admin(){return $GLOBALS['user'];}
function adminIsPlain(){return isset($_GET['ui']) && $_GET['ui']==='plain';}
function canAdminPage($page){
    $user=admin();if(!$user)return false;
    $pages=array('dashboard','players','levels','comments','messages','rating','moderation','songs','news','settings','system','audit','dbbackups','admins');
    if(!in_array($page,$pages,true))return false;
    if(in_array($page,array('dashboard','players','levels','comments','moderation','system','admins'),true))return true;
    if($page==='rating')return in_array($user['role'],array('owner','elder_moderator'),true);
    return $user['role']==='owner';
}
function canPermission($permission){
    $user=admin();if(!$user)return false;
    if($permission==='players.delete')return false;
    if(in_array($permission,array('levels.manage','levels.rate'),true))return in_array($user['role'],array('owner','elder_moderator'),true);
    return $user['role']==='owner';
}
function requireRank($rank){$user=admin();$ranks=array('moderator'=>10,'elder_moderator'=>20,'owner'=>30);if(!$user||$ranks[$user['role']]<$rank)throw new RuntimeException('Нет доступа.');}
function tableExists(PDO $db,$table){if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$q=$db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute(array($table));return (bool)$q->fetchColumn();}$q=$db->prepare('SHOW TABLES LIKE ?');$q->execute(array($table));return (bool)$q->fetchColumn();}
function countTable(PDO $db,$table){if(!in_array($table,array('accounts','levels','songs'),true))return 0;return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
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
    $html=preg_replace_callback('~\b(href|src|action|data-face-url)=(["\'])(.*?)\2~s',function($match){return $match[1].'='.$match[2].h(mucho7_admin_ui_url(html_entity_decode($match[3],ENT_QUOTES,'UTF-8'))).$match[2];},$html);
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
