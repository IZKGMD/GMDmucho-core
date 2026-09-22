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
             WHERE ".implode(' OR ',$where);

        $st=$db->prepare($sql);
        $st->execute($params);
    } catch(Throwable){}
}

$marks=implode(',',array_fill(0,count($ids),'?'));

try{
    $q=$db->prepare(
        "DELETE FROM profiles
         WHERE account_id IN ($marks)"
    );
    $q->execute($ids);
}catch(Throwable){}

try{
    $q=$db->prepare(
        "DELETE FROM accounts
         WHERE account_id IN ($marks)"
    );
    $q->execute($ids);
}catch(Throwable){}

$db->exec("SET FOREIGN_KEY_CHECKS=1");

echo "CLEANUP=OK\n";
PHP

    rm -f /tmp/mucho_v80_*
}

trap cleanup EXIT INT TERM

latest_pk() {
    TBL="$1" A="$2" B="$3" php <<'PHP'
<?php
require 'vendor/autoload.php';

$db=\MuchoCore\V71\DatabaseBridge::pdo();

$table=(string)getenv('TBL');
$a=(int)getenv('A');
$b=(int)getenv('B');

if(!preg_match('/^[a-zA-Z0-9_]+$/',$table)){
    exit;
}

$pk='';

foreach(
    $db->query("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'")
       ->fetchAll(PDO::FETCH_ASSOC)
    as $r
){
    $pk=(string)$r['Column_name'];
    break;
}

if($pk===''){
    exit;
}

$cols=$db->query(
    "SHOW COLUMNS FROM `$table`"
)->fetchAll(PDO::FETCH_COLUMN);

$targets=[];

foreach($cols as $c){
    if(
        preg_match(
            '/account|sender|receiver|from_|to_|user/i',
            (string)$c
        )
    ){
        $targets[]=(string)$c;
    }
}

$where=[];

foreach($targets as $c){
    $where[]=
        "(`$c`=".(int)$a.
        " OR `$c`=".(int)$b.")";
}

if(!$where){
    exit;
}

$sql=
    "SELECT `$pk`
     FROM `$table`
     WHERE ".implode(' OR ',$where)."
     ORDER BY `$pk` DESC
     LIMIT 1";

echo (string)$db->query($sql)->fetchColumn();
PHP
}

echo "========================================"
echo " MUCHOCORE V8.0 REGRESSION"
echo " RUN=$RUN_ID"
echo "========================================"

echo
echo "===== STATIC / SYNTAX ====="

for f in \
src/Core/Application.php \
src/Account/AccountController.php \
src/Level/LevelTransferController.php \
src/Interaction/CommentController.php \
src/Social/RelationshipController.php \
src/Social/MessageController.php \
src/Interaction/RewardsController.php \
src/Music/SongController.php
do
    if php -l "$f" >/dev/null 2>&1; then
        pass "PHP lint $f"
    else
        fail "PHP lint $f"
    fi
done

ROUTE_RESULT="$(
python3 <<'PY'
from pathlib import Path
import re

s=Path("src/Core/Application.php").read_text().lower()

routes=set(
    x.lower()
    for x in re.findall(
        r"\$route\(\s*'([^']+)'",
        s
    )
)

required={
"/registergjaccount",
"/logingjaccount",
"/backupgjaccount20",
"/syncgjaccount20",
"/getgjlevels21",
"/uploadgjlevel22",
"/downloadgjlevel22",
"/deletegjleveluser20",
"/updategjleveldesc20",
"/getgjsonginfo",
"/getgjcomments21",
"/uploadgjcomment21",
"/deletegjcomment20",
"/getgjaccountcomments20",
"/uploadgjacccomment20",
"/deletegjacccomment20",
"/likegjitem211",
"/uploadfriendrequest20",
"/getgjfriendrequests20",
"/acceptgjfriendrequest20",
"/removegjfriend20",
"/blockgjuser20",
"/unblockgjuser20",
"/getgjmessages20",
"/downloadgjmessage20",
"/uploadgjmessage20",
"/deletegjmessages20",
"/suggestgjstars20",
"/rategjstars211",
"/rategjdemon21",
"/reportgjlevel",
"/getgjdailyLevel".lower(),
"/getgjgauntlets21",
"/getgjmappacks21",
"/getgjlevelscores211",
"/getgjlevelscoresplat",
"/getgjrewards",
"/getgjchallenges",
}

