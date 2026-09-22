#!/usr/bin/env bash

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

DOMAIN="${MUCHO_TEST_BASE_URL:-http://127.0.0.1}"
PUBLIC="${MUCHO_PUBLIC_BASE_URL:-$DOMAIN}"
HOST="${MUCHO_TEST_HOST:-localhost}"

PASS=0
FAIL=0

RUN_ID="$(date +%s)_$RANDOM"
U1="V80A${RANDOM}${RANDOM}"
U2="V80B${RANDOM}${RANDOM}"
P1="V80_A_${RANDOM}_Password!"
P2="V80_B_${RANDOM}_Password!"
E1="${U1,,}@v80.local"
E2="${U2,,}@v80.local"

AID1=0
AID2=0
UID1=0
UID2=0
LEVEL_ID=0

TEST_IP="127.80.$((RANDOM%200+1)).$((RANDOM%200+1))"

pass() {
    echo "[PASS] $1"
    PASS=$((PASS+1))
}

fail() {
    echo "[FAIL] $1"
    FAIL=$((FAIL+1))
}

expect_eq() {
    local name="$1"
    local got="$2"
    local expected="$3"

    if [ "$got" = "$expected" ]; then
        pass "$name"
    else
        fail "$name :: expected=[$expected] got=[$got]"
    fi
}

expect_not() {
    local name="$1"
    local got="$2"
    local bad="$3"

    if [ -n "$got" ] && [ "$got" != "$bad" ]; then
        pass "$name"
    else
        fail "$name :: got=[$got]"
    fi
}

expect_contains() {
    local name="$1"
    local got="$2"
    local needle="$3"

    if [[ "$got" == *"$needle"* ]]; then
        pass "$name"
    else
        fail "$name :: missing=[$needle]"
    fi
}

post() {
    local endpoint="$1"
    shift

    curl -sS \
        --connect-timeout 5 \
        --max-time 20 \
        -H "Host: $HOST" \
        -H "CF-Connecting-IP: $TEST_IP" \
        -X POST \
        "$DOMAIN/database/${endpoint}.php" \
        "$@"
}

api_get() {
    local path="$1"

    curl -sS \
        --connect-timeout 5 \
        --max-time 20 \
        -H "Host: $HOST" \
        -H "CF-Connecting-IP: $TEST_IP" \
        "$DOMAIN$path"
}

cleanup() {
    echo
    echo "===== CLEANUP ====="

    U1="$U1" U2="$U2" php <<'PHP' || true
<?php
require 'vendor/autoload.php';

$db=\MuchoCore\V71\DatabaseBridge::pdo();

$names=[
    getenv('U1'),
    getenv('U2'),
];

$names=array_values(
    array_filter(
        $names,
        static fn($v)=>is_string($v) && $v!==''
    )
);

if(!$names){
    echo "CLEANUP=NOTHING\n";
    exit;
}

$marks=implode(',',array_fill(0,count($names),'?'));

$q=$db->prepare(
    "SELECT account_id
     FROM accounts
     WHERE username IN ($marks)"
);

$q->execute($names);

$ids=array_map(
    'intval',
    $q->fetchAll(PDO::FETCH_COLUMN)
);

if(!$ids){
    echo "CLEANUP=NO_ACCOUNTS\n";
    exit;
}

$db->exec("SET FOREIGN_KEY_CHECKS=0");

/* V80 cleanup public reports */
try {
    $marks=implode(
        ',',
        array_fill(0,count($ids),'?')
    );

    $q=$db->prepare(
        "SELECT level_id
         FROM levels
         WHERE account_id IN ($marks)"
    );

    $q->execute($ids);

    $levelIds=array_map(
        'intval',
        $q->fetchAll(PDO::FETCH_COLUMN)
    );

    if($levelIds){
        $levelMarks=implode(
            ',',
            array_fill(0,count($levelIds),'?')
        );

        $q=$db->prepare(
            "DELETE FROM mucho_level_reports
             WHERE level_id IN ($levelMarks)"
        );

        $q->execute($levelIds);
    }
} catch(Throwable){}

$tables=$db->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema=DATABASE()
")->fetchAll(PDO::FETCH_COLUMN);

foreach($tables as $table){

    if(in_array($table,['accounts','profiles'],true)){
        continue;
    }

    try {
        $cols=$db->query(
            "SHOW COLUMNS FROM `".$table."`"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch(Throwable){
        continue;
    }

    $targets=[];

    foreach($cols as $c){
        if(
            preg_match(
                '/account|sender|receiver|from_|to_|user_id|user1|user2/i',
                $c
            )
        ){
            $targets[]=$c;
        }
    }

    if(!$targets){
        continue;
    }

    $where=[];
    $params=[];

    foreach($targets as $ci=>$col){
        foreach($ids as $ii=>$id){
            $key='p_'.$ci.'_'.$ii;
            $where[]="`".$col."`=:".$key;
            $params[$key]=$id;
        }
    }

    if(!$where){
        continue;
    }

    try {
        $sql=
            "DELETE FROM `".$table."`

echo '[MuchoCore] Critical path checks'

grep -q "this->auth->authenticate(\$accountId, \$credential)" "$ROOT/src/CloudSave/CloudSaveService.php"
grep -q "authenticate(\$accountId, \$credential)" "$ROOT/public/api/v2/music-upload.php"
grep -q 'storage/music-public' "$ROOT/docker/app-entrypoint.sh"
grep -q 'handle_path /music/\*' "$ROOT/docker/Caddyfile"

echo 'PASS critical auth/storage checks'
