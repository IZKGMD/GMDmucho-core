<?php

declare (strict_types=1);
use MuchoCore\Database\Migration;
return new class implements Migration
{
    public function up(PDO $pdo)
    {
        foreach (['demon_info' => "ALTER TABLE profiles ADD COLUMN demon_info TEXT NOT NULL DEFAULT ''", 'star_info' => "ALTER TABLE profiles ADD COLUMN star_info TEXT NOT NULL DEFAULT ''", 'platformer_info' => "ALTER TABLE profiles ADD COLUMN platformer_info TEXT NOT NULL DEFAULT ''", 'glow' => "ALTER TABLE profiles ADD COLUMN glow INT UNSIGNED NOT NULL DEFAULT 0"] as $column => $sql) {
            $exists = $pdo->query("SHOW COLUMNS FROM profiles LIKE " . $pdo->quote($column))->fetchColumn();
            if ($exists === false) {
                \mucho7_domain_schema_exec($pdo, $sql);
            }
        }
    }
    public function down(PDO $pdo)
    {
        \mucho7_domain_schema_exec($pdo, 'ALTER TABLE profiles
             DROP COLUMN IF EXISTS demon_info,
             DROP COLUMN IF EXISTS star_info,
             DROP COLUMN IF EXISTS platformer_info,
             DROP COLUMN IF EXISTS glow');
    }
};
