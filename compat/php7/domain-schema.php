<?php
/** Resumable original migrations over an existing native database. PHP 7.0+. */
declare(strict_types=1);

/** Split ALTER clauses without splitting column types, expressions or strings. */
function mucho7_domain_sql_clauses($sql)
{
    $clauses=array();$start=0;$depth=0;$quote='';$length=strlen($sql);
    for($index=0;$index<$length;$index++){
        $character=$sql[$index];
        if($quote!==''){
            if($character==='\\' && $quote!=='`'){$index++;continue;}
            if($character===$quote){if($index+1<$length && $sql[$index+1]===$quote){$index++;continue;}$quote='';}
            continue;
        }
        if($character==='\'' || $character==='"' || $character==='`'){$quote=$character;continue;}
        if($character==='(')$depth++;
        elseif($character===')')$depth--;
        elseif($character===',' && $depth===0){$clauses[]=trim(substr($sql,$start,$index-$start));$start=$index+1;}
    }
    $clauses[]=trim(substr($sql,$start));return $clauses;
}
function mucho7_domain_schema_member(PDO $db,$table,$name,$index=false)
{
    $query=$db->prepare($index
        ? 'SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1'
        : 'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $query->execute(array($table,$name));return (bool)$query->fetchColumn();
}
/** Normalize additive DDL for native columns already supplied by older builds. */
function mucho7_domain_schema_exec(PDO $db,$sql)
{
    $sql=trim($sql);
    if(preg_match('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i',$sql))$sql=preg_replace('/^CREATE\s+TABLE\s+/i','CREATE TABLE IF NOT EXISTS ',$sql,1);
    if(preg_match('/^INSERT\s+INTO\s+`?(roles|custom_difficulties|custom_ratings|server_settings)`?\s/i',$sql))$sql=preg_replace('/^INSERT\s+INTO/i','INSERT IGNORE INTO',$sql,1);
    if(!preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is',$sql,$alter))return $db->exec($sql);
    $total=0;$table=$alter[1];
    foreach(mucho7_domain_sql_clauses(rtrim($alter[2],';')) as $clause){
        if(preg_match('/^ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s+/i',$clause,$column)
            && !in_array(strtoupper($column[1]),array('PRIMARY','UNIQUE','KEY','INDEX','CONSTRAINT','FOREIGN','CHECK'),true)){
            if(mucho7_domain_schema_member($db,$table,$column[1]))continue;
            $clause=preg_replace('/\bIF\s+NOT\s+EXISTS\s+/i','',$clause,1);
        }elseif(preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?/i',$clause,$key)){
            if(mucho7_domain_schema_member($db,$table,$key[1],true))continue;
        }elseif(preg_match('/^ADD\s+PRIMARY\s+KEY\b/i',$clause)){
            if(mucho7_domain_schema_member($db,$table,'PRIMARY',true))continue;
        }elseif(preg_match('/^DROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',$clause,$column)){
            if(!mucho7_domain_schema_member($db,$table,$column[1]))continue;
            $clause=preg_replace('/\bIF\s+EXISTS\s+/i','',$clause,1);
        }elseif(preg_match('/^DROP\s+(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?/i',$clause,$key)){
            if(!mucho7_domain_schema_member($db,$table,$key[1],true))continue;
        }
        $total+=(int)$db->exec('ALTER TABLE `'.$table.'` '.$clause);
    }
    return $total;
}
function mucho7_domain_migration_files()
{
    $files=glob(__DIR__.'/core/database/migrations/*.php');sort($files,SORT_STRING);return $files;
}
/** Owner-triggered only; no DDL or data migration during ordinary API reads. */
function mucho7_domain_upgrade(PDO $db,$batch=8)
{
    $db->exec('CREATE TABLE IF NOT EXISTS php7_core_migrations (
        version VARCHAR(255) NOT NULL PRIMARY KEY,
        source_sha256 CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $lock='mucho7:core:'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn());
    $query=$db->prepare('SELECT GET_LOCK(?,0)');$query->execute(array($lock));
    if((int)$query->fetchColumn()!==1)throw new RuntimeException('Core upgrade is already running.');
    $applied=0;$started=microtime(true);$files=mucho7_domain_migration_files();
    try{
        $done=$db->query('SELECT version,source_sha256 FROM php7_core_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $sources=json_decode(file_get_contents(__DIR__.'/core/sources.json'),true);
        foreach($files as $file){
            $version=basename($file,'.php');$source='database/migrations/'.basename($file);
            if(!isset($sources[$source]))throw new RuntimeException('Migration provenance is unavailable.');
            if(isset($done[$version])){
                if(!hash_equals($done[$version],$sources[$source]))throw new RuntimeException('An applied migration changed.');
                continue;
            }
            if($applied>=max(1,(int)$batch) || microtime(true)-$started>12)break;
            $migration=require $file;
            if(is_array($migration))foreach($migration as $sql)mucho7_domain_schema_exec($db,$sql);
            elseif($migration instanceof \MuchoCore\Database\Migration)$migration->up($db);
            elseif(is_callable($migration))$migration($db);
            else throw new RuntimeException('Invalid core migration.');
            $query=$db->prepare('INSERT INTO php7_core_migrations(version,source_sha256) VALUES(?,?)');
            $query->execute(array($version,$sources[$source]));$done[$version]=$sources[$source];$applied++;
        }
        return array('applied'=>$applied,'remaining'=>count($files)-count(array_intersect(array_keys($done),array_map(function($file){return basename($file,'.php');},$files))));
    }finally{
        $query=$db->prepare('SELECT RELEASE_LOCK(?)');$query->execute(array($lock));
    }
}
