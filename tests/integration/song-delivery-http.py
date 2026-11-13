#!/usr/bin/env python3
"""Check real local MP3 transport and GD 1.9–2.209 song metadata.

All writes are restricted to the GitHub Actions testgdps database and a
randomly named disposable song in the isolated HTTP acceptance stack.
"""
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:18080"
SONG_ID = 99999002


def check(ok, description):
    if not ok:
        raise AssertionError("GD music transport: " + description)
    print("PASS " + description, flush=True)


def run(args, env=None, timeout=45):
    result = subprocess.run(
        args, cwd=ROOT, env=env, capture_output=True,
        text=True, timeout=timeout, check=False,
    )
    if result.returncode:
        raise RuntimeError("Music acceptance command failed: " +
                           " ".join(args[:3]) + "\n" + result.stderr[-1400:])
    return result.stdout.strip()


def request(path, fields=None, headers=None):
    if not path.startswith("/") or path.startswith("//"):
        raise ValueError("Only local relative HTTP requests permitted")
    payload = None if fields is None else urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(BASE + path, data=payload, headers=headers or {},
                                 method="POST" if payload is not None else "GET")
    try:
        response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(), dict(response.headers.items())


def main():
    if not (os.environ.get("GITHUB_ACTIONS") == "true" and
            os.environ.get("MUCHO_TEST_ALLOW_WRITES") == "1" and
            os.environ.get("DB_NAME") == "testgdps" and
            os.environ.get("DB_HOST") == "127.0.0.1" and
            os.environ.get("DB_PORT") == "13307" and
            os.environ.get("MUCHO_TEST_BASE_URL") == BASE):
        raise RuntimeError("Refusing music acceptance outside isolated CI")

    # Keep the 40-hex naming convention used by MuchoCore upload/delete.
    base = secrets.token_hex(20)
    filename = base + ".mp3"
    local = ROOT / "storage" / "music-public" / filename
    environment = os.environ.copy()
    environment["MC_MUSIC_TEST_FILE"] = filename

    created = False
    installed = False
    try:
        with tempfile.TemporaryDirectory(prefix="muchocore-audio-") as temporary:
            tone = Path(temporary) / "original-test-tone.mp3"
            run([
                "ffmpeg", "-hide_banner", "-nostdin", "-loglevel", "error",
                "-f", "lavfi", "-i", "sine=frequency=523:duration=1.0",
                "-ac", "1", "-ar", "22050", "-codec:a", "libmp3lame",
                "-b:a", "64k", "-y", str(tone),
            ])
            source_bytes = tone.read_bytes()
            check(len(source_bytes) > 1000, "original test MP3 is nonempty")
            media = json.loads(run([
                "ffprobe", "-v", "error", "-show_entries",
                "format=duration:stream=codec_name", "-of", "json", str(tone),
            ]))
            check(any(s.get("codec_name") == "mp3" for s in media.get("streams", []))
                  and float(media["format"]["duration"]) > 0.5,
                  "fixture is decodable MP3 rather than fabricated header bytes")
            # Match PHP-FPM's production upload ownership and 0640 mode;
            # 0644 would hide a real Caddy-vs-PHP permissions regression.
            run(["sudo", "install", "-o", "www-data", "-g", "www-data",
                 "-m", "0640", str(tone), str(local)])
            installed = True

            def fixture(command):
                return run([
                    "sudo", "-E", "php", "tests/integration/music-delivery-fixture.php",
                    "--" + command,
                ], environment)

            check(fixture("create") == "CREATED",
                  "verified song metadata seeded exclusively in testgdps")
            created = True

            expected_url = BASE + "/music/" + filename
            for gv, binary in ((19, 0), (20, 0), (21, 0), (22, 51)):
                code, data, _ = request("/database/getGJSongInfo.php", {
                    "songID": str(SONG_ID),
                    "gameVersion": str(gv),
                    "binaryVersion": str(binary),
                    "secret": "Wmfd2893gb7",
                })
                response = data.decode("utf-8", "strict")
                check(code == 200 and response.startswith("1~|~" + str(SONG_ID) + "~|~"),
                      "song metadata is returned for GD version " + str(gv))
                match = re.search(r"(?:^|~\|~)10~\|~([^~]+)", response)
                check(match is not None and
                      urllib.parse.unquote(match.group(1)) == expected_url,
                      "GD version " + str(gv) + " receives correctly encoded MP3 URL")
                check(response.endswith("8~|~0"),
                      "GD version " + str(gv) + " sees enabled song flags")

            code, size_data, _ = request("/database/getGJAudioFileSize.php", {
                "songIDs": str(SONG_ID), "sfxIDs": "",
                "gameVersion": "22", "binaryVersion": "51",
            })
            size_text = size_data.decode("ascii", "strict")
            check(code == 200 and size_text.startswith(str(SONG_ID) + ":")
                  and int(size_text.split(":", 1)[1].split("#", 1)[0]) > 0,
                  "GD 2.209 reports nonzero custom-song audio size")

            code, body, headers = request("/music/" + filename)
            check(code == 200 and body == source_bytes,
                  "Caddy serves exact original MP3 bytes via GD download URL")
            check("audio/mpeg" in headers.get("Content-Type", ""),
                  "song download has audio/mpeg content type")
            code, fragment, headers = request("/music/" + filename,
                                              headers={"Range": "bytes=0-127"})
            check(code == 206 and fragment == source_bytes[:128] and
                  headers.get("Content-Range", "").startswith("bytes 0-127/"),
                  "partial range downloads work for older GD clients")


            # Recreate all three services, not merely restart the app. The
            # testgdps SQL and runtime volumes, the uploaded MP3 and the
            # original Cloud Save key must survive a deployment replacement.
            # Unlike a cross-revision upgrade, this only tests persistence
            # under an identical source tree and image.
            compose = ["docker", "compose", "-f", "docker-compose.test.yml",
                       "-f", "docker-compose.test-acceptance.yml"]
            key_command = compose + ["exec", "-T", "testgdps-app", "sha256sum",
                                     "/var/lib/muchocore/cloudsave.key"]
            before_key = run(key_command).split()[0]
            check(re.fullmatch(r"[a-f0-9]{64}", before_key) is not None,
                  "original Cloud Save key exists before stack recreation")
            run(compose + ["up", "-d", "--force-recreate", "--no-build",
                           "testgdps-db", "testgdps-app", "testgdps-caddy"],
                timeout=180)
            for _ in range(75):
                try:
                    health, health_body, _ = request("/health")
                    if health == 200 and health_body.strip() == b"1":
                        break
                except (OSError, TimeoutError):
                    pass
                time.sleep(2)
            else:
                raise AssertionError("Isolated PHP8 stack unhealthy after recreation")

            check(run(key_command).split()[0] == before_key,
                  "Cloud Save key survives complete three-service recreation")
            status, metadata, _ = request("/database/getGJSongInfo.php", {
                "songID": str(SONG_ID), "gameVersion": "22",
                "binaryVersion": "51",
            })
            check(status == 200 and
                  metadata.startswith(("1~|~" + str(SONG_ID) + "~|~").encode()),
                  "song SQL record survives MariaDB container recreation")
            status, recreated_bytes, _ = request("/music/" + filename)
            check(status == 200 and recreated_bytes == source_bytes,
                  "uploaded MP3 survives app and Caddy container recreation")

            # Exercise *file* recovery separately from SQL recovery (#250).
            # This intentionally does not claim to be a complete backup tool.
            # Read the actual uploaded file, not a second copy of the source
            # tone, so a missing or unreadable live file fails the backup.
            saved_media = Path(temporary) / "saved-upload.mp3"
            run(["sudo", "install", "-o", str(os.getuid()), "-g",
                 str(os.getgid()), "-m", "0600", str(local), str(saved_media)])
            check(saved_media.read_bytes() == source_bytes,
                  "private test-only MP3 backup contains exact live upload")
            run(["sudo", "rm", "-f", "--", str(local)])
            status, _, _ = request("/music/" + filename)
            check(status != 200, "missing uploaded MP3 fails HTTP delivery")
            run(["sudo", "install", "-o", "www-data", "-g", "www-data",
                 "-m", "0640", str(saved_media), str(local)])
            status, restored_bytes, _ = request("/music/" + filename)
            check(status == 200 and restored_bytes == source_bytes,
                  "recovered MP3 downloads with its original contents")
            status, restored_range, headers = request(
                "/music/" + filename, headers={"Range": "bytes=0-127"})
            check(status == 206 and restored_range == source_bytes[:128] and
                  headers.get("Content-Range", "").startswith("bytes 0-127/"),
                  "recovered MP3 supports HTTP Range for GD clients")

            check(fixture("hide") == "UPDATED", "moderation can unverify a song")
            code, data, _ = request("/database/getGJSongInfo.php",
                                     {"songID": str(SONG_ID)})
            check(code == 200 and data == b"-1",
                  "unverified song metadata is rejected in GD")
            check(fixture("show") == "UPDATED", "moderation can reverify a song")
            code, data, _ = request("/database/getGJSongInfo.php",
                                     {"songID": str(SONG_ID)})
            check(code == 200 and data.decode().startswith("1~|~" + str(SONG_ID)),
                  "reverified song becomes available to GD again")
            print("MUCHOCORE_GD_SONG_HTTP_DELIVERY_OK", flush=True)
    finally:
        if created:
            run(["sudo", "-E", "php", "tests/integration/music-delivery-fixture.php",
                 "--delete"], environment)
        if installed:
            run(["sudo", "rm", "-f", "--", str(local)])


if __name__ == "__main__":
    main()
