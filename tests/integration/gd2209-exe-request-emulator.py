#!/usr/bin/env python3
"""Replay GD 2.209 EXE-derived endpoint *shapes* against disposable PHP 8 HTTP.

The 113 paths come from a prior static inspection of the user's patched
Windows GD 2.209 PE. Nearby field names are unverified proximity hints.
This is synthetic request emulation, NOT an execution of the game or native
client acceptance/certification.
"""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import re
from urllib import error, parse, request

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = ROOT / "tests/client-fixtures/2.209/native-windows-exe-static.json"
BASE = "http://127.0.0.1:18080"
MAX_REPLY = 262144
LEAK = re.compile(
    rb"(?:Fatal error:|Uncaught\s+\w+|Stack trace:|SQLSTATE\[|"
    rb"PDOException|Parse error:|(?:Warning|Notice):\s+)",
    re.I,
)
# Exact literal fragments recovered in docs/GD_2209_NATIVE_EXE_FINDINGS.md.
# Values are synthetic; the complete wire form is NOT established by a fragment.
NATIVE_FRAGMENTS = {
    "adminGetReportQueue.php": {"page": "0", "sort": "0", "mode": "0", "flags": "1"},
    "adminGetReportedContent.php": {"targetAccountID": "0", "page": "0", "sort": "0", "ctype": "0"},
    "adminGetUserHistory.php": {"targetAccountID": "0", "page": "0"},
    "adminGetAbuseInfo.php": {"targetAccountID": "0", "page": "0", "history": "0"},
    "adminGetAccountReview.php": {"targetAccountID": "0"},
    "adminClearReportedContent.php": {"targetAccountID": "0", "items": ""},
    "adminThreadAction.php": {"threadID": "0", "action": "invalid", "messageID": "0"},
    "adminCommentBan.php": {"mode": "invalid", "durationMinutes": "0"},
    "adminCloseCase.php": {"outcome": "0"},
    "syncGJFollowing.php": {"follows": "", "ack": "1"},
    "getGJFollowing.php": {"ack": "1"},
    "getGJUserSearch.php": {"str": ""},
    "getGJSongUsage.php": {"songID": "0"},
    "getGJContentStatus.php": {"special": "1"},
    "getGJAudioFilesize.php": {"songIDs": "", "sfxIDs": ""},
    "reportGJContent.php": {
        "type": "0", "targetID": "0", "special": "0", "reasons": "",
    },
}
INTEGER = {
    "accountID", "userID", "targetAccountID", "toAccountID", "levelID",
    "commentID", "threadID", "messageID", "songID", "type", "page", "count",
    "mode", "sort", "flags", "ctype", "special", "outcome", "durationMinutes",
    "total", "gameID", "levelVersion", "binaryVersion", "gameVersion",
}
NEGATIVE_CREDENTIALS = {
    "accountID": "0", "gjp2": "invalid", "gjp": "invalid",
    "password": "invalid", "secret": "synthetic-replay",
}
HTML_PATHS = {
    "/database/accounts/lostusername.php",
    "/database/accounts/lostpassword.php",
    "/database/accounts/accountManagement.php",
}


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


OPENER = request.build_opener(NoRedirect())


def check(ok: bool, label: str) -> None:
    if not ok:
        raise AssertionError(label)


def read_fixture() -> list[dict]:
    fixture = json.loads(FIXTURE.read_text(encoding="utf-8"))
    source = fixture["source"]
    check(source["client_binary_version"] == 51, "Unexpected binary version")
    check(source["client_game_version"] == 22, "Unexpected game version")
    check(re.fullmatch(r"[0-9a-f]{64}", source["sha256"]) is not None, "Invalid source SHA")
    check(source["original_size"] == 13178880, "Unexpected saved EXE size")
    routes = fixture["routes"]
    check(fixture["expected_direct_urls"] == len(routes) == 113, "Incomplete EXE URL inventory")
    paths = [row["path"] for row in routes]
    check(len(paths) == len(set(paths)), "Duplicate native URL")
    for row in routes:
        path = row["path"]
        check(
            path.startswith("/database/") and path.endswith(".php")
            and not any(token in path for token in ("..", "?", "#", "//", "\\")),
            "Unexpected EXE route path",
        )
        check(re.fullmatch(r"0x[0-9a-f]+", row["section_offset"]) is not None, "Invalid offset")
        fields = row["parameter_name_hints"]
        check(isinstance(fields, list) and all(re.fullmatch(r"[A-Za-z][A-Za-z0-9]*", k) for k in fields),
              "Untrusted parameter field in static inventory")
    check(
        fixture["known_gap"] == ["/database/uploadMPComment.php"],
        "Known unimplemented native operation changed; re-audit required",
    )
    return routes


