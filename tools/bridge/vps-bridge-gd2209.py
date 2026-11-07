#!/usr/bin/env python3
"""Read-only synthetic Geometry Dash 2.209 HTTP protocol probe.

This is a synthetic client, not the real game executable. It makes only bounded
unauthenticated POST requests to the isolated localhost:18080 stress server.
Never accepts custom targets, credentials, parameters, or mutation endpoints.
"""
from __future__ import annotations

import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:18080"
TIMEOUT = 6
SEARCH_TERM = "__muchocore_bridge_no_such_level_93a0bf"


def request(name: str, path: str, fields: dict[str, str]) -> bool:
    data = urllib.parse.urlencode(fields).encode("ascii")
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    req = urllib.request.Request(
        BASE + path,
        data=data,
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "User-Agent": "GeometryDash/2.209 (synthetic MuchoCore bridge)",
            "Accept": "*/*",
        },
        method="POST",
    )
    try:
        with opener.open(req, timeout=TIMEOUT) as resp:
            body = resp.read(8192)
            status = resp.status
            content_type = resp.headers.get("Content-Type", "")
    except (urllib.error.URLError, OSError, TimeoutError) as exc:
        print(f"FAIL {name}: {type(exc).__name__}")
        return False

    # HTML login pages, 404 fallback, and PHP/server error output are not
    # Geometry Dash responses. A no-results "-1" is VALID for search.
    valid = (
        status == 200
        and len(body) > 0
        and len(body) < 8192
        and b"<html" not in body.lower()
        and b"warning:" not in body.lower()
        and b"fatal error" not in body.lower()
        and b"stack trace" not in body.lower()
        and not content_type.lower().startswith("text/html")
    )
    preview = body[:90].decode("ascii", errors="replace").replace("\n", " ")
    print(f"{'PASS' if valid else 'FAIL'} {name}: HTTP {status}; bytes={len(body)}; response={preview!r}")
    return valid


def main() -> int:
    game_fields = {
        "gameVersion": "22",
        "binaryVersion": "51",
        "secret": "Wmfd2893gb7",
    }
    search = request(
        "GD 2.209 level search",
        "/a/getGJLevels21.php",
        {**game_fields, "type": "0", "page": "0", "str": SEARCH_TERM},
    )
    users = request(
        "GD 2.209 user search",
        "/a/getGJUsers20.php",
        {**game_fields, "page": "0", "str": SEARCH_TERM},
    )
    print(
        "Synthetic 2.209 request framing only. Does NOT certify real GD "
        "2.209 client acceptance, auth, writes, Cloud Save or correctness "
        "of server-side level/user data."
    )
    return 0 if search and users else 1


if __name__ == "__main__":
    sys.exit(main())
