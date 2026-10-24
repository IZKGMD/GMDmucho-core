<?php

declare (strict_types=1);
namespace MuchoCore\Database;

use PDO;
interface Migration
{
    public function up(PDO $pdo);
    public function down(PDO $pdo);
}
