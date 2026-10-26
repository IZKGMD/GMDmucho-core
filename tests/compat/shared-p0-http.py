"""Real shared front-controller P0 regression on isolated local MariaDB.

Synthetic HTTP/SQL evidence only: this does not certify a native GD client.
No production URL, database or credential is accepted by this runner.
"""
import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib

REPO = Path(__file__).resolve().parents[2]
PASSWORD = "P0Fixture-1!"
GJP2 = hashlib.sha1((PASSWORD + "mI29fmAnxgTs").encode()).hexdigest()
VERSION = dict(gameVersion=22, binaryVersion=51)
PAYLOAD = "kS1,1,kA2,0;1,1,2,15,3,15;"


def require(ok, reason):
    if not ok:
        raise AssertionError(reason)


def fields(text, delimiter=":"):
    parts = text.split(delimiter)
    require(len(parts) % 2 == 0, "Invalid protocol key/value framing")
    return dict(zip(parts[::2], parts[1::2]))


def run(php, report):
    require(os.environ.get("TEST_DB_HOST") == "127.0.0.1", "Local test database required")
    with tempfile.TemporaryDirectory(prefix="mucho-p0-http-") as temporary:
        root = Path(temporary)
        (root / "config").mkdir(mode=0o700)
        (root / "public").mkdir()
        (root / "compat").symlink_to(REPO / "compat", target_is_directory=True)
        shutil.copyfile(REPO / "public/php7.php", root / "public/php7.php")
        (root / "public/php7-mode.on").write_text("fixture")
        helper = REPO / "tests/compat/php7-shared-p0-fixture.php"

        def fixture(operation):
            result = subprocess.run([php, str(helper), operation, str(root)], check=True,
                                    capture_output=True, text=True)
            return json.loads(result.stdout) if operation in ("inspect", "account-change-inspect") else result.stdout

        router = root / "router.php"
        # Only the loopback test server treats its own HTTP connection as HTTPS.
        # No request header can choose this behavior in the deployed runtime.
        router.write_text("<?php $_SERVER['HTTPS']='on';"
                          "$_SERVER['SCRIPT_NAME']='/mc/public/php7.php';"
                          "header('X-P0-OPcache: '.(function_exists('opcache_get_status')&&opcache_get_status(false)!==false?'enabled':'missing'));"
                          "require __DIR__.'/public/php7.php';")
        servers, logs, ports = [], [], []
        timings = {name: [] for name in ("account.login", "levels.search", "levels.upload",
                                        "levels.download", "users.leaderboard", "comments.read")}
        checks = []
        try:
            fixture("create")
            # Isolate protocol/authorization regressions from deliberate WAF
            # burst limits. Dedicated MuchoProtect tests exercise those limits.
            env = dict(os.environ, MUCHO_PROTECT="0", MUCHO_FILE_CACHE_DIR=str(root / "cache"))
            for index in range(3):
                with socket.socket() as probe:
                    probe.bind(("127.0.0.1", 0))
                    port = probe.getsockname()[1]
                log = (root / ("server-%d.log" % index)).open("wb")
                server = subprocess.Popen([php, "-d", "memory_limit=128M", "-d", "opcache.enable_cli=1", "-d", "opcache.validate_timestamps=0", "-d", "opcache.file_update_protection=0", "-d",
                    "disable_functions=exec,shell_exec,system,passthru,proc_open,popen",
                    "-S", "127.0.0.1:%d" % port, str(router)], env=env, stdout=log, stderr=log)
                servers.append(server); logs.append(log); ports.append(port)
                for attempt in range(100):
                    require(server.poll() is None, "HTTP fixture did not start")
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.02)
                else:
                    raise RuntimeError("HTTP fixture startup timed out")

            def post(path, data, endpoint=None, worker=0, allowed_status=(200,)):
                body = urllib.parse.urlencode(data).encode()
                request = urllib.request.Request("http://127.0.0.1:%d%s" % (ports[worker], path),
                                                 data=body, method="POST")
                start = time.monotonic()
                try:
                    response = urllib.request.urlopen(request, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    text = response.read().decode()
                    require(response.status in allowed_status, "Unexpected HTTP status: %s %s" % (path, response.status))
                    require(response.headers.get("X-Content-Type-Options") == "nosniff", "Missing response hardening")
                    require(response.headers.get("X-P0-OPcache") == "enabled", "OPcache-enabled regression fixture is required")
                    require(text == text.strip() and not text.startswith("<"), "Contaminated game response")
                if endpoint:
                    timings[endpoint].append((time.monotonic() - start) * 1000)
                return text

            login = dict(VERSION, userName="P0Owner", password=PASSWORD)
            require(post("/a/accounts/loginGJAccount22.php", login, "account.login") == "1,101", "Password login IDs")
            require(post("/database/loginGJAccount20.php", dict(VERSION, userName="P0Owner", gjp2=GJP2)) == "1,101", "GJP2 login")
            for name, secret in (("P0Owner", "wrong"), ("P0Banned", "P0Fixture-3!"), ("P0Inactive", "P0Fixture-4!")):
                require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName=name, password=secret)) == "-1", "Rejected login became valid")
            checks.append("password/gjp2/identity/banned/inactive/wrong-password")

            recovery_before = fixture("inspect")
            for path in ("/a/accounts/lostpassword.php", "/database/accounts/lostusername.php"):
                request = urllib.request.Request("http://127.0.0.1:%d%s" % (ports[0], path))
                with urllib.request.urlopen(request, timeout=15) as response:
                    html = response.read().decode()
                    require(response.status == 200 and "text/html" in response.headers.get("Content-Type", ""),
                            "Native account recovery page failed")
                    require('<html lang="en">' in html and 'name="csrf"' in html
                            and 'name="identity"' in html and 'Send recovery link' in html,
                            "Native recovery page language/form/CSRF")
                    require('/mc/public/recovery/recovery.css' in html, "Recovery assets lost installation mount")
            require(fixture("inspect") == recovery_before, "Opening recovery changed account data")
            checks.append("native-lost-password-and-username/english-html/csrf/mounted-assets/no-mutation")

            upload = dict(VERSION, accountID=1, gjp2=GJP2, levelName="P0RoundTrip",
                          levelString=PAYLOAD, levelDesc=base64.b64encode(b"P0 description").decode(),
                          levelInfo="editor-info", settingsString="1,2,3,4", songIDs="123,456",
                          sfxIDs="789,901", objects=1, levelVersion=1)
            level = post("/a/uploadGJLevel21.php", upload, "levels.upload")
            require(level.isdigit() and int(level) > 0, "Upload rejected")
            saved = fixture("inspect")["levels"][0]
            for key, expected in (("level_data", PAYLOAD), ("level_info", "editor-info"),
                                  ("settings_string", "1,2,3,4"), ("song_ids", "123,456"), ("sfx_ids", "789,901")):
                require(saved[key] == expected, "Upload lost " + key)
            require(post("/database/uploadGJLevel21.php", dict(upload, levelID=level, stars=10, featured=1)) == level,
                    "Idempotent owner update rejected")
            before = fixture("inspect")
            other = hashlib.sha1(b"P0Fixture-2!mI29fmAnxgTs").hexdigest()
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, accountID=2, gjp2=other)) == "-1", "Foreign level overwrite")
            require(post("/a/uploadGJLevel21.php", dict(upload, gjp2="wrong")) == "-1", "Bad upload credential")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelString="")) == "-1", "Empty level accepted")
            require(fixture("inspect") == before, "Rejected upload changed database")
            require(int(saved["stars"]) == 0 and int(fixture("inspect")["levels"][0]["stars"]) == 0, "Client self-rated a level")
            checks.append("upload/metadata/ownership/invalid-payload/self-rating/database-integrity")

            # Valid credentials must not let malformed editor fields reach a
            # successful update. The unauthenticated inventory sweep cannot
            # exercise these checks because authentication fails first.
            malformed_uploads = [
                {key + "[]": "1"} for key in ("auto", "twoPlayer", "ldm", "unlisted", "unlisted1", "unlisted2")
            ] + [
                {"auto": "2"}, {"unlisted2": "invalid"},
                {"wt2": str(2 ** 100)}, {"original": "9" * 100},
                {"songID": "0" * 10 + str(2 ** 100)},
                {"settingsString[]": "invalid"}, {"songIDs[]": "invalid"}, {"sfxIDs[]": "invalid"},
            ]
            for malformed in malformed_uploads:
                invalid = dict(upload, levelID=level, levelName="RejectMalformed")
                invalid.update(malformed)
                require(post("/a/uploadGJLevel21.php", invalid) == "-1",
                        "Authenticated malformed upload accepted: " + ",".join(malformed))
                require(fixture("inspect") == before, "Malformed authenticated upload changed database")
            checks.append("authenticated-editor-array/boolean/integer-overflow-rejection/no-mutation")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, wt2="00042", auto=1)) == level,
                    "Valid numeric/boolean metadata rejected")
            accepted = fixture("inspect")["levels"][0]
            require(int(accepted["wt2"]) == 42 and int(accepted["auto_level"]) == 1,
                    "Valid numeric/boolean metadata changed")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, auto="")) == level,
                    "Empty optional boolean compatibility rejected")

            download_request = dict(VERSION, levelID=level, extras=1, inc=0)
            downloaded = fields(post("/a/downloadGJLevel22.php", download_request, "levels.download").split("#")[0])
            compressed = downloaded["4"]
            require(zlib.decompress(base64.urlsafe_b64decode(compressed + "=" * (-len(compressed) % 4))).decode() == PAYLOAD,
                    "Downloaded level bytes changed")
            for key, expected in (("1", level), ("6", "101"), ("48", "1,2,3,4"), ("52", "123,456"), ("53", "789,901")):
                require(downloaded[key] == expected, "Download metadata mismatch: " + key)
            require(int(downloaded["62"]) > 0 and int(downloaded["63"]) > 0, "Missing 2.209 timestamps")
            listing = post("/database/getGJLevels21.php", dict(VERSION, str="P0RoundTrip", type=0), "levels.search")
            item = fields(listing.split("#")[0])
            require(item["1"] == level and item["2"] == "P0RoundTrip" and item["6"] == "101", "Search wire identity")
            require(PAYLOAD not in listing and "#1:0:10#" in listing, "Search payload/pagination")
            require(post("/a/getGJLevels21.php", dict(VERSION, str="does-not-exist", type=0)) == "-2", "Empty search framing")
            require(post("/a/getGJLevels21.php", dict(VERSION, str="x" * 101, type=0)) == "-1", "Oversized search accepted")
            require(post("/a/downloadGJLevel22.php", dict(VERSION, levelID=99999999)) == "-1", "Missing level download")
            scores = post("/a/getGJScores20.php", dict(VERSION, type="top", count=10), "users.leaderboard")
            rows = [fields(row) for row in scores.split("|")]
            require([row["1"] for row in rows] == ["P0Other", "P0Owner"], "Banned/inactive leaderboard or order")
            require(post("/a/getGJScores20.php", dict(VERSION, type="friends", accountID=1, gjp2="wrong")) == "-1", "Friends leaderboard authentication")
            checks.append("search/download/byte-roundtrip/timestamps/leaderboard/empty-results")

            comment = base64.b64encode(b"P0 comment").decode()
            require(post("/a/uploadGJComment21.php", dict(VERSION, accountID=1, gjp2=GJP2, levelID=level, comment=comment)) not in ("-1", "-2"), "Comment upload rejected")
            comments = post("/a/getGJComments21.php", dict(VERSION, levelID=level, count=10), "comments.read")
            require(comments.endswith("#1:0:10") and "2~" + comment in comments and "~15~" in comments,
                    "Comment framing/content/timestamp")
            require(post("/a/getGJComments21.php", dict(VERSION, levelID=level, page=1)) == "-2", "Empty comment page")
            checks.append("comment-write/read/content/timestamps/pagination")

            fixture("friendships")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, unlisted2=1)) == level, "Private update rejected")
            require(post("/a/downloadGJLevel22.php", download_request) == "-1", "Private download was public")
            friend_request = dict(download_request, accountID=2, gjp2=other)
            require(post("/a/downloadGJLevel22.php", friend_request) != "-1", "Friend could not download private level")
            require(post("/a/getGJLevels21.php", dict(VERSION, str="P0RoundTrip", type=0)) == "-2", "Private level leaked in search")
            require(post("/a/getGJComments21.php", dict(VERSION, levelID=level)) == "-2", "Private comments were public")
            require("2~" + comment in post("/a/getGJComments21.php", dict(VERSION, levelID=level, accountID=2, gjp2=other)),
                    "Friend could not read private comments")
            require(post("/a/getGJComments21.php", dict(VERSION, userID=101)) == "-2", "Private comment leaked into user history")
            require(post("/a/getGJCommentHistory.php", dict(VERSION, userID=101)) == "-2", "Private comment leaked into history route")
            require(post("/a/downloadGJLevel22.php", dict(friend_request, gjp2="wrong")) == "-1", "Friendship replaced password proof")
            fixture("clear-friendships")
            require(post("/a/downloadGJLevel22.php", friend_request) == "-1", "Revoked friend retained download access")
            require(post("/a/getGJComments21.php", dict(VERSION, levelID=level, accountID=2, gjp2=other)) == "-2", "Revoked friend retained comment access")
            require(post("/a/getLevelRateInfo.php", dict(VERSION, levelID=level, accountID=2, gjp2=other)) == "-1",
                    "Revoked friend retained rating information access")
            before_private = fixture("inspect")
            require(post("/a/uploadGJComment21.php", dict(VERSION, accountID=2, gjp2=other, levelID=level, comment=comment)) == "-1", "Nonfriend posted on private level")
            require(fixture("inspect") == before_private, "Rejected private comment changed data")
            require(post("/a/downloadGJLevel22.php", dict(download_request, accountID=1, gjp2=GJP2)) != "-1", "Owner lost private access")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, unlisted2=0)) == level, "Public update rejected")
            checks.append("friend-only-download/search/comment-authorization")

            # Three independent PHP processes exercise real simultaneous DB access,
            # including on PHP 7.0 (which has no CLI server worker pool).
            def concurrent_download(index):
                return post("/a/downloadGJLevel22.php", dict(download_request, inc=1), worker=index % 3)
            with ThreadPoolExecutor(max_workers=6) as pool:
                results = list(pool.map(concurrent_download, range(12)))
            require(all(fields(body.split("#")[0])["1"] == level for body in results), "Concurrent download response")
            state = fixture("inspect")
            require(int(state["levels"][0]["downloads"]) == 2 and state["download_slots"] == 2,
                    "Concurrent download counter/dedupe: %s/%s" % (state["levels"][0]["downloads"], state["download_slots"]))
            fixture("read-only")
            require(post("/a/uploadGJLevel21.php", upload) == "-1", "Read-only upload allowed")
            require(fields(post("/a/downloadGJLevel22.php", dict(download_request, inc=1)).split("#")[0])["1"] == level,
                    "Read-only download unavailable")
            require(fixture("inspect") == state, "Read-only request changed data")
            fixture("writable")
            checks.append("concurrent-download-dedupe/read-only-write-guard")

            def concurrent_login(index):
                return post("/a/accounts/loginGJAccount22.php", login, worker=index % 3)
            with ThreadPoolExecutor(max_workers=6) as pool:
                require(set(pool.map(concurrent_login, range(12))) == {"1,101"}, "Concurrent login identity changed")
            require(fixture("inspect")["profiles"] == 4, "Concurrent logins duplicated profiles")

            def concurrent_update(index):
                return post("/a/uploadGJLevel21.php", dict(upload, levelID=level,
                    settingsString="settings-%d" % index, songIDs=str(1000 + index), sfxIDs=str(2000 + index)),
                    worker=index % 3)
            with ThreadPoolExecutor(max_workers=6) as pool:
                require(set(pool.map(concurrent_update, range(6))) == {level}, "Concurrent owner update rejected")
            current = fixture("inspect")["levels"][0]
            require((current["settings_string"], current["song_ids"], current["sfx_ids"]) in
                    {("settings-%d" % index, str(1000 + index), str(2000 + index)) for index in range(6)},
                    "Concurrent update mixed editor metadata from different requests")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level)) == level, "Restore test metadata")
            checks.append("concurrent-login-profile-identity/atomic-editor-update")

            # Exercise protocol generation boundaries over real HTTP without
            # treating declared request versions as proof of a native client.
            legacy = post("/a/uploadGJLevel.php", dict(upload, gameVersion=1, binaryVersion=0,
                          levelName="P0Legacy", levelDesc=base64.b64encode(b"Legacy description").decode()))
            require(legacy.isdigit(), "Legacy-compatible fixture upload")
            for game, binary in ((1, 0), (11, 0), (15, 0), (19, 0), (20, 27), (21, 35), (22, 47), (22, 51)):
                version = dict(gameVersion=game, binaryVersion=binary)
                text = post("/database/getGJLevels21.php", dict(version, str="P0Legacy", type=0))
                require(fields(text.split("#")[0])["1"] == legacy, "Versioned level search")
                data = fields(post("/a/downloadGJLevel22.php", dict(version, levelID=legacy, inc=0)).split("#")[0])
                if game <= 18:
                    require(data["4"] == PAYLOAD, "Legacy raw level payload")
                else:
                    encoded = data["4"]
                    require(zlib.decompress(base64.urlsafe_b64decode(encoded + "=" * (-len(encoded) % 4))).decode() == PAYLOAD,
                            "Modern compressed level payload")
                require(("62" in data) == (game >= 22 and binary >= 51), "2.209 timestamp version boundary")
            checks.append("synthetic-http-wire-matrix-1.0/1.1/1.5/1.9/2.0/2.1/2.2/2.209")

            inventory = json.loads((REPO / "tests/protocol/endpoint-contracts.json").read_text())["contracts"]
            baseline = fixture("inspect")["business_sha256"]
            for contract in inventory:
                for malformed in (False, True):
                    data = dict(VERSION)
                    if malformed:
                        data.update({key + "[]": "invalid" for key in
                                     ("accountID", "gjp", "gjp2", "userName", "password", "levelID", "type", "page")})
                    response = post(contract["canonical_path"], data, allowed_status=(200, 400, 401, 403, 404, 405, 422))
                    require(not any(secret in response for secret in
                                    ("SQLSTATE", "PDOException", "Fatal error", "Warning:", "Stack trace")),
                            "Public diagnostic leak: " + contract["id"])
            require(fixture("inspect")["business_sha256"] == baseline,
                    "Unauthenticated/malformed route sweep mutated core business data")
            checks.append("all-%d-routes/missing-credentials/array-inputs/no-public-diagnostics/no-business-mutation" % len(inventory))

            xor_password = base64.urlsafe_b64encode(bytes(
                value ^ b"37526"[index % 5] for index, value in enumerate(PASSWORD.encode())
            )).decode()
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, gjp2="", gjp=xor_password,
                         gameVersion=21, binaryVersion=35)) == level, "Legacy GJP owner update rejected")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level)) == level,
                    "Restore modern level version")
            alias_count = 0
            for contract in inventory:
                endpoint = contract["id"]
                if endpoint not in timings:
                    continue
                for alias in dict.fromkeys([contract["canonical_path"]] + contract["aliases"]):
                    if endpoint == "account.login":
                        require(post(alias, login) == "1,101", "Login alias: " + alias)
                        require(post(alias, dict(login, password="wrong")) == "-1", "Bad login alias: " + alias)
                    elif endpoint == "levels.upload":
                        require(post(alias, dict(upload, levelID=level)) == level, "Upload alias: " + alias)
                        state_before = fixture("inspect")["business_sha256"]
                        require(post(alias, dict(upload, levelID=level, gjp2="wrong")) == "-1",
                                "Bad upload credential alias: " + alias)
                        require(fixture("inspect")["business_sha256"] == state_before,
                                "Rejected upload alias changed data: " + alias)
                    elif endpoint == "levels.search":
                        value = post(alias, dict(VERSION, str="P0RoundTrip", type=0))
                        require(fields(value.split("#")[0])["1"] == level, "Search alias: " + alias)
                    elif endpoint == "levels.download":
                        value = post(alias, download_request)
                        require(fields(value.split("#")[0])["1"] == level, "Download alias: " + alias)
                    elif endpoint == "users.leaderboard":
                        value = post(alias, dict(VERSION, type="top", count=10))
                        require([fields(row)["1"] for row in value.split("|")] == ["P0Other", "P0Owner"],
                                "Leaderboard alias: " + alias)
                    else:
                        value = post(alias, dict(VERSION, levelID=level, count=10))
                        require("2~" + comment in value and value.endswith("#1:0:10"), "Comments alias: " + alias)
                    alias_count += 1
            checks.append("p0-%d-canonical-and-alias-success-paths/credential-rejection/legacy-gjp" % alias_count)

            # The native client's star rating and demon subtype are separate
            # requests. Warm the exact search result before changing subtype.
            fixture("owner-role")
            rating_auth = dict(VERSION, accountID=1, gjp2=GJP2, levelID=level)
            require(post("/a/rateGJStars211.php", dict(rating_auth, stars=10)) == "1", "Client star rate rejected")
            rating_search = dict(VERSION, str=level, type=0)
            require(fields(post("/a/getGJLevels21.php", rating_search).split("#")[0])["43"] == "0",
                    "Initial hard-demon response")
            require(post("/a/rateGJDemon21.php", dict(rating_auth, rating=5)) == level, "Extreme demon rate rejected")
            require(int(fixture("inspect")["levels"][0]["demon_difficulty"]) == 6, "Extreme demon storage mapping")
            require(fields(post("/a/downloadGJLevel22.php", download_request).split("#")[0])["43"] == "6",
                    "Extreme demon download wire mapping")
            require(fields(post("/a/getGJLevels21.php", rating_search).split("#")[0])["43"] == "6",
                    "Client demon rate left hard demon in warm search cache")
            for rating, wire in ((1, "3"), (2, "4"), (3, "0"), (4, "5"), (5, "6")):
                require(post("/a/rateGJDemon21.php", dict(rating_auth, rating=rating)) == level, "Client demon subtype rate")
                require(post("/a/rateGJStars211.php", dict(rating_auth, stars=10)) == "1", "Repeat 10-star rate")
                stored = fixture("inspect")["levels"][0]
                require(str(stored["demon_difficulty"]) == wire, "10-star refresh erased demon subtype")
                require(fields(post("/a/getGJLevels21.php", rating_search).split("#")[0])["43"] == wire,
                        "Rated demon list wire mapping")
                require(fields(post("/a/downloadGJLevel22.php", download_request).split("#")[0])["43"] == wire,
                        "Rated demon download wire mapping")
            require(post("/database/rateGJDemon21.php", dict(rating_auth, rating=5, gameVersion=21,
                         binaryVersion=35, gjp2="", gjp=xor_password)) == level, "Legacy GJP demon rate rejected")
            require(post("/database/rateGJStars20.php", dict(rating_auth, stars=10)) == "1", "Old star-rate alias rejected")
            require(str(fixture("inspect")["levels"][0]["demon_difficulty"]) == "6", "Old star alias erased extreme demon")
            migration_before = fixture("inspect")["business_sha256"]
            fixture("repeat-rating-schema")
            require(fixture("inspect")["business_sha256"] == migration_before,
                    "Repeated rating schema repair changed existing data")
            rejected_before = fixture("inspect")["business_sha256"]
            for invalid in (dict(rating_auth, rating=0), dict(rating_auth, rating=9),
                            dict(rating_auth, rating=5, gjp2="wrong"),
                            dict(rating_auth, rating=5, accountID=2, gjp2=other)):
                require(post("/a/rateGJDemon21.php", invalid) == "-1", "Invalid/unauthorized demon rate accepted")
            require(fixture("inspect")["business_sha256"] == rejected_before, "Rejected demon rate mutated data")
            require(post("/a/suggestGJStars20.php", dict(rating_auth, stars=10, feature=2)) == "1", "Demon quality rate")
            require(str(fixture("inspect")["levels"][0]["demon_difficulty"]) == "6", "Quality rate erased extreme demon")
            require(post("/a/suggestGJStars20.php", dict(rating_auth, stars=9, feature=0)) == "1", "Non-demon quality rate")
            cleared = fixture("inspect")["levels"][0]
            require(int(cleared["demon"]) == 0 and int(cleared["demon_difficulty"]) == 0,
                    "Non-demon rate retained a demon subtype")
            fixture("user-role")
            checks.append("client-demon-rates/all-five-subtypes/warm-cache/star-refresh/permissions")

            # Native RateInfoPopup consumes two colon sections and comma counts,
            # not the level-list key/value format or a generic success marker.
            info_auth = dict(VERSION, accountID=1, gjp2=GJP2, levelID=level)
            zero_info = ",".join(["0"] * 10) + ":" + ",".join(["0"] * 5)
            require(post("/database/getLevelRateInfo.php", info_auth) == zero_info,
                    "Empty native rating histogram framing")
            fixture("advisor-role")
            for stars, tier in ((7, 1), (10, 4)):
                require(post("/a/suggestGJStars20.php", dict(info_auth, stars=stars, feature=tier)) == "1",
                        "Advisor suggestion fixture rejected")
            expected_info = ",".join(["0"] * 9 + ["1"]) + ":0,0,0,1,0"
            require(post("/a/getLevelRateInfo.php", info_auth) == expected_info,
                    "Rating histogram must use the latest real suggestion per advisor")
            info_before = fixture("inspect")["business_sha256"]
            for invalid in (dict(info_auth, gjp2="wrong"), dict(info_auth, levelID=99999999),
                            dict(info_auth, levelID="9" * 100),
                            dict(info_auth, **{"levelID[]": "1"})):
                require(post("/a/getLevelRateInfo.php", invalid) == "-1", "Invalid rating info accepted")
            fixture("read-only")
            require(post("/a/getLevelRateInfo.php", info_auth) == expected_info,
                    "Read-only server blocked rating information")
            fixture("writable")
            require(fixture("inspect")["business_sha256"] == info_before, "Rating info mutated level data")
            checks.append("native-rate-info/two-histograms/latest-advisor-vote/auth/read-only/no-mutation")

            fixture("read-only")
            refresh_before = fixture("inspect")
            saved_refresh = post("/database/getSaveData.php", dict(VERSION, secret="Wmfd2893gb7"))
            require(len(saved_refresh) > 40 and zlib.decompress(base64.urlsafe_b64decode(
                    saved_refresh[20:-20])).decode() == level + ",9", "Native saved-level rating refresh framing/data")
            require(post("/a/getSaveData.php", dict(VERSION, secret="wrong")) == "-1", "Bad rating refresh secret accepted")
            require(fixture("inspect") == refresh_before, "Read-only rating refresh changed data")
            fixture("writable")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, unlisted2=1)) == level,
                    "Rated private level fixture rejected")
            private_refresh = post("/a/getSaveData.php", dict(VERSION, secret="Wmfd2893gb7"))
            require(zlib.decompress(base64.urlsafe_b64decode(private_refresh[20:-20])) == b"",
                    "Saved-level refresh leaked rated friend-only level")
            require(post("/a/uploadGJLevel21.php", dict(upload, levelID=level, unlisted2=0)) == level,
                    "Rated public level restore rejected")
            checks.append("native-saved-level-ratings/zlib-base64-20-byte-padding/read-only/private/no-account-save-leak")

            # Queue mute is a moderation-queue filter. It must retain reports,
            # player access and comments rather than banning the target.
            queue_level = post("/a/uploadGJLevel21.php", dict(upload, accountID=2, gjp2=other,
                               levelName="QueueFixture"))
            require(queue_level.isdigit(), "Queue level fixture rejected")
            require(post("/a/reportGJContent.php", dict(VERSION, accountID=1, gjp2=GJP2,
                         type=1, targetID=queue_level, special=0, reasons="0")) == "1", "Queue report fixture rejected")
            queue_auth = dict(VERSION, accountID=1, gjp2=GJP2, targetAccountID=2)
            require(post("/a/adminQueueMute.php", queue_auth) == "-1", "Advisor queue mute accepted")
            fixture("owner-role")
            require(post("/a/adminGetReportQueue.php", queue_auth).startswith("1:0:20#2:"),
                    "Reported account missing from queue")
            before_mute = fixture("inspect")
            for invalid in (dict(queue_auth, gjp2="wrong"), dict(queue_auth, targetAccountID=1),
                            dict(queue_auth, targetAccountID=999999), dict(queue_auth, durationMinutes="invalid"),
                            dict(queue_auth, **{"targetAccountID[]": "2"})):
                require(post("/a/adminQueueMute.php", invalid) == "-1", "Invalid queue mute accepted")
            require(fixture("inspect") == before_mute, "Rejected queue mute changed database")
            fixture("read-only")
            require(post("/a/adminQueueMute.php", queue_auth) == "-1", "Read-only queue mutation accepted")
            fixture("writable")
            require(post("/database/adminQueueMute.php", queue_auth) == "1", "Native queue mute failed")
            require(post("/a/adminGetReportQueue.php", queue_auth) == "0:0:20#", "Muted account remained in queue")
            after_mute = fixture("inspect")
            require(after_mute["business_sha256"] == before_mute["business_sha256"]
                    and after_mute["pending_reports"] == before_mute["pending_reports"]
                    and after_mute["queue_mutes"] == 1, "Queue mute changed player data or erased reports")
            require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName="P0Other",
                         password="P0Fixture-2!")) == "2,102", "Queue mute disabled player account")
            require(post("/a/adminQueueUnmute.php", queue_auth) == "1", "Native queue unmute failed")
            require(post("/a/adminGetReportQueue.php", queue_auth).startswith("1:0:20#2:"), "Unmute did not restore queue entry")
            require(fixture("inspect")["queue_audit"] == ["QUEUE_MUTE", "QUEUE_UNMUTE"], "Queue audit missing")
            require(post("/a/adminQueueUnmute.php", queue_auth) == "-1", "Absent mute reported as removed")
            fixture("user-role")
            checks.append("native-queue-mute/permissions/validation/read-only/filter/retain-reports/player-login/audit/unmute")

            # Local wall-clock observations only, deliberately excluded from the
            # native-client evidence directory and release certification registry.
            for unused in range(30):
                post("/a/getGJLevels21.php", dict(VERSION, str="P0RoundTrip", type=0), "levels.search")
                post("/a/downloadGJLevel22.php", download_request, "levels.download")
                post("/a/getGJScores20.php", dict(VERSION, type="top"), "users.leaderboard")
                post("/a/getGJComments21.php", dict(VERSION, levelID=level), "comments.read")

            # Exercise the browser endpoint through the same shared entry point,
            # with cookies and CSRF. Never accept an accountID/GJP2 as a browser
            # password or let one player's submitted ID select another account.
            cookies = ""

            def account_page(path="/database/accounts/accountManagement.php", data=None):
                nonlocal cookies
                request = urllib.request.Request("http://127.0.0.1:%d%s" % (ports[0], path),
                    data=None if data is None else urllib.parse.urlencode(data).encode(),
                    headers={"Cookie": cookies})
                try:
                    response = urllib.request.urlopen(request, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    page = response.read().decode()
                    for cookie in response.headers.get_all("Set-Cookie", []):
                        if cookie.startswith("MUCHO_ACCOUNT="):
                            require("HttpOnly" in cookie and "secure" in cookie.lower(), "Insecure player CSRF cookie")
                            cookies = cookie.split(";", 1)[0]
                    if page.startswith("<!doctype"):
                        require(response.headers.get_content_type() == "text/html", "Account page MIME type")
                        require(response.headers.get("Referrer-Policy") == "no-referrer", "Account referrer policy")
                        require("form-action 'self'" in response.headers.get("Content-Security-Policy", ""), "Account CSP")
                        require("no-store" in response.headers.get("Cache-Control", ""), "Account page cached")
                    return response.status, page

            status, page = account_page()
            require(status == 200 and '<html lang="en">' in page and "Account management" in page, "Native browser account page missing")
            require('/mc/public/recovery/recovery.css' in page and '/mc/accounts/lostpassword.php' in page,
                    "Shared account links escaped the mount")
            token = re.search(r'name="csrf" value="([a-f0-9]{64})"', page).group(1)
            change = dict(csrf=token, username="P0Other", current_password="P0Fixture-2!",
                          password="ChangedFixture-2!", password_confirmation="ChangedFixture-2!", accountID=1)
            before_change = fixture("inspect")["business_sha256"]
            status, page = account_page(data=dict(change, csrf="wrong"))
            require(status == 403 and "page has expired" in page, "Invalid account CSRF accepted")
            for invalid in (dict(change, current_password="incorrect"),
                            dict(change, current_password=other),
                            dict(change, password_confirmation="different"),
                            dict(change, password="x" * 73, password_confirmation="x" * 73),
                            dict(change, password="nul\0password", password_confirmation="nul\0password"),
                            dict(change, username="P0Banned", current_password="P0Fixture-3!"),
                            dict(change, username="P0Inactive", current_password="P0Fixture-4!"),
                            dict(change, username="")):
                status, page = account_page(data=invalid)
                require(status == 200 and "Unable to change the password" in page, "Invalid browser password change accepted")
                require(invalid["current_password"] not in page and change["password"] not in page,
                        "Account form reflected a password")
            require(fixture("inspect")["business_sha256"] == before_change, "Rejected account change modified player data")
            fixture("read-only")
            require(account_page(data=change)[1] == "-1", "Read-only account change accepted")
            fixture("writable")
            fixture("account-change-seed")
            fixture("account-change-fail-audit")
            status, page = account_page(data=change)
            require(status == 503 and "temporarily unavailable" in page and "Fixture audit failure" not in page,
                    "Failed password transaction leaked an error or reported success")
            require(fixture("inspect")["business_sha256"] == before_change
                    and fixture("account-change-inspect") == dict(sessions=1, recovery=1, audit=0),
                    "Failed password change did not roll back hashes and revocations")
            fixture("account-change-restore-audit")
            status, page = account_page(data=change)
            require(status == 200 and "Password changed." in page, "Browser password change failed")
            after_change = fixture("account-change-inspect")
            require(after_change == dict(sessions=0, recovery=0, audit=1), "Password change did not atomically revoke/audit")
            require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName="P0Other", password="P0Fixture-2!")) == "-1", "Old password still authenticates")
            require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName="P0Other", gjp2=other)) == "-1", "Old GJP2 still authenticates")
            require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName="P0Other", password=change["password"])) == "2,102", "New password does not authenticate")
            new_gjp2 = hashlib.sha1((change["password"] + "mI29fmAnxgTs").encode()).hexdigest()
            require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName="P0Other", gjp2=new_gjp2)) == "2,102", "New GJP2 does not authenticate")
            require(post("/a/accounts/loginGJAccount22.php", dict(VERSION, userName="P0Owner", password=PASSWORD)) == "1,101", "Submitted accountID changed another player")
            require(account_page(data=change)[0] == 403, "Used successful form CSRF token remained valid")
            # A second account exhausts its identity budget before the IP budget.
            status, page = account_page("/mc/accounts/accountManagement.php")
            token = re.search(r'name="csrf" value="([a-f0-9]{64})"', page).group(1)
            for unused in range(5):
                account_page(data=dict(change, csrf=token, username="P0Owner", current_password="wrong"))
            before_limited = fixture("inspect")["business_sha256"]
            status, page = account_page(data=dict(change, csrf=token, username="P0Owner", current_password=PASSWORD))
            require("Unable to change the password" in page and fixture("inspect")["business_sha256"] == before_limited,
                    "Account rate limit failed closed")
            checks.append("native-account-management/mount/cookie/csrf/current-password/no-gjp2/banned/read-only/revocation/audit/rollback/rate-limit")
            result = dict(kind="synthetic-local-http", native_client_certified=False,
                          php=Path(php).name, game_version=22, binary_version=51,
                          fixture_accounts=4, checks=checks, timings={})
            for endpoint, values in timings.items():
                ordered = sorted(values)
                result["timings"][endpoint] = dict(samples=len(ordered), round_trip_p95_ms=round(ordered[(95 * len(ordered) + 99)//100 - 1], 3))
            if report:
                Path(report).write_text(json.dumps(result, indent=2) + "\n")
            print("SHARED_P0_REAL_HTTP_METADATA_AUTH_WIRE_DB_RACE_READONLY_OK " + Path(php).name)
        finally:
            for server in servers:
                server.terminate()
            for server in servers:
                server.wait(timeout=10)
            for log in logs:
                log.close()
            if (root / "config/php7-local.php").exists():
                fixture("drop")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=shutil.which("php"))
    parser.add_argument("--report")
    args = parser.parse_args()
    run(args.php, args.report)
