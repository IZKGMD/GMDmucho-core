#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${MUCHO_TEST_BASE_URL:-http://127.0.0.1}"

BASE_URL="${BASE_URL%/}"

echo "===== HEALTH ====="
curl -fsS "$BASE_URL/api/v2/health"
echo

echo "===== DASHBOARD ====="
curl -fsS "$BASE_URL/api/v2/dashboard"
echo

echo "===== PROFILE ====="
PROFILE_ID="${MUCHO_TEST_PROFILE_ID:-1}"
curl -fsS "$BASE_URL/api/v2/profile/$PROFILE_ID"
echo

echo "===== CLIENT CONFIG ====="
curl -fsS "$BASE_URL/api/v2/client-config?platform=android&version=1.0.0"
echo

echo "SMOKE_TESTS_OK"