missing=sorted(required-routes)

print("OK" if not missing else "MISSING:"+",".join(missing))
PY
)"

expect_eq "Required Application routes" "$ROUTE_RESULT" "OK"

for f in \
getGJLevelLists.php \
uploadGJLevelList.php \
deleteGJLevelList.php \
getGJCommentHistory.php \
getGJTopArtists.php \
getAccountURL.php \
getCustomContentURL.php
do
    if [ -f "public/database/$f" ]; then
        pass "Standalone $f"
    else
        fail "Standalone $f"
    fi
done

echo
echo "===== HEALTH ====="

H="$(
api_get /api/v2/health
)"

expect_contains "Local health" "$H" '"ok": true'

PH="$(
curl -sS \
    --connect-timeout 5 \
    --max-time 20 \
    "$PUBLIC/api/v2/health"
)"

expect_contains "Public health" "$PH" '"ok": true'

echo
echo "===== ACCOUNT REGISTER ====="

R1="$(
post registerGJAccount \
    -d "userName=$U1" \
    -d "password=$P1" \
    -d "email=$E1"
)"

R2="$(
post registerGJAccount \
    -d "userName=$U2" \
    -d "password=$P2" \
    -d "email=$E2"
)"

expect_not "Register account A" "$R1" "-1"
expect_not "Register account B" "$R2" "-1"

eval "$(
U1="$U1" U2="$U2" php <<'PHP'
<?php
require 'vendor/autoload.php';

$db=\MuchoCore\V71\DatabaseBridge::pdo();

foreach(['U1'=>getenv('U1'),'U2'=>getenv('U2')] as $k=>$name){
    $q=$db->prepare("
        SELECT
            a.account_id,
            COALESCE(p.user_id,a.account_id)
        FROM accounts a
        LEFT JOIN profiles p
          ON p.account_id=a.account_id
        WHERE a.username=?
        LIMIT 1
    ");

    $q->execute([$name]);
    $r=$q->fetch(PDO::FETCH_NUM);

    echo ($k==='U1'?'AID1':'AID2').
        '='.(int)($r[0]??0)."\n";

    echo ($k==='U1'?'UID1':'UID2').
        '='.(int)($r[1]??0)."\n";
}
PHP
)"

if [ "$AID1" -gt 0 ] && [ "$AID2" -gt 0 ]; then
    pass "Registered accounts exist in DB"
else
    fail "Registered accounts exist in DB"
fi

echo
echo "===== ACCOUNT LOGIN ====="

L1="$(
post loginGJAccount \
    -d "userName=$U1" \
    -d "password=$P1"
)"

L2="$(
post loginGJAccount \
    -d "userName=$U2" \
    -d "password=$P2"
)"

expect_not "Login A" "$L1" "-1"
expect_not "Login B" "$L2" "-1"

BAD_LOGIN="$(
post loginGJAccount \
    -d "userName=$U1" \
    -d 'password=V80_WRONG_PASSWORD'
)"

expect_eq "Reject wrong password" "$BAD_LOGIN" "-1"

echo
echo "===== USER CORE ====="

US="$(
post updateGJUserScore22 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d "userName=$U1" \
    -d 'stars=123' \
    -d 'demons=4' \
    -d 'diamonds=321' \
    -d 'coins=7' \
    -d 'userCoins=8' \
    -d 'icon=1' \
    -d 'color1=2' \
    -d 'color2=3' \
    -d 'iconType=0'
)"

