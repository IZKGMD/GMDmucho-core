#!/usr/bin/env bash
set -euo pipefail

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

HOST="${MUCHO_TEST_HOST:-localhost}"
BASE="${MUCHO_TEST_BASE_URL:-http://127.0.0.1}"

run_load () {
    NAME="$1"
    URL="$2"
    METHOD="$3"
    DATA="$4"
    TOTAL="$5"
    CONCURRENCY="$6"

    TMP="$(mktemp -d /tmp/mucho_v83_XXXXXX)"
    START_NS="$(date +%s%N)"

    export URL METHOD DATA HOST TMP

    seq 1 "$TOTAL" | xargs -P "$CONCURRENCY" -I{} bash -c '
        OUT="$TMP/{}"
        N={}
        OCTET=$(( (N % 250) + 1 ))
        TEST_IP="198.51.100.${OCTET}"

        if [ "$METHOD" = "POST" ]; then
            /usr/bin/curl -sS \
                --connect-timeout 3 \
                --max-time 10 \
                -H "Host: $HOST" \
                -H "CF-Connecting-IP: $TEST_IP" \
                -o /dev/null \
                -w "%{http_code} %{time_total}\n" \
                -X POST \
                -d "$DATA" \
                "$URL" > "$OUT" 2>/dev/null || echo "000 10" > "$OUT"
        else
            /usr/bin/curl -sS \
                --connect-timeout 3 \
                --max-time 10 \
                -H "Host: $HOST" \
                -H "CF-Connecting-IP: $TEST_IP" \
                -o /dev/null \
                -w "%{http_code} %{time_total}\n" \
                "$URL" > "$OUT" 2>/dev/null || echo "000 10" > "$OUT"
        fi
    '

    END_NS="$(date +%s%N)"

    awk '
    {
        code[$1]++;
        t[NR]=$2;
        sum+=$2;
    }
    END{
        n=NR;

        for(i=1;i<=n;i++){
            for(j=i+1;j<=n;j++){
                if(t[i]>t[j]){
                    x=t[i];
                    t[i]=t[j];
                    t[j]=x;
                }
            }
        }

        p50=t[int((n-1)*0.50)+1];
        p95=t[int((n-1)*0.95)+1];
        p99=t[int((n-1)*0.99)+1];

        printf("requests=%d\n",n);
        printf("avg=%.6fs\n",sum/n);
        printf("p50=%.6fs\n",p50);
        printf("p95=%.6fs\n",p95);
        printf("p99=%.6fs\n",p99);

        for(c in code){
            printf("http_%s=%d\n",c,code[c]);
        }
    }' "$TMP"/*

    ELAPSED="$(
        awk -v s="$START_NS" -v e="$END_NS" \
        'BEGIN{printf "%.3f",(e-s)/1000000000}'
    )"

    RPS="$(
        awk -v n="$TOTAL" -v t="$ELAPSED" \
        'BEGIN{if(t>0) printf "%.2f",n/t; else print "0"}'
    )"

    echo "wall=${ELAPSED}s"
    echo "throughput=${RPS}_req_s"

    BAD="$(
        awk '$1 != "200" {n++} END {print n+0}' "$TMP"/*
    )"

    if [ "$BAD" -gt 0 ]; then
        echo "LOAD_ERRORS=$BAD"
        rm -rf "$TMP"
        return 1
    fi

    echo "LOAD_ERRORS=0"
    rm -rf "$TMP"
}

echo "========================================"
echo " MUCHOCORE V8.3 LOCAL LOAD"
echo "========================================"

echo
echo "===== SYSTEM BEFORE ====="
uptime
free -h

echo
echo "===== FPM BEFORE ====="
ps -C php-fpm8.3 -o pid,rss,%cpu,cmd --sort=-rss 2>/dev/null || \
ps aux | grep '[p]hp-fpm'

for C in 1 2 4 8
do
    echo
    echo "===== HEALTH C=$C ====="

    run_load \
        "Health" \
        "$BASE/api/v2/health" \
        GET \
        "" \
        100 \
        "$C"
done

for C in 1 2 4 8
do
    echo
    echo "===== LEVEL SEARCH C=$C ====="

    run_load \
        "LevelSearch" \
        "$BASE/database/getGJLevels21.php" \
        POST \
        "type=0&str=1&page=0&gameVersion=22" \
        100 \
        "$C"
done

for C in 1 2 4 8
do
    echo
    echo "===== SONG INFO C=$C ====="

    run_load \
        "SongInfo" \
        "$BASE/database/getGJSongInfo.php" \
        POST \
        "songID=10001" \
        100 \
        "$C"
done

echo
echo "===== BURST C=16 ====="

run_load \
    "LevelSearchBurst" \
    "$BASE/database/getGJLevels21.php" \
    POST \
    "type=0&str=1&page=0&gameVersion=22" \
    160 \
    16

echo
echo "===== SYSTEM AFTER ====="
uptime
free -h

echo
echo "===== FPM AFTER ====="
ps -C php-fpm8.3 -o pid,rss,%cpu,cmd --sort=-rss 2>/dev/null || \
ps aux | grep '[p]hp-fpm'

echo
echo "===== MYSQL AFTER ====="

php <<'PHP'
<?php
require 'vendor/autoload.php';

$db=\MuchoCore\V71\DatabaseBridge::pdo();

foreach([
    'Threads_connected',
    'Threads_running',
    'Slow_queries',
    'Created_tmp_tables',
    'Created_tmp_disk_tables'
] as $name){
    $q=$db->query(
        "SHOW GLOBAL STATUS LIKE ".$db->quote($name)
    );

    if($r=$q->fetch(PDO::FETCH_NUM)){
        echo $r[0]."=".$r[1]."\n";
    }
}
PHP

echo
echo "===== FINAL HEALTH ====="

/usr/bin/curl -fsS \
    -H "Host: $HOST" \
    -H "CF-Connecting-IP: 203.0.113.254" \
    "$BASE/api/v2/health"

echo
echo "V83_LOAD_TEST_OK"
