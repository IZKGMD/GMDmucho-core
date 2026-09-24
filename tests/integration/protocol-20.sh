#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${MUCHO_TEST_BASE_URL:-http://127.0.0.1}"
BASE_URL="${BASE_URL%/}"
HOST="${MUCHO_TEST_HOST:-localhost}"

if [[ -z "${MUCHO_TEST_ACCOUNT_ID:-}" || -z "${MUCHO_TEST_GJP:-}" ]]; then
    echo "GD20_INTEGRATION_SKIPPED: set MUCHO_TEST_ACCOUNT_ID and MUCHO_TEST_GJP for a real 2.0 account."
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

echo "===== GD 2.0 PROFILE ====="
AUTH="$(post getGJUserInfo20     -d "accountID=$ACCOUNT_ID"     -d "targetAccountID=$ACCOUNT_ID"     -d gameVersion=20     -d binaryVersion=27)"

grep -q "^1:" <<<"$AUTH"
echo "PASS 2.0 authenticated profile"

echo "===== GD 2.0 LEVEL DISCOVERY ====="
LIST="$(post getGJLevels20     -d type=0     -d page=0     -d gameVersion=20     -d binaryVersion=27)"

[[ -n "$LIST" && "$LIST" != "-1" ]]
echo "PASS 2.0 level discovery"

echo "===== GD 2.0 USER LEADERBOARD ====="
LB="$(post getGJScores20     -d type=top     -d gameVersion=20     -d binaryVersion=27)"

[[ -n "$LB" && "$LB" != "-1" ]]
echo "PASS 2.0 leaderboard surface"

if [[ "$LEVEL_ID" -gt 0 ]]; then
    echo "===== GD 2.0 LEVEL DOWNLOAD ====="
    DOWNLOAD="$(post downloadGJLevel20         -d "levelID=$LEVEL_ID"         -d gameVersion=20         -d binaryVersion=27)"

    [[ -n "$DOWNLOAD" && "$DOWNLOAD" != "-1" ]]
    grep -q "#[0-9a-f]{40}#" <<<"$DOWNLOAD"
    echo "PASS 2.0 level download and hash sections"

    echo "===== GD 2.0 LEVEL SCORE ====="
    SCORES="$(post getGJLevelScores20         -d "accountID=$ACCOUNT_ID"         -d "gjp=$GJP"         -d "levelID=$LEVEL_ID"         -d gameVersion=20         -d binaryVersion=27         -d percent=100         -d type=1)"

    [[ -n "$SCORES" && "$SCORES" != "-1" ]]
    [[ "$SCORES" != *"|" ]]
    echo "PASS 2.0 level score surface"
fi

echo "MUCHOCORE_PROTOCOL_20_INTEGRATION_OK"