expect_not "Update user score" "$US" "-1"

UI="$(
post getGJUserInfo20 \
    -d "targetAccountID=$AID1" \
    -d "accountID=$AID1"
)"

expect_contains "Get user info" "$UI" "$U1"

SEARCH_USER="$(
post getGJUsers20 \
    -d "str=$U1" \
    -d 'page=0'
)"

expect_contains "Search user" "$SEARCH_USER" "$U1"

echo
echo "===== LEVEL CORE ====="

DESC1="$(
printf 'V8 regression description A' |
base64 -w0
)"

DESC2="$(
printf 'V8 regression description B' |
base64 -w0
)"

LEVEL_ID="$(
post uploadGJLevel22 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d 'levelName=Mucho V80 Regression' \
    --data-urlencode "levelDesc=$DESC1" \
    -d 'levelString=MUCHO_V80_LEVEL_DATA_A' \
    -d 'levelVersion=1' \
    -d 'gameVersion=22' \
    -d 'binaryVersion=45' \
    -d 'levelLength=2' \
    -d 'objects=8080' \
    -d 'coins=3' \
    -d 'requestedStars=7'
)"

if [[ "$LEVEL_ID" =~ ^[0-9]+$ ]] && [ "$LEVEL_ID" -gt 0 ]; then
    pass "Upload level"
else
    fail "Upload level :: got=[$LEVEL_ID]"
    LEVEL_ID=0
fi

if [ "$LEVEL_ID" -gt 0 ]; then

    LS="$(
        post getGJLevels21 \
            -d 'type=0' \
            -d "str=$LEVEL_ID" \
            -d 'page=0' \
            -d 'gameVersion=22'
    )"

    expect_contains "Search level by ID" "$LS" "1:$LEVEL_ID:"

    LD="$(
        post downloadGJLevel22 \
            -d "levelID=$LEVEL_ID" \
            -d 'gameVersion=22'
    )"

    expect_contains "Download level" "$LD" 'MUCHO_V80_LEVEL_DATA_A'

    DU="$(
        post updateGJLevelDesc20 \
            -d "accountID=$AID1" \
            -d "gjp2=$P1" \
            -d "levelID=$LEVEL_ID" \
            --data-urlencode "levelDesc=$DESC2"
    )"

    expect_eq "Update level description" "$DU" "1"

    WRONG="$(
        post updateGJLevelDesc20 \
            -d "accountID=$AID2" \
            -d "gjp2=$P2" \
            -d "levelID=$LEVEL_ID" \
            --data-urlencode "levelDesc=$DESC1"
    )"

    expect_eq "Reject foreign level edit" "$WRONG" "-1"

    echo
    echo "===== COMMENTS / LIKES ====="

    COMMENT="$(
        printf 'Mucho V80 level comment' |
        base64 -w0
    )"

    CID="$(
        post uploadGJComment21 \
            -d "accountID=$AID1" \
            -d "gjp2=$P1" \
            -d "levelID=$LEVEL_ID" \
            --data-urlencode "comment=$COMMENT" \
            -d 'percent=77'
    )"

    if [[ "$CID" =~ ^[0-9]+$ ]] && [ "$CID" -gt 0 ]; then
        pass "Upload level comment"
    else
        fail "Upload level comment :: got=[$CID]"
        CID=0
    fi

    GC="$(
        post getGJComments21 \
            -d "levelID=$LEVEL_ID" \
            -d 'page=0'
    )"

    if [ "$CID" -gt 0 ]; then
        expect_contains "Get level comments" "$GC" "$CID"
    fi

    if [ "$CID" -gt 0 ]; then
        LIKE="$(
            post likeGJItem211 \
                -d "accountID=$AID2" \
                -d "gjp2=$P2" \
                -d "itemID=$CID" \
                -d 'type=2' \
                -d 'like=1'
        )"

        expect_eq "Like level comment" "$LIKE" "1"

        DELC="$(
            post deleteGJComment20 \
                -d "accountID=$AID1" \
                -d "gjp2=$P1" \
                -d "commentID=$CID"
        )"

        expect_eq "Delete own level comment" "$DELC" "1"

        ORPHAN="$(
            CID="$CID" php <<'PHP'