def shape(fields: list[str], basename: str) -> dict[str, str]:
    payload = {"gameVersion": "22", "binaryVersion": "51"}
    for name in fields:
        payload[name] = "0" if name in INTEGER else "test"
    payload.update(NATIVE_FRAGMENTS.get(basename, {}))
    # Never send a real account or valid auth credential. Mutation checks with
    # authenticated data belong to tests/integration/regression.sh fixtures.
    for name in NEGATIVE_CREDENTIALS:
        if name in payload:
            payload[name] = NEGATIVE_CREDENTIALS[name]
    payload["secret"] = NEGATIVE_CREDENTIALS["secret"]
    return payload


def post(path: str, fields: dict[str, str]) -> tuple[int, bytes, str]:
    req = request.Request(
        BASE + path,
        data=parse.urlencode(fields).encode("utf-8"),
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        resp = OPENER.open(req, timeout=12)
    except error.HTTPError as exc:
        resp = exc
    with resp:
        return resp.status, resp.read(MAX_REPLY + 1), resp.headers.get("Content-Type", "")


def probe(path: str, label: str, fields: dict[str, str]) -> tuple[int, str]:
    status, content, mime = post(path, fields)
    # Browser recovery and protected GD operations may intentionally reject
    # anonymous synthetic requests. Treat those as guarded, not as successes.
    # A 404 is not accepted: the EXE expects a route at this exact URL.
    check(status in (200, 400, 401, 403, 405, 422),
          f"{path} {label}: unexpected HTTP {status}")
    check(len(content) <= MAX_REPLY, f"{path} {label}: oversized response")
    check(not LEAK.search(content), f"{path} {label}: PHP/SQL error disclosure")
    is_html = "text/html" in mime.lower()
    if path not in HTML_PATHS:
        check(not is_html and not content.lstrip().lower().startswith(b"<!doctype"),
              f"{path} {label}: unexpected browser HTML")
        if status == 200:
            check(bool(content), f"{path} {label}: empty game response")
    if path.endswith("/checkIfServerOnline.php"):
        check(status == 200, "Online check must be HTTP 200")
        check(content == b"1", "Online check must return exact native success '1'")
    if path.endswith("/getAccountURL.php") and status == 200:
        check(content.startswith((b"http://", b"https://")),
              "Native account URL endpoint should return HTTP(S) URL")
    return status, hashlib.sha256(content).hexdigest()


def run_http(routes: list[dict]) -> None:
    check(os.environ.get("DB_NAME") == "testgdps", "Refusing non-disposable DB")
    check(os.environ.get("MUCHO_TEST_ALLOW_WRITES") == "1", "Test fixture flag required")
    check(os.environ.get("MUCHO_TEST_BASE_URL") == BASE, "Only loopback test target is supported")

    total = 0
    buckets = Counter()
    statuses = Counter()
    for row in routes:
        path = row["path"]
        basename = path.rsplit("/", 1)[-1]
        client = shape(row["parameter_name_hints"], basename)
        anonymous = {
            "gameVersion": "22", "binaryVersion": "51",
            "secret": NEGATIVE_CREDENTIALS["secret"],
        }
        poisoned = dict(client)
        for name in ("accountID", "gjp2", "levelID", "type", "page", "secret"):
            poisoned.pop(name, None)
            poisoned[name + "[]"] = "invalid"
        for label, payload in (
            ("anonymous", anonymous),
            ("client-shaped-no-auth", client),
            ("malformed-array", poisoned),
        ):
            status, _ = probe(path, label, payload)
            statuses[str(status)] += 1
            buckets[label] += 1
            total += 1
    print(
        "MUCHOCORE_GD2209_EXE_SYNTHETIC_HTTP_OK "
        f"native_routes={len(routes)} requests={total} "
        f"variants={json.dumps(buckets, sort_keys=True)} "
        f"statuses={json.dumps(statuses, sort_keys=True)} "
        "native_client_certified=false"
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--http", action="store_true", help="Send to disposable local Caddy")
    args = parser.parse_args()
    routes = read_fixture()
    if args.http:
        run_http(routes)
    else:
        print(
            "MUCHOCORE_GD2209_EXE_STATIC_FIXTURE_OK "
            f"native_routes={len(routes)} "
            f"with_parameter_hints={sum(bool(row['parameter_name_hints']) for row in routes)}"
        )


if __name__ == "__main__":
    main()
