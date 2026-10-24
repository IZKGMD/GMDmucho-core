<?php
/** Authorized HTTP boundary for the shared-only client patcher. */
declare(strict_types=1);
require_once __DIR__.'/patcher/Job.php';
function mucho7_patcher_json($payload,$status=200)
{
    mucho7_admin_clean_response();http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
function mucho7_patcher_action($db,$user,$action,$root,$data)
{
    if(!mucho7_admin_can($db,$user,'client.manage')){http_response_code(403);throw new RuntimeException('Нет прав на патчер клиентов.');}
    if(!is_file($root.'/config/php7-complete.on'))throw new RuntimeException('Сначала заверши перенос ядра.');
    require_once $root.'/compat/php7/domain.php';$owner=(int)$user['account_id'];
    if(!method_exists('MuchoCore\\Client\\AndroidClientPatcher','patchEntryBytes'))throw new RuntimeException('Установи загруженную сборку в разделе «Сервер», чтобы включить shared-патчер.');
    $id=isset($_SESSION['shared_patcher_id'])?$_SESSION['shared_patcher_id']:'';
    if($action==='shared-patcher-start'){
        if($id!=='')throw new RuntimeException('Сначала сбрось предыдущую задачу патчера.');
        $size=isset($data['size'])&&is_string($data['size'])&&preg_match('/^[0-9]{1,10}$/D',$data['size'])?(int)$data['size']:0;
        $r=MuchoSharedPatcherJob::start($root,$owner,isset($data['filename'])&&is_string($data['filename'])?$data['filename']:'',$size,isset($data['server_url'])&&is_string($data['server_url'])?$data['server_url']:'',isset($data['alongside'])&&$data['alongside']==='1',isset($data['fingerprint'])&&is_string($data['fingerprint'])?$data['fingerprint']:'');
        $_SESSION['shared_patcher_id']=$r['id'];mucho7_admin_audit($db,$owner,'shared-patcher-start',0);return $r;
    }
    if($id===''){if($action==='shared-patcher-status')return array('ok'=>true,'phase'=>'idle');throw new RuntimeException('Задача патчера не найдена.');}
    $dir=MuchoSharedPatcherJob::directory($root,$id);$s=MuchoSharedPatcherJob::load($dir,$action==='shared-patcher-reset');MuchoSharedPatcherJob::owned($s,$owner);
    if($action==='shared-patcher-reset'){MuchoSharedPatcherJob::remove($dir);unset($_SESSION['shared_patcher_id']);return array('ok'=>true,'phase'=>'idle');}
    if($action==='shared-patcher-status')return MuchoSharedPatcherJob::status($s);
    if($action==='shared-patcher-chunk'){
        $f=isset($_FILES['chunk'])?$_FILES['chunk']:null;
        if(!is_array($f)||$f['error']!==UPLOAD_ERR_OK||$f['size']>2097152||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('Хостинг отклонил часть файла. Проверь лимит POST.');
        $offset=isset($data['offset'])&&is_string($data['offset'])&&preg_match('/^[0-9]{1,10}$/D',$data['offset'])?(int)$data['offset']:-1;
        return MuchoSharedPatcherJob::chunk($root,$owner,$id,$offset,(string)file_get_contents($f['tmp_name']),isset($data['sha256'])&&is_string($data['sha256'])?$data['sha256']:'');
    }
    if($action==='shared-patcher-step'){
        $r=MuchoSharedPatcherJob::step($root,$owner,$id);
        if($r['phase']==='done'&&$s['phase']!=='done')mucho7_admin_audit($db,$owner,'shared-patcher-complete',0,$r['report']['output_sha256']);return $r;
    }
    throw new RuntimeException('Неизвестное действие патчера.');
}
function mucho7_patcher_download($db,$user,$root)
{
    if(!$db||!$user||!mucho7_admin_can($db,$user,'client.manage')){http_response_code(403);exit;}
    $id=isset($_GET['shared_patcher_download'])&&is_string($_GET['shared_patcher_download'])?$_GET['shared_patcher_download']:'';
    if(!isset($_SESSION['shared_patcher_id'])||!hash_equals($_SESSION['shared_patcher_id'],$id)){http_response_code(404);exit;}
    $dir=MuchoSharedPatcherJob::directory($root,$id);$s=MuchoSharedPatcherJob::load($dir);MuchoSharedPatcherJob::owned($s,(int)$user['account_id']);
    if($s['phase']!=='done'){http_response_code(409);exit;}$file=$dir.'/result.'.$s['kind'];
    if(!is_file($file)||is_link($file)){http_response_code(404);exit;}
    $handle=fopen($file,'rb');if(!$handle)throw new RuntimeException('Result is unavailable.');
    $size=filesize($file);$first=0;$last=$size-1;$etag='"'.$s['report']['output_sha256'].'"';
    if(isset($_SERVER['HTTP_RANGE'])&&(!isset($_SERVER['HTTP_IF_RANGE'])||$_SERVER['HTTP_IF_RANGE']===$etag)){
        $range=$_SERVER['HTTP_RANGE'];
        if(!preg_match('/^bytes=([0-9]*)-([0-9]*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);fclose($handle);exit;}
        if($m[1]===''){$first=max(0,$size-(int)$m[2]);}else{$first=(int)$m[1];if($m[2]!=='')$last=min($last,(int)$m[2]);}
        if($first>$last||$first>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);fclose($handle);exit;}
        http_response_code(206);
    }
    session_write_close();
    // Use the normal stream output path without optional per-chunk flush or
    // connection-status functions, which shared PHP settings may disable.
    // Compression must not change the byte counts used by range responses.
    @ini_set('zlib.output_compression','0');mucho7_admin_clean_response();header('Content-Type: '.($s['kind']==='apk'?'application/vnd.android.package-archive':'application/octet-stream'));header('Content-Length: '.($last-$first+1));header('Content-Disposition: attachment; filename="GeometryDash-MuchoCore.'.$s['kind'].'"');header('Cache-Control: no-store, private, no-transform');header('X-Content-Type-Options: nosniff');header('Accept-Ranges: bytes');header('ETag: '.$etag);
    if(http_response_code()===206)header('Content-Range: bytes '.$first.'-'.$last.'/'.$size);
    if(isset($_SERVER['REQUEST_METHOD'])&&$_SERVER['REQUEST_METHOD']==='HEAD'){fclose($handle);exit;}
    fseek($handle,$first);
    if($last===$size-1){fpassthru($handle);}else{$remaining=$last-$first+1;while($remaining>0){$chunk=fread($handle,min(1048576,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}}
    fclose($handle);exit;
}