<?php
require 'vendor/autoload.php';
$db=\MuchoCore\V71\DatabaseBridge::pdo();

$q=$db->prepare(
    "SELECT COUNT(*)
     FROM likes
     WHERE item_id=?
       AND type=2"
);

$q->execute([(int)getenv('CID')]);

echo (int)$q->fetchColumn();
PHP
        )"

        expect_eq "No orphan comment likes" "$ORPHAN" "0"
    fi

    ACC_COMMENT="$(
        printf 'Mucho V80 account comment' |
        base64 -w0
    )"

    ACID="$(
        post uploadGJAccComment20 \
            -d "accountID=$AID1" \
            -d "gjp2=$P1" \
            --data-urlencode "comment=$ACC_COMMENT"
    )"

    if [[ "$ACID" =~ ^[0-9]+$ ]] && [ "$ACID" -gt 0 ]; then
        pass "Upload account comment"

        GA="$(
            post getGJAccountComments20 \
                -d "accountID=$AID1" \
                -d 'page=0'
        )"

        expect_contains "Get account comments" "$GA" "$ACID"

        DAC="$(
            post deleteGJAccComment20 \
                -d "accountID=$AID1" \
                -d "gjp2=$P1" \
                -d "commentID=$ACID"
        )"

        expect_eq "Delete account comment" "$DAC" "1"
    else
        fail "Upload account comment :: got=[$ACID]"
    fi
fi

echo
echo "===== SOCIAL ====="

FR="$(
post uploadFriendRequest20 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d "toAccountID=$AID2" \
    -d 'comment=VjgwIEZSSUVORA=='
)"

expect_eq "Send friend request" "$FR" "1"

FRID="$(latest_pk friend_requests "$AID1" "$AID2")"

if [[ "$FRID" =~ ^[0-9]+$ ]] && [ "$FRID" -gt 0 ]; then
    pass "Resolve friend request ID"
else
    fail "Resolve friend request ID"
    FRID=0
fi

FGET="$(
post getGJFriendRequests20 \
    -d "accountID=$AID2" \
    -d "gjp2=$P2" \
    -d 'page=0'
)"

expect_not "Get friend requests" "$FGET" "-1"

if [ "$FRID" -gt 0 ]; then
    FA="$(
        post acceptGJFriendRequest20 \
            -d "accountID=$AID2" \
            -d "gjp2=$P2" \
            -d "requestID=$FRID"
    )"

    expect_eq "Accept friend request" "$FA" "1"
fi

UL="$(
post getGJUserList20 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d 'type=0'
)"

expect_not "Friend user list" "$UL" "-1"

echo
echo "===== MESSAGES ====="

MS="$(
post uploadGJMessage20 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d "toAccountID=$AID2" \
    -d 'subject=VjgwIFN1YmplY3Q=' \
    -d 'body=VjgwIE1lc3NhZ2UgQm9keQ=='
)"

expect_eq "Send message" "$MS" "1"

ML="$(
post getGJMessages20 \
    -d "accountID=$AID2" \
    -d "gjp2=$P2" \
    -d 'page=0' \
    -d 'getSent=0'
)"

expect_not "Get messages" "$ML" "-1"

MID="$(latest_pk messages "$AID1" "$AID2")"

if [[ "$MID" =~ ^[0-9]+$ ]] && [ "$MID" -gt 0 ]; then
    pass "Resolve message ID"

    MR="$(
        post downloadGJMessage20 \
            -d "accountID=$AID2" \
            -d "gjp2=$P2" \
            -d "messageID=$MID"
    )"

    expect_not "Download/read message" "$MR" "-1"

    MD="$(
        post deleteGJMessages20 \
            -d "accountID=$AID2" \
            -d "gjp2=$P2" \
            -d "messageID=$MID" \
            -d 'isSender=0'
    )"

    expect_eq "Delete received message" "$MD" "1"
