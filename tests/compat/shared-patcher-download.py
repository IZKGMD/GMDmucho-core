"""Exercise the real shared-patcher HTTP boundary on the selected PHP runtime."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

php = sys.argv[1] if len(sys.argv) > 1 else shutil.which("php")
repo = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix="mucho-download-") as temporary:
    root = Path(temporary)
    job_id = "a" * 32
    job = root / "storage" / "shared-client-patcher" / job_id
    job.mkdir(parents=True)
    payload = os.urandom(2 * 1024 * 1024 + 257)
    digest = hashlib.sha256(payload).hexdigest()
    for kind in ("exe", "apk"):
        (job / ("result." + kind)).write_bytes(payload)
    state = dict(phase="done", expires=int(time.time()) + 600, owner=7,
                 kind="exe", report=dict(output_sha256=digest))
    (job / "state.json").write_text(json.dumps(state))
    router = root / "router.php"
    router.write_text("""<?php
ob_start(); echo 'incidental host output';
function mucho7_private_write($path,$bytes){file_put_contents($path,$bytes);}
function mucho7_admin_can($db,$user,$permission){return !isset($_GET['denied']);}
function mucho7_admin_clean_response(){while(ob_get_level()>0)ob_end_clean();}
session_start();
$_SESSION['shared_patcher_id']=str_repeat('a',32);
if(isset($_GET['wrong_session']))$_SESSION['shared_patcher_id']=str_repeat('b',32);
require """ + json.dumps(str(repo / "compat/php7/patcher.php")) + """;
try { mucho7_patcher_download(new stdClass(),array('account_id'=>7),__DIR__); }
catch(Exception $e){http_response_code(404);exit;}
""")
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    with (root / "server.log").open("wb") as log:
        server = subprocess.Popen([
            php, "-d", "zlib.output_compression=1", "-d",
            "disable_functions=flush,connection_aborted,exec,shell_exec,system,passthru,proc_open,popen",
            "-S", "127.0.0.1:" + str(port), str(router)
        ], stdout=log, stderr=log)
        try:
            for attempt in range(100):
                if server.poll() is not None:
                    raise RuntimeError((root / "server.log").read_text())
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.03)
            url = "http://127.0.0.1:%d/?shared_patcher_download=%s" % (port, job_id)

            def request(headers=None, method="GET", suffix=""):
                req = urllib.request.Request(url + suffix, headers=headers or {}, method=method)
                try:
                    return urllib.request.urlopen(req, timeout=10)
                except urllib.error.HTTPError as error:
                    return error

            for kind in ("exe", "apk"):
                state["kind"] = kind
                (job / "state.json").write_text(json.dumps(state))
                with request({"Accept-Encoding": "gzip"}) as response:
                    assert response.status == 200
                    assert int(response.headers["Content-Length"]) == len(payload)
                    assert response.headers.get("Content-Encoding") is None
                    assert hashlib.sha256(response.read()).hexdigest() == digest
                for value, first, last in (("bytes=17-1048599", 17, 1048599),
                                           ("bytes=-97", len(payload)-97, len(payload)-1),
                                           ("bytes=1048576-", 1048576, len(payload)-1)):
                    with request({"Range": value}) as response:
                        assert response.status == 206
                        assert response.headers["Content-Range"] == "bytes %d-%d/%d" % (first, last, len(payload))
                        assert response.read() == payload[first:last+1]
                with request(method="HEAD") as response:
                    assert response.status == 200 and response.read() == b""
                    assert int(response.headers["Content-Length"]) == len(payload)
                with request({"Range": "bytes=99999999-"}) as response:
                    assert response.status == 416
                with request({"Range": "bytes=0-1", "If-Range": '"different"'}) as response:
                    assert response.status == 200 and response.read() == payload
            for suffix, status in (("&denied=1", 403), ("&wrong_session=1", 404)):
                with request(suffix=suffix) as response:
                    assert response.status == status
            print("SHARED_DOWNLOAD_OK full/range/HEAD/auth/compression " + php)
        finally:
            server.terminate()
            server.wait(timeout=5)
