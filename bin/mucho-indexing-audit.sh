#!/usr/bin/env bash
set -uo pipefail
# Read-only live crawlability check for the canonical MuchoCore host.
# Run from VPS after deploying pages. Does not send passwords or index requests.

base="https://muchogdps.space"
failures=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

if ! command -v curl >/dev/null 2>&1; then
  echo "ERROR: curl is required." >&2
  exit 2
fi
if ! getent ahosts muchogdps.space >/dev/null 2>&1; then
  echo "FAIL DNS: muchogdps.space did not resolve on this host"
  failures=$((failures + 1))
fi

check() {
  local path="$1" expected="$2" ua="$3" code
  local body="$tmp/body" headers="$tmp/headers"
  : > "$body"; : > "$headers"
  code="$(curl --location --silent --show-error --connect-timeout 8 --max-time 20 \
    --user-agent "$ua" --dump-header "$headers" --output "$body" \
    --write-out '%{http_code}' "$base$path")" || code="000"
  if [[ "$code" != "200" ]]; then
    echo "FAIL HTTP $code: $base$path (agent: $ua)"
    failures=$((failures + 1))
    return
  fi
  if ! grep -Fq "$expected" "$body"; then
    echo "FAIL CONTENT: $base$path did not contain '$expected' (bot challenge or stale deployment?)"
    failures=$((failures + 1))
    return
  fi
  if grep -Eiq '^x-robots-tag:.*noindex' "$headers"; then
    echo "FAIL NOINDEX HEADER: $base$path"
    failures=$((failures + 1))
    return
  fi
  echo "PASS HTTP 200: $base$path (agent: $ua)"
}

check '/robots.txt' 'OAI-SearchBot' 'OAI-SearchBot'
check '/sitemap.xml' '/muchocore/compatibility/' 'Googlebot'
check '/llms.txt' 'MuchoCore' 'OAI-SearchBot'
check '/muchocore/' '<link rel="canonical" href="https://muchogdps.space/muchocore/">' 'OAI-SearchBot'
check '/muchocore/about/' '<title>What is MuchoCore?' 'Bingbot'
check '/muchocore/compatibility/' '<title>MuchoCore Geometry Dash Version Compatibility' 'Googlebot'

if [[ "$failures" -ne 0 ]]; then
  echo "AI/SEO discovery audit: $failures problem(s). Resolve DNS/CDN/robots/deployment before submitting for indexing."
  exit 1
fi
echo "AI/SEO discovery audit passed HTTP/DNS/HTML checks."
echo "NOTE: crawlability does not prove Google/Bing/ChatGPT has indexed or cited the page."