else
    fail "Resolve message ID"
fi

echo
echo "===== FRIEND REMOVE / BLOCK ====="

RF="$(
post removeGJFriend20 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d "targetAccountID=$AID2"
)"

expect_eq "Remove friend" "$RF" "1"

BL="$(
post blockGJUser20 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d "targetAccountID=$AID2"
)"

expect_eq "Block user" "$BL" "1"

UBL="$(
post unblockGJUser20 \
    -d "accountID=$AID1" \
    -d "gjp2=$P1" \
    -d "targetAccountID=$AID2"
)"

expect_eq "Unblock user" "$UBL" "1"

echo
echo "===== MUSIC ====="

SONG="$(
post getGJSongInfo \
    -d 'songID=10001'
)"

expect_contains "Known song" "$SONG" 'At the Speed of Light'

UNKNOWN="$(
post getGJSongInfo \
    -d 'songID=999999999'
)"

expect_eq "Unknown song" "$UNKNOWN" "-1"

MUSIC="$(
api_get /api/v2/music
)"

expect_contains "Music API" "$MUSIC" '"id": 10001'

echo
echo "===== AUTH SECURITY SMOKE ====="

for ep in \
backupGJAccount20 \
syncGJAccount20 \
uploadGJLevel22 \
updateGJLevelDesc20 \
deleteGJLevelUser20 \
suggestGJStars20 \
rateGJStars211 \
rateGJDemon21 \
getGJLevelScores211 \
getGJLevelScoresPlat
do
    BODY="$(
        post "$ep" \
            -d "accountID=$AID1" \
            -d 'gjp2=INVALID_V80' \
            -d "levelID=$LEVEL_ID"
    )"

    if [ "$BODY" = "-1" ]; then
        pass "$ep rejects invalid auth"
    else
        fail "$ep invalid auth :: got=[$BODY]"
    fi
done

echo
echo "===== PUBLIC LEVEL REPORT ====="

if [ "$LEVEL_ID" -gt 0 ]; then
    REPORT1="$(
        post reportGJLevel \
            -d "levelID=$LEVEL_ID"
    )"

    if [[ "$REPORT1" =~ ^[0-9]+$ ]] && [ "$REPORT1" -gt 0 ]; then
        pass "Public level report"
    else
        fail "Public level report :: got=[$REPORT1]"
    fi

    REPORT2="$(
        post reportGJLevel \
            -d "levelID=$LEVEL_ID"
    )"

    expect_eq "Duplicate report rejected by IP" "$REPORT2" "-1"
fi

echo
echo "===== DISCOVERY / REWARD ROUTE SMOKE ====="

for ep in \
getGJDailyLevel \
getGJGauntlets21 \
getGJMapPacks21 \
getGJRewards \
getGJChallenges
do
    CODE="$(
        curl -sS \
            --connect-timeout 5 \
            --max-time 20 \
            -o "/tmp/mucho_v80_${ep}" \
            -w '%{http_code}' \
            -H "Host: $HOST" \
            -H "CF-Connecting-IP: $TEST_IP" \
            -X POST \
            "$DOMAIN/database/${ep}.php"
    )"

    if [ "$CODE" = "200" ]; then
        pass "$ep HTTP 200"
    else
        fail "$ep HTTP=$CODE"
    fi
done

echo
echo "===== V7.1 STANDALONE SMOKE ====="

