#!/usr/bin/env python3
"""Authenticated GD 2.209 EXE-shaped HTTP/SQL checks on disposable testgdps.

Accounts and credentials are supplied by tests/integration/regression.sh.
Only the native endpoint spelling/selected request fragments are derived from
the saved 2.209 EXE analysis. This tests *server behavior* and DB integrity;
it does not pretend to execute or certify the original Windows client.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import zlib

ROOT = Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:18080"
FIXTURE = ROOT / "tests/client-fixtures/2.209/native-windows-exe-static.json"
DB_HELPER = ROOT / "tests/integration/gd2209-exe-auth-db.php"
LEVEL_STRING = "kS1,1,kA2,0;1,1,2,15,3,15;"
checks = 0


def require(ok: bool, label: str) -> None:
    global checks
    if not ok:
        raise AssertionError("GD2209 authenticated replay: " + label)
    checks += 1
    print("PASS gd2209-auth: " + label, flush=True)


def environment() -> dict:
    require(os.environ.get("MUCHO_TEST_ALLOW_WRITES") == "1",
            "explicit disposable test permission")
    require(os.environ.get("DB_NAME") == "testgdps",
            "testgdps database guard")
    require(os.environ.get("MUCHO_TEST_BASE_URL") == BASE,
            "local-only Caddy listener guard")

    expected = [
        "MUCHO_EXE_AUTH_AID1", "MUCHO_EXE_AUTH_AID2",
        "MUCHO_EXE_AUTH_GJP2_A", "MUCHO_EXE_AUTH_GJP2_B",
        "MUCHO_EXE_AUTH_USERNAME_A", "MUCHO_EXE_AUTH_USERNAME_B",
    ]
    env = {k: os.environ.get(k, "") for k in expected}
    for name in ("MUCHO_EXE_AUTH_AID1", "MUCHO_EXE_AUTH_AID2"):
        require(env[name].isdigit() and int(env[name]) > 0,
                name + " is a positive synthetic fixture account ID")
    require(env["MUCHO_EXE_AUTH_AID1"] != env["MUCHO_EXE_AUTH_AID2"],
            "independent fixture accounts")
    for name in ("MUCHO_EXE_AUTH_GJP2_A", "MUCHO_EXE_AUTH_GJP2_B"):
        require(re.fullmatch("[0-9a-f]{40}", env[name]) is not None,
                name + " has native GJP2 hash format")
    for name in ("MUCHO_EXE_AUTH_USERNAME_A", "MUCHO_EXE_AUTH_USERNAME_B"):
        require(re.fullmatch("[A-Za-z0-9_]{3,30}", env[name]) is not None,
                name + " is a local synthetic fixture user")
    return env


def database(action: str, first: int, second: int = 0) -> dict | None:
    cmd = ["php", str(DB_HELPER), action, str(first), str(second)]
    result = subprocess.run(
        cmd, cwd=ROOT, capture_output=True, text=True, timeout=15,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"GD2209 database helper {action} failed with exit {result.returncode}: "
            + result.stderr.strip()[:300]
        )
    return json.loads(result.stdout)


def form(path: str, fields: dict[str, str | int]) -> str:
    # Never accept complete URLs: authorization/DB writes must not escape
    # the local isolated Caddy listener.
    if (not path.startswith("/database/") or not path.endswith(".php")
            or ".." in path or "?" in path or "//" in path):
        raise ValueError("Refusing non-native local API path")
    data = {"gameVersion": "22", "binaryVersion": "51", **fields}
    req = urllib.request.Request(
        BASE + path,
        data=urllib.parse.urlencode(data).encode("utf-8"),
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    opener = urllib.request.build_opener(_NoRedirect())
    try:
        response = opener.open(req, timeout=20)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        status = response.status
        payload = response.read(1024 * 1024 + 1)
        mime = response.headers.get("Content-Type", "")
    require(status == 200, f"{path}: HTTP 200 game response (actual {status})")
    require(len(payload) <= 1024 * 1024, f"{path}: bounded HTTP reply")
    require("text/html" not in mime.lower() and
            b"<html" not in payload[:200].lower(),
            f"{path}: game protocol has no HTML contamination")
    return payload.decode("utf-8", errors="strict")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def wire_map(body: str) -> dict[str, str]:
    core = body.split("#", 1)[0]
    chunks = core.split(":")
    require(len(chunks) % 2 == 0, "native level response key/value parity")
    return dict(zip(chunks[0::2], chunks[1::2]))


def main() -> None:
    env = environment()
    native = json.loads(FIXTURE.read_text(encoding="utf-8"))
    captured = {row["path"] for row in native["routes"]}

    # Do not accidentally test fabricated paths under the label "from EXE."
    used = [
        "/database/accounts/loginGJAccount22.php",
        "/database/accounts/backupGJAccountNew.php",
        "/database/accounts/syncGJAccountNew.php",
        "/database/submitGJUserInfo.php",
        "/database/followGJUser.php",
        "/database/unfollowGJUser.php",
        "/database/getGJFollowing.php",
        "/database/syncGJFollowing.php",
        "/database/uploadGJLevel21.php",
        "/database/getGJLevels21.php",
        "/database/downloadGJLevel22.php",
        "/database/updateGJDesc20.php",
        "/database/deleteGJLevelUser20.php",
    ]
    # The archived manifest should justify every replayed native path.
    for route in used:
        require(route in captured, "saved native EXE contains " + route)

    aid1 = int(env["MUCHO_EXE_AUTH_AID1"])
    aid2 = int(env["MUCHO_EXE_AUTH_AID2"])
    token1 = env["MUCHO_EXE_AUTH_GJP2_A"]
    token2 = env["MUCHO_EXE_AUTH_GJP2_B"]
    username1 = env["MUCHO_EXE_AUTH_USERNAME_A"]
    username2 = env["MUCHO_EXE_AUTH_USERNAME_B"]
    auth1 = {"accountID": aid1, "gjp2": token1}
    auth2 = {"accountID": aid2, "gjp2": token2}
    bad_auth = {"accountID": aid1, "gjp2": token2}

    account1 = database("account", aid1)
    account2 = database("account", aid2)
    require(account1 is not None and account1["username"] == username1,
            "first real registered test account matches DB")
    require(account2 is not None and account2["username"] == username2,
            "second real registered test account matches DB")
    require(form("/database/accounts/loginGJAccount22.php",
                 {"userName": username1, "gjp2": token1})
            == f"{aid1},{account1['user_id']}",
            "EXE loginGJAccount22 returns exact account/user IDs")
    require(form("/database/accounts/loginGJAccount22.php",
                 {"userName": username1, "gjp2": token2}) == "-1",
            "EXE native login rejects another account's GJP2")

    # Native Cloud Save New aliases: ciphertext has a stored checksum and an
    # incrementing revision. Rejections must not change this persisted row.
    save = "GD2209-auth-replay-" + secrets.token_hex(12) + ";21;30;a;a"
    cloud_path = "/database/accounts/backupGJAccountNew.php"
    sync_path = "/database/accounts/syncGJAccountNew.php"
    before = database("cloud", aid1)
    require(form(cloud_path, {**auth1, "saveData": save}) == "1",
            "GD 2.209 New cloud backup succeeds with real fixture GJP2")
    after = database("cloud", aid1)
    require(after is not None and after["sha256"] == hashlib.sha256(save.encode()).hexdigest()
            and int(after["size_bytes"]) == len(save)
            and int(after["revision"]) == (int(before["revision"]) + 1 if before else 1),
            "cloud backup persisted an exact new revision and checksum")
    require(form(sync_path, auth1) == save,
            "GD 2.209 New sync returns exact cloud payload")
    require(form(cloud_path, {**bad_auth, "saveData": "tamper"}) == "-1",
            "cross-account cloud backup denied")
    require(form(cloud_path, {**auth1, "saveData": ""}) == "-1",
            "authenticated empty cloud backup denied")
    require(database("cloud", aid1) == after,
            "denied backup did not change encrypted cloud revision")
    require(form(sync_path, bad_auth) == "-1",
            "cross-account cloud download denied")

    # Native opaque user-info submission is stored independently of profile
    # statistics, and invalid credentials/empty bodies may not overwrite it.
    info_path = "/database/submitGJUserInfo.php"
    level_info = "12,87,1|33,100,2|91,45,1|x" + secrets.token_hex(5)
    require(form(info_path, {**auth1, "levelsInfo": level_info}) == "1",
            "GD 2.209 authenticated opaque user info submission accepted")
    stored = database("user_info", aid1)
    require(stored is not None and stored["levels_info"] == level_info
            and stored["payload_sha256"] == hashlib.sha256(level_info.encode()).hexdigest(),
            "user-info snapshot persisted exact bytes and SHA256")
    require(form(info_path, {**bad_auth, "levelsInfo": "tamper"}) == "-1",
            "user-info submission rejects wrong GJP2")
    require(form(info_path, {**auth1, "levelsInfo": ""}) == "-2",
            "native empty user-info status remains -2")
    require(database("user_info", aid1) == stored,
            "unauthorized/empty user info did not overwrite persisted snapshot")

    # The 2.209 follow family is provisional on the wire, but its account
    # authorization and SQL changes can be verified independently.
    follow = "/database/followGJUser.php"
    unfollow = "/database/unfollowGJUser.php"
    list_path = "/database/getGJFollowing.php"
    sync_follow = "/database/syncGJFollowing.php"
    follow_args = {**auth1, "toAccountID": aid2}
    original_follow = database("following", aid1, aid2)
    require(original_follow is not None and int(original_follow["total"]) == 0,
            "fixture accounts begin without an existing follow link")
    notification_before = database("follow_notifications", aid1, aid2)
    notification_count = int(notification_before["total"])
    require(form(follow, {**bad_auth, "toAccountID": aid2}) == "-1",
            "follow request with wrong GJP2 rejected")
    require(database("following", aid1, aid2) == original_follow,
            "invalid follow caused no SQL mutation")
    require(form(follow, follow_args) == "1",
            "authenticated GD 2.209 follow acknowledged")
    require(int(database("following", aid1, aid2)["total"]) == 1,
            "authenticated follow inserts exactly one SQL link")
    require(form(follow, follow_args) == "1",
            "duplicate follow is idempotent")
    require(int(database("following", aid1, aid2)["total"]) == 1,
            "duplicate follow has no duplicate SQL link")
    notices = database("follow_notifications", aid1, aid2)
    require(int(notices["total"]) == notification_count + 1,
            "recipient receives exactly one follow notification")
    listing = form(list_path, {**auth1, "page": 0, "ack": 1})
    require(username2 in listing and "#" in listing,
            "authenticated following list includes followed user and pagination")
    synced = form(sync_follow, {**auth1, "ack": 1, "follows": ""})
    require(username2 in synced,
            "EXE sync-following alias returns authoritative server snapshot")
    require(form(unfollow, follow_args) == "1",
            "authenticated GD 2.209 unfollow acknowledged")
    require(int(database("following", aid1, aid2)["total"]) == 0,
            "unfollow removed SQL link")
    require(form(list_path, {**auth1, "page": 0}) == "-2",
            "following list returns -2 after last link removed")

    # Recreate a synthetic level at the exact version-21 EXE path and check
    # native 2.209 compressed download framing (not merely route status).
    created_level = 0
    upload_path = "/database/uploadGJLevel21.php"
    download_path = "/database/downloadGJLevel22.php"
    delete_path = "/database/deleteGJLevelUser20.php"
    level_name = "Exe2209Auth" + secrets.token_hex(5)
    encoded_desc = base64.b64encode(b"GD 2.209 authenticated emulator").decode()
    upload = {**auth1, "levelName": level_name, "levelString": LEVEL_STRING,
              "levelDesc": encoded_desc, "objects": "1", "levelVersion": "1"}
    try:
        created = form(upload_path, upload)
        require(created.isdecimal() and int(created) > 0,
                "authenticated native 2.209 level upload returns positive ID")
        created_level = int(created)
        row = database("level", created_level)
        require(row is not None and int(row["account_id"]) == aid1
                and row["name"] == level_name and row["level_data"] == LEVEL_STRING
                and int(row["is_deleted"]) == 0,
                "native level upload writes expected owner/name/bytes to SQL")
        require(form(upload_path, {**upload, **bad_auth,
                                  "levelID": created_level, "levelName": "TAKEOVER"}) == "-1",
                "foreign GJP2 cannot replace an owned level")
        require(database("level", created_level) == row,
                "failed ownership takeover leaves level row unchanged")
        result = form("/database/getGJLevels21.php",
                      {"type": 0, "str": str(created_level), "page": 0})
        require("1:" + str(created_level) + ":" in result,
                "client-version 2.209 level search sees fresh upload")
        fetched = form(download_path, {"levelID": created_level, "inc": 0, "extras": 1})
        parts = wire_map(fetched)
        require(parts.get("1") == str(created_level)
                and parts.get("6") == str(account1["user_id"]),
                "2.209 download wire preserves level ID and author user ID")
        encoded = parts.get("4", "")
        decoded = zlib.decompress(base64.urlsafe_b64decode(
            encoded + "=" * (-len(encoded) % 4))).decode()
        require(decoded == LEVEL_STRING, "2.209 download decodes to exact editor bytes")
        require("62" in parts and "63" in parts,
                "2.209 download wire contains timestamps 62 and 63")

        # Avoid accepting a forged client-provided rating on level rewrite.
        require(form(upload_path, {**upload, "levelID": created_level,
                                   "stars": 10, "featured": 1}) == str(created_level),
                "legitimate owned level reupload is accepted")
        after_reupload = database("level", created_level)
        require(after_reupload is not None
                and after_reupload["name"] == level_name
                and int(after_reupload["account_id"]) == aid1,
                "owned level reupload keeps ownership")

        require(form(delete_path, {**auth2, "levelID": created_level}) == "-1",
                "other authenticated player cannot delete first player's level")
        require(int(database("level", created_level)["is_deleted"]) == 0,
                "foreign delete denial leaves SQL row active")
        require(form(delete_path, {**auth1, "levelID": created_level}) == "1",
                "level owner can delete own test level")
        require(int(database("level", created_level)["is_deleted"]) == 1,
                "level deletion is persisted as soft-delete")
        require(form(download_path, {"levelID": created_level}) == "-1",
                "deleted level becomes unavailable over GD 2.209 HTTP")
    finally:
        if created_level > 0:
            record = database("level", created_level)
            if record is not None and int(record["is_deleted"]) == 0:
                form(delete_path, {**auth1, "levelID": created_level})

    print(
        "MUCHOCORE_GD2209_EXE_AUTH_SQL_OK "
        f"assertions={checks} "
        "families=login,cloudsave,userinfo,following,levels "
        "native_client_certified=false",
        flush=True,
    )


if __name__ == "__main__":
    main()
