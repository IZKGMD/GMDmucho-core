<?php
// Test-only bootstrap: NEVER use an installation database.
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
    if (str_starts_with($class,'MuchoCore\\')) {
        $file=__DIR__.'/../../src/'.str_replace('\\','/',substr($class,10)).'.php';
        if(is_file($file)) require $file;
    }
});
$dsn=getenv('MUCHO_GO_PHP_TEST_DSN')?:'';
if(!preg_match('/;dbname=muchocore_go_(?:core_)?test(?:;|$)/',$dsn)) throw new RuntimeException('Disposable test database required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) throw new RuntimeException('Empty test database required');
(new MuchoCore\Database\Migrator($db,__DIR__.'/../../database/migrations'))->migrate();
if($export=getenv('MUCHO_GO_EXPORT_SCHEMA')){
    $ddl=[];$seed=[];
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');
        $ddl[]=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        foreach($db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row){
            // Let the destination initialize bookkeeping timestamps itself.
            unset($row['created_at'],$row['updated_at'],$row['applied_at']);
            $columns=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($row)));
            $values=implode(',',array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row)));
            $seed[]="INSERT INTO `$table` ($columns) VALUES ($values)";
        }
    }
    file_put_contents($export,json_encode(['tables'=>$ddl,'seed'=>$seed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}