for ep in \
getGJLevelLists.php \
getGJCommentHistory.php \
getGJTopArtists.php \
getAccountURL.php \
getCustomContentURL.php
do
    CODE="$(
        curl -sS \
            --connect-timeout 5 \
            --max-time 20 \
            -o "/tmp/mucho_v80_${ep}" \
            -w '%{http_code}' \
            -H "Host: $HOST" \
            -X POST \
            "$DOMAIN/database/$ep"
    )"

    if [ "$CODE" = "200" ]; then
        pass "$ep HTTP 200"
    else
        fail "$ep HTTP=$CODE"
    fi
done

WL="$(
curl -sS \
    -H "Host: $HOST" \
    -X POST \
    "$DOMAIN/database/uploadGJLevelList.php" \
    -d 'accountID=0' \
    -d 'gjp2=INVALID' \
    -d 'listName=V80'
)"

if [ "$WL" = "-100" ] || [ "$WL" = "-1" ]; then
    pass "Level-list upload rejects invalid auth"
else
    fail "Level-list upload invalid auth :: got=[$WL]"
fi

DL="$(
curl -sS \
    -H "Host: $HOST" \
    -X POST \
    "$DOMAIN/database/deleteGJLevelList.php" \
    -d 'accountID=0' \
    -d 'gjp2=INVALID' \
    -d 'listID=1'
)"

expect_eq "Level-list delete rejects invalid auth" "$DL" "-1"

echo
echo "===== LEVEL DELETE ====="

if [ "$LEVEL_ID" -gt 0 ]; then

    FOREIGN_DELETE="$(
        post deleteGJLevelUser20 \
            -d "accountID=$AID2" \
            -d "gjp2=$P2" \
            -d "levelID=$LEVEL_ID"
    )"

    expect_eq "Reject foreign level delete" "$FOREIGN_DELETE" "-1"

    LD="$(
        post deleteGJLevelUser20 \
            -d "accountID=$AID1" \
            -d "gjp2=$P1" \
            -d "levelID=$LEVEL_ID"
    )"

    expect_eq "Delete own level" "$LD" "1"

    AFTER="$(
        post downloadGJLevel22 \
            -d "levelID=$LEVEL_ID" \
            -d 'gameVersion=22'
    )"

    expect_eq "Deleted level unavailable" "$AFTER" "-1"
fi

echo
echo "===== FINAL HEALTH ====="

FH="$(api_get /api/v2/health)"
expect_contains "Final health" "$FH" '"ok": true'

echo
echo "========================================"
echo " V8.0 RESULT"
echo " PASS=$PASS"
echo " FAIL=$FAIL"
echo "========================================"

echo
echo "===== CRITICAL PATH GUARDS ====="
grep -q "this->auth->authenticate(\$accountId, \$credential)" "$ROOT/src/CloudSave/CloudSaveService.php"
grep -q "authenticate(\$accountId, \$credential)" "$ROOT/public/api/v2/music-upload.php"
grep -q 'storage/music-public' "$ROOT/docker/app-entrypoint.sh"
grep -q 'handle_path /music/\*' "$ROOT/docker/Caddyfile"
grep -q "/var/lib/muchocore/runtime.env" "$ROOT/public/api/v2/bootstrap.php"
grep -q "/var/lib/muchocore/runtime.env" "$ROOT/public/database/muchoProfileV1.php"
grep -q "MuchoCore\\\\Database\\\\Database" "$ROOT/src/V71/DatabaseBridge.php"
grep -q "require __DIR__ . '/../index.php';" "$ROOT/public/database/uploadGJComment20.php"
grep -q "require __DIR__ . '/../index.php';" "$ROOT/public/database/uploadGJComment21.php"
! grep -q "role_id.*>= 1" "$ROOT/public/database/uploadGJComment20.php"
! grep -q "role_id.*>= 1" "$ROOT/public/database/uploadGJComment21.php"
echo "Critical path guards: PASS"

if [ "$FAIL" -eq 0 ]; then
    echo "MUCHOCORE_REGRESSION_OK"
    exit 0
fi

echo "MUCHOCORE_REGRESSION_FAILED"
exit 1
