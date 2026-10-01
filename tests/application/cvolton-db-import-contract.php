<?php

declare(strict_types=1);

$cli=file_get_contents(
    dirname(__DIR__,2).'/bin/import-cvolton-db.php'
);

if($cli===false){
    throw new RuntimeException('Unable to read Cvolton DB importer CLI.');
}

foreach([
    "SET SESSION TRANSACTION READ ONLY",
    "--confirm=COVOLTON",
    "CVOLTON_SOURCE_PASS",
    "invalid source database name",
    "createVerifiedTargetBackup",
    "mucho-db-backup.sh",
    "TARGET_BACKUP=",
] as $needle){
    if(strpos($cli,$needle)===false){
        throw new RuntimeException(
            'Cvolton DB importer safety contract missing: '.$needle
        );
    }
}

$source=file_get_contents(
    dirname(__DIR__,2).'/src/Migration/CvoltonDatabaseImporter.php'
);

if($source===false){
    throw new RuntimeException('Unable to read Cvolton importer.');
}

if(preg_match('/(?:\$source|\$sql)[^\n]{0,80}SELECT[^\n]*passwordHash|passwordHash\s+AS\s+password/i',$source)){
    throw new RuntimeException(
        'Cvolton importer must not reference a generic plaintext password field.'
    );
}

if(strpos($source,"password_hash(")===false){
    throw new RuntimeException(
        'Cvolton importer must hash unusable credentials.'
    );
}

if(
    strpos($source,"sourceTable('platscores')")===false ||
    strpos($source,"WHERE ID > :last")===false
){
    throw new RuntimeException(
        'Cvolton platformer score import must follow platscores.ID.'
    );
}

$kit=file_get_contents(
    dirname(__DIR__,2).'/tools/migration/mucho-migrate.sh'
);

if($kit===false){
    throw new RuntimeException('Unable to read Migration Kit.');
}

if(strpos($kit,'--apply')===false || strpos($kit,'--confirm=COVOLTON')===false){
    throw new RuntimeException('Migration Kit apply contract is missing.');
}

if(
    strpos($kit,'TARGET_BACKUP=')===false ||
    strpos($kit,'--apply')===false
){
    throw new RuntimeException(
        'Migration Kit must require a verified target backup before apply.'
    );
}

echo "cvolton-db-import-contract: OK
";
