#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTRACT="${MUCHO_CLIENT_CONTRACT:-$ROOT/tests/client-fixtures/endpoints.json}"

if [[ ! -f "$CONTRACT" ]]; then
  echo "Client contract not found: $CONTRACT"
  echo "Generate it after a real client test with:"
  echo "python3 tools/client-trace-summary.py --input storage/client-trace.ndjson --output tests/client-fixtures/endpoints.json"
  exit 1
fi

export CONTRACT ROOT

python3 <<'PY'
import json
import os
import re
from pathlib import Path

root = Path(os.environ["ROOT"])
contract = Path(os.environ["CONTRACT"])
data = json.loads(contract.read_text(encoding="utf-8"))
application = (root / "src/Core/Application.php").read_text(encoding="utf-8")
routes = {x.lower() for x in re.findall(r"\$route\(\s*'([^']+)'", application)}
missing = []

for endpoint in data.get("endpoints", []):
    path = str(endpoint["path"])
    method = str(endpoint.get("method", "GET")).upper()
    if not path.startswith("/database/"):
        continue
    relative = path[len("/database/"):].split("?", 1)[0]
    physical = root / "public" / "database" / relative
    stem = Path(relative).stem.lower()
    if physical.is_file():
        continue
    if "/" + stem in routes:
        continue
    missing.append(f"{method} {path}")

if missing:
    print("Missing client endpoints:")
    for item in missing:
        print(f"  - {item}")
    raise SystemExit(1)

print(f"Client contract OK: {len(data.get('endpoints', []))} endpoint(s)")
PY
