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

if(strpos($source,"passwordHash")!==false){
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
    strpos($source,"FROM platscores")===false ||
    strpos($source,"WHERE ID > :last")===false
){
    throw new RuntimeException(
        'Cvolton platformer score import must follow platscores.ID.'
    );
}

echo "cvolton-db-import-contract: OK
";
