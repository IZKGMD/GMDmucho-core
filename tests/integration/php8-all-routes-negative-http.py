#!/usr/bin/env python3
"""Smoke every inventoried PHP 8 route through real Caddy/PHP-FPM HTTP.

This deliberately checks only unauthenticated error-path safety and routing.
It is NOT proof of wire parity, authorized mutations, or real GD 2.209 usage.
The script refuses non-local URLs and non-disposable test environments.
"""

import json
import os
from pathlib import Path
import re
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
REGISTRY = ROOT / "tests/protocol/endpoint-contracts.json"
BASE = "http://127.0.0.1:18080"
MAX_RESPONSE = 262_144
ERROR_LEAK = re.compile(
    rb"(?:Fatal error:|Uncaught\s+\w+|Stack trace:|SQLSTATE\[|"
    rb"PDOException|Parse error:|(?:Warning|Notice):\s+)",
    re.IGNORECASE,
)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


OPENER = urllib.request.build_opener(NoRedirect())


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def post(path, fields, label):
    require(path.startswith("/") and "//" not in path[:2], "Non-relative path")
    require(".." not in path.split("/"), "Traversal in endpoint inventory")
    request = urllib.request.Request(
        BASE + path,
        data=urllib.parse.urlencode(fields).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        response = OPENER.open(request, timeout=12)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        status = response.status
        payload = response.read(MAX_RESPONSE + 1)
        content_type = response.headers.get("Content-Type", "")
    require(status < 500, f"{label}: server HTTP {status}")
    require(len(payload) <= MAX_RESPONSE, f"{label}: oversized error response")
    require(not ERROR_LEAK.search(payload), f"{label}: PHP/SQL diagnostic leak")
    require(
        not (payload.lstrip().lower().startswith(b"<!doctype html")
             and "text/html" not in content_type.lower()),
        f"{label}: unexpected HTML framing",
    )
    return status


def main():
    require(os.environ.get("MUCHO_TEST_ALLOW_WRITES") == "1",
            "Disposable acceptance fixture flag required")
    require(os.environ.get("DB_NAME") == "testgdps",
            "Refusing to run outside testgdps")
    require(os.environ.get("MUCHO_TEST_BASE_URL") == BASE,
            "Refusing a non-loopback HTTP target")

    registry = json.loads(REGISTRY.read_text(encoding="utf-8"))
    contracts = registry["contracts"]
    require(len(contracts) >= 160, "Unexpectedly incomplete endpoint registry")
    paths = [entry["canonical_path"] for entry in contracts]
    require(len(paths) == len(set(paths)), "Duplicate canonical paths")

    normal = {
        "gameVersion": "22",
        "binaryVersion": "51",
        "accountID": "0",
        "levelID": "0",
        "page": "0",
    }
    malformed = {
        "gameVersion": "22",
        "binaryVersion": "51",
        "accountID[]": "1",
        "gjp[]": "invalid",
        "gjp2[]": "invalid",
        "userName[]": "invalid",
        "password[]": "invalid",
        "levelID[]": "1",
        "type[]": "1",
        "page[]": "1",
    }

    checks = 0
    statuses = {}
    for entry in contracts:
        path = entry["canonical_path"]
        require(path.startswith("/") and path != "/health", "Unexpected game route")
        for variant, fields in (("missing-credentials", normal),
                                ("array-credentials", malformed)):
            status = post(path, fields, f"{path} {variant}")
            statuses[status] = statuses.get(status, 0) + 1
            checks += 1

        # Check one declared externally visible .php spelling as well as the
        # canonical route. This is a failure-path probe, not a claim that all
        # aliases, protocol versions or authenticated semantics are certified.
        alias = next(
            (candidate for candidate in entry.get("aliases", [])
             if candidate.lower().endswith(".php")),
            None,
        )
        if alias:
            status = post(alias, normal, f"{path} alias {alias}")
            statuses[status] = statuses.get(status, 0) + 1
            checks += 1

    print(
        "MUCHOCORE_PHP8_FULL_ROUTE_FAILURE_PATH_HTTP_OK "
        f"canonical_routes={len(contracts)} requests={checks} "
        f"statuses={json.dumps(statuses, sort_keys=True)}"
    )


if __name__ == "__main__":
    main()
