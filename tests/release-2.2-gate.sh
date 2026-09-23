#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONTRACT="$ROOT/tests/client-fixtures/2.2/endpoints.json"

if [[ ! -f "$CONTRACT" ]]; then
    echo "2.2 RELEASE GATE: FAIL"
    echo "Missing real-client fixture: tests/client-fixtures/2.2/endpoints.json"
    echo "Generate it only from a real Geometry Dash 2.2 trace:"
    echo "python3 tools/client-trace-summary.py --expected-family 2.2 --input storage/client-trace.ndjson --output tests/client-fixtures/2.2/endpoints.json"
    exit 1
fi

python3 - "$CONTRACT" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
families = set(data.get("trace_client_families", []))
if families != {"2.2"}:
    raise SystemExit(f"2.2 RELEASE GATE: FAIL - trace families are {sorted(families)}")
endpoints = data.get("endpoints", [])
if not endpoints:
    raise SystemExit("2.2 RELEASE GATE: FAIL - fixture contains no endpoints")
print(f"2.2 RELEASE GATE: PASS - real 2.2 client fixture with {len(endpoints)} unique endpoint(s)")
PY
