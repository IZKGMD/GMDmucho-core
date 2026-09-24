#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${MUCHO_TEST_BASE_URL:-http://127.0.0.1}"
BASE_URL="${BASE_URL%/}"
HOST="${MUCHO_TEST_HOST:-localhost}"

if [[ -z "${MUCHO_TEST_ACCOUNT_ID:-}" || -z "${MUCHO_TEST_GJP:-}" ]]; then
    echo "GD21_INTEGRATION_SKIPPED: set MUCHO_TEST_ACCOUNT_ID and MUCHO_TEST_GJP for a real 2.1 account."
    exit 0
fi

ACCOUNT_ID="$MUCHO_TEST_ACCOUNT_ID"
GJP="$MUCHO_TEST_GJP"
LEVEL_ID="${MUCHO_TEST_LEVEL_ID:-0}"

post() {
    local endpoint="$1"
    shift
    curl -fsS         --connect-timeout 5         --max-time 20         -H "Host: $HOST"         -X POST         "$BASE_URL/database/$endpoint.php"         "$@"
}

echo "===== GD 2.1 AUTH ====="
AUTH="$(post getGJUserInfo20     -d "accountID=$ACCOUNT_ID"     -d "targetAccountID=$ACCOUNT_ID"     -d gameVersion=21     -d binaryVersion=35)"

grep -q "^1:" <<<"$AUTH"
echo "PASS 2.1 authenticated profile"

echo "===== GD 2.1 LEVEL DISCOVERY ====="
LIST="$(post getGJLevels21     -d type=0     -d page=0     -d gameVersion=21     -d binaryVersion=35)"

[[ -n "$LIST" && "$LIST" != "-1" ]]
echo "PASS 2.1 level discovery"

echo "===== GD 2.1 USER LEADERBOARD ====="
LB="$(post getGJScores20     -d type=top     -d gameVersion=21     -d binaryVersion=35)"

[[ -n "$LB" && "$LB" != "-1" ]]
echo "PASS 2.1 leaderboard surface"

if [[ "$LEVEL_ID" -gt 0 ]]; then
    echo "===== GD 2.1 LEVEL DOWNLOAD ====="
    DOWNLOAD="$(post downloadGJLevel21         -d "levelID=$LEVEL_ID"         -d gameVersion=21         -d binaryVersion=35)"

    [[ -n "$DOWNLOAD" && "$DOWNLOAD" != "-1" ]]
    grep -q "#[0-9a-f]\{40\}#" <<<"$DOWNLOAD"
    echo "PASS 2.1 level download and hash sections"

    echo "===== GD 2.1 LEVEL SCORE ====="
    SCORES="$(post getGJLevelScores211         -d "accountID=$ACCOUNT_ID"         -d "gjp=$GJP"         -d "levelID=$LEVEL_ID"         -d gameVersion=21         -d binaryVersion=35         -d percent=100         -d type=1)"

    [[ -n "$SCORES" && "$SCORES" != "-1" ]]
    [[ "$SCORES" != *"|" ]]
    echo "PASS 2.1 level score surface"
fi

echo "MUCHOCORE_PROTOCOL_21_INTEGRATION_OK"
