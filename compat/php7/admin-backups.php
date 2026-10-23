<?php
/** Native shared-host operations for the original Database Backup Center. */
declare(strict_types=1);
require_once __DIR__.'/admin-ui/DatabaseBackupService.php';
require_once __DIR__.'/admin-ui/dbbackups.php';
function mucho7_admin_contains($haystack,$needle){return $needle===''||strpos($haystack,$needle)!==false;}
function muchodbSharedHosting(){return true;}
function muchoDbBackupDir(){return $GLOBALS['root'].'/config/php7-db-backups';}
function muchoDbBackupValidName($name){return is_string($name)&&preg_match('/^[A-Za-z0-9_.-]+\.sql\.gz$/D',$name)===1;}
function mucho7_admin_backup_file($name){
    if(!muchoDbBackupValidName($name))throw new RuntimeException('Invalid backup filename.');
    $path=muchoDbBackupDir().'/'.$name;
    if(!is_file($path)||is_link($path)||!is_file($path.'.sha256')||is_link($path.'.sha256'))throw new RuntimeException('Backup not found.');
    $line=file_get_contents($path.'.sha256',false,null,0,4096);$parts=preg_split('/\s+/',trim((string)$line));
    $actual=hash_file('sha256',$path);
    if(!preg_match('/^[a-f0-9]{64}$/D',$parts[0])||!is_string($actual)||!hash_equals($parts[0],$actual))throw new RuntimeException('Backup checksum verification failed.');
    return $path;
}
function mucho7_admin_backup_action(PDO $db,$aid,$action,$data){
    $actor=mucho7_admin_user($db,$aid);
    if(!mucho7_admin_can($db,$actor,'backups.download'))throw new RuntimeException('Insufficient permissions.');
    if($action==='dbbackup-create'){
        $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        $service=new \MuchoCore\Backup\DatabaseBackupService($db,muchoDbBackupDir());
        $result=$service->create($name!==''?$name:'muchocore');
        mucho7_admin_audit($db,$aid,'database.backup_create',0,basename($result['file']));return;
    }
    if($action==='dbbackup-download'){
        $path=mucho7_admin_backup_file(isset($data['file'])?$data['file']:'');
        mucho7_admin_audit($db,$aid,'database.backup_download',0,basename($path));
        header('Content-Type: application/gzip');header('Content-Disposition: attachment; filename="'.basename($path).'"');
        header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');
        readfile($path);return true;
    }
    throw new RuntimeException('Restore and server-side backup deletion are VPS operations. Use the verified download with your hosting database tool.');
}
