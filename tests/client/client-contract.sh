#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

declare -a CONTRACTS=()
MUCHO_CLIENT_CONTRACT_VALUE="$(printenv MUCHO_CLIENT_CONTRACT 2>/dev/null || true)"

if [[ -n "$MUCHO_CLIENT_CONTRACT_VALUE" ]]; then
    CONTRACTS+=("$MUCHO_CLIENT_CONTRACT_VALUE")
elif [[ -f "$ROOT/tests/client-fixtures/endpoints.json" ]]; then
    CONTRACTS+=("$ROOT/tests/client-fixtures/endpoints.json")
else
    while IFS= read -r file; do
        CONTRACTS+=("$file")
    done < <(
        find "$ROOT/tests/client-fixtures"             -mindepth 2             -maxdepth 2             -type f             -name endpoints.json             -print | sort
    )
fi

if [[ -z "$(printf '%s\n' "${CONTRACTS[@]}")" ]]; then
    echo "CLIENT_CONTRACT_SKIPPED: no real-client contracts exist yet."
    echo "Generate the 2.2 contract after a real client test with:"
    echo "python3 tools/client/client-trace-summary.py --expected-family 2.2 --input storage/client-trace.ndjson --output tests/client-fixtures/2.2/endpoints.json"
    exit 0
fi

CONTRACT_LIST="$(printf '%s\n' "${CONTRACTS[@]}")"

ROOT="$ROOT" CONTRACTS="$CONTRACT_LIST" python3 <<'PY'
import json
import os
import re
from pathlib import Path

root = Path(os.environ["ROOT"])
contracts = [Path(item) for item in os.environ["CONTRACTS"].splitlines() if item]

application = (root / "src/Core/Application.php").read_text(encoding="utf-8")
routes = {
    x.lower()
    for x in re.findall(
        r"\$route\(\s*'([^']+)'",
        application,
    )
}

router = (root / "src/Routing/Router.php").read_text(encoding="utf-8")
aliases = {}
for match in re.finditer(
    r"'([^']+)'\s*=>\s*'([^']+)'",
    router,
):
    aliases[match.group(1).lower()] = match.group(2).lower()

failures = []
total = 0

for contract in contracts:
    if not contract.is_file():
        failures.append(f"missing contract: {contract}")
        continue

    data = json.loads(contract.read_text(encoding="utf-8"))
    version = contract.parent.name

    if version == "2.2":
        families = set(data.get("trace_client_families", []))
        if families != {"2.2"}:
            failures.append(
                f"2.2: contract must be generated from a real 2.2 trace; "
                f"families={sorted(families)}"
            )

    missing = []

    for endpoint in data.get("endpoints", []):
        total += 1
        path = str(endpoint["path"])
        method = str(endpoint.get("method", "GET")).upper()

        if not path.startswith("/database/"):
            continue

        relative = path[len("/database/"):].split("?", 1)[0]
        physical = root / "public" / "database" / relative
        stem = Path(relative).stem.lower()

        if physical.is_file():
            continue

        normalized = "/" + stem
        normalized = aliases.get(normalized, normalized)

        if normalized in routes:
            continue

        missing.append(f"{method} {path}")

    if missing:
        failures.append(f"{version}: missing {len(missing)} endpoint(s)")
        for item in missing:
            failures.append(f"  - {item}")
    else:
        count = len(data.get("endpoints", []))
        print(f"Client contract OK: {version} ({count} endpoint(s))")

if failures:
    print("Client contract failures:")
    for item in failures:
        print(item)
    raise SystemExit(1)

print(f"Client contracts OK: {total} endpoint(s)")
PY
