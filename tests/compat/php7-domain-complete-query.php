<?php
/** One status-query regression, no database server required. */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/compat/php7/domain-data.php';
class OneQueryPdo extends PDO {
    public $count=0;
    public $stage=0;
    public $throwMissing=false;
    public $throwOther=false;
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs) {
        $this->count++;
        if(strpos($query,'information_schema')!==false)throw new RuntimeException('Expensive schema introspection on request path');
        if($this->throwMissing){$exception=new PDOException('table missing');$exception->errorInfo=array('42S02',1146,'missing');throw $exception;}
        if($this->throwOther){$exception=new PDOException('connection lost');$exception->errorInfo=array('HY000',2006,'gone away');throw $exception;}
        return new OneQueryStatement($this->stage);
    }
}
class OneQueryStatement extends PDOStatement {
    private $value;
    public function __construct($value){$this->value=$value;}
    #[\ReturnTypeWillChange]
    public function fetchColumn($column=0) {return $this->value;}
}
$pdo=new OneQueryPdo();
$pdo->stage=count(mucho7_domain_import_stages());
if(!mucho7_domain_complete($pdo)||$pdo->count!==1)throw new RuntimeException('Complete core should need one query');
$pdo->stage=0;
if(mucho7_domain_complete($pdo)||$pdo->count!==2)throw new RuntimeException('Incomplete core returned true');
$pdo->throwMissing=true;
if(mucho7_domain_complete($pdo))throw new RuntimeException('Missing state table returned true');
$pdo->throwMissing=false;
$pdo->throwOther=true;
$rejected=false;try{mucho7_domain_complete($pdo);}catch(PDOException $error){$rejected=isset($error->errorInfo[1]) && (int)$error->errorInfo[1]===2006;}
if(!$rejected)throw new RuntimeException('Unrelated SQL errors must fail closed');
echo "PHP7_SINGLE_QUERY_SCHEMA_STATUS_OK\n";
