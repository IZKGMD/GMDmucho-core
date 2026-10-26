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
    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false {
        $this->count++;
        if(strpos($query,'information_schema')!==false)throw new RuntimeException('Expensive schema introspection on request path');
        if($this->throwMissing){$exception=new PDOException('table missing','42S02');$exception->errorInfo=array('42S02',1146,'missing');throw $exception;}
        if($this->throwOther){$exception=new PDOException('connection lost','HY000');$exception->errorInfo=array('HY000',2006,'gone away');throw $exception;}
        return new OneQueryStatement($this->stage);
    }
}
class OneQueryStatement extends PDOStatement {
    private $value;
    public function __construct($value){$this->value=$value;}
    public function fetchColumn(int $column=0): mixed {return $this->value;}
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
$rejected=false;try{mucho7_domain_complete($pdo);}catch(PDOException $error){$rejected=$error->getCode()==='HY000';}
if(!$rejected)throw new RuntimeException('Unrelated SQL errors must fail closed');
echo "PHP7_SINGLE_QUERY_SCHEMA_STATUS_OK\n";
