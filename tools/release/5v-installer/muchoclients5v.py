"""Client patching and owner-controlled publishing for the 5v.pl native runtime.

Windows: canonical SHA-pinned GD 2.209 URL-only Python patcher.
Android: existing authenticated PHP 7 shared patcher, including v1/v2 signing.
No original game binaries are downloaded, embedded, or shipped by MuchoCore.
"""
from __future__ import annotations

import hashlib
import html
import io
import json
from pathlib import Path
import re
import secrets
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

from mucho5v import FtpSite, HttpsSite, InstallError, site_origin

MAX_EXE = 64 * 1024 * 1024
MAX_APK = 512 * 1024 * 1024
CHUNK_SIZE = 1024 * 1024
CLIENT_DIR = "mc/public/clients"
PUBLIC_PREFIX = "/mc/public/clients/"


def sha256_file(source: Path) -> str:
    digest = hashlib.sha256()
    with source.open("rb") as stream:
        while True:
            part = stream.read(1024 * 1024)
            if not part:
                break
            digest.update(part)
    return digest.hexdigest()


def fingerprint_file(source: Path) -> str:
    size = source.stat().st_size
    with source.open("rb") as stream:
        first = stream.read(min(65536, size))
        stream.seek(max(0, size - 65536))
        last = stream.read(min(65536, size))
    return hashlib.sha256(first + last).hexdigest()


def read_source(value: str | Path, suffix: str) -> Path:
    file = Path(value).expanduser().resolve()
    if file.suffix.lower() != suffix or not file.is_file():
        raise InstallError("Select an existing original " + suffix + ".")
    size = file.stat().st_size
    maximum = MAX_EXE if suffix == ".exe" else MAX_APK
    if not 1024 <= size <= maximum:
        raise InstallError("The source file size is outside the supported range.")
    return file


def patch_windows(source: Path, site_url: str, outdir: Path) -> tuple[Path, dict]:
    from gd_windows_patcher import patch_client, validate_result, sha256

    source = read_source(source, ".exe")
    site_url = site_origin(site_url)
    original = source.read_bytes()
    try:
        patched, stats = patch_client(original, site_url)
        validate_result(original, patched, site_url, stats)
    except (ValueError, AssertionError) as exc:
        raise InstallError("This EXE could not be patched for GD 2.209: " + str(exc)) from exc
    digest = sha256(patched)
    outdir.mkdir(parents=True, exist_ok=True)
    output = outdir / ("GeometryDash-MuchoCore-" + digest[:12] + ".exe")
    if output.exists() and sha256_file(output) != digest:
        raise InstallError("The local client with this filename is damaged.")
    if not output.exists():
        part = output.with_name(output.name + ".partial-" + secrets.token_hex(6))
        try:
            part.write_bytes(patched)
            if sha256_file(part) != digest:
                raise InstallError("The patched EXE failed verification.")
            part.replace(output)
        finally:
            part.unlink(missing_ok=True)
    return output, {"sha256": digest, "size": len(patched),
                    "replacements": sum(item.count for item in stats),
                    "kind": "exe", "source": "canonical SHA-pinned Windows patcher"}


def patcher_csrf(page: bytes) -> str:
    markup = page.decode("utf-8", "replace")
    match = re.search(r'<form\b[^>]*\bid="sharedPatcher"[^>]*\bdata-csrf="([a-f0-9]{48,64})"', markup)
    if not match:
        raise InstallError("The Client Patcher is unavailable. Sign in as the owner and finish migration.")
    return match.group(1)


def _urlencoded(site: HttpsSite, csrf: str, action: str, **fields) -> dict:
    form = {"csrf": csrf, "action": "shared-patcher-" + action}
    form.update({key: str(value) for key, value in fields.items()})
    code, body = site.request("/mc/admin.php?page=clientpatcher", form)
    try:
        data = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        raise InstallError("The Client Patcher did not return JSON. Check owner sign-in and PHP 7.")
    if code != 200 or data.get("ok") is not True:
        raise InstallError("5v.pl Client Patcher: " + str(data.get("error", "HTTP " + str(code))))
    return data


def _chunk_request(site: HttpsSite, csrf: str, offset: int, chunk: bytes) -> dict:
    boundary = "Mucho5V" + secrets.token_hex(12)
    fields = {
        "csrf": csrf,
        "action": "shared-patcher-chunk",
        "offset": str(offset),
        "sha256": hashlib.sha256(chunk).hexdigest(),
    }
    encoded = bytearray()
    for key, value in fields.items():
        encoded += ("--" + boundary + "\r\nContent-Disposition: form-data; name=\""
                    + key + "\"\r\n\r\n" + value + "\r\n").encode("ascii")
    encoded += ("--" + boundary + "\r\nContent-Disposition: form-data; "
                "name=\"chunk\"; filename=\"chunk.bin\"\r\n"
                "Content-Type: application/octet-stream\r\n\r\n").encode("ascii")
    encoded += chunk
    encoded += ("\r\n--" + boundary + "--\r\n").encode("ascii")
    req = urllib.request.Request(
        site.origin + "/mc/admin.php?page=clientpatcher",
        data=bytes(encoded),
        headers={"Content-Type": "multipart/form-data; boundary=" + boundary,
                 "Accept": "application/json",
                 "User-Agent": "MuchoCore-5V-Installer/1.1"},
        method="POST",
    )
    try:
        with site.opener.open(req, timeout=40) as res:
            code, body = res.status, res.read(100000)
    except urllib.error.HTTPError as exc:
        code, body = exc.code, exc.read(10000)
    except (urllib.error.URLError, TimeoutError) as exc:
        raise InstallError("The APK upload was interrupted. Retry to resume.") from exc
    try:
        data = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        raise InstallError("The website returned an invalid response during APK upload.")
    if code != 200 or data.get("ok") is not True:
        raise InstallError("APK upload failed: " + str(data.get("error", "HTTP " + str(code))))
    return data


def patch_android(site: HttpsSite, apk: Path, origin: str, *,
                  alongside: bool = False, progress=print) -> dict:
    """Authenticated, resumable web patcher. Never bypasses PHP7 job security."""
    apk = read_source(apk, ".apk")
    origin = site_origin(origin)
    code, page = site.request("/mc/admin.php?page=clientpatcher")
    if code != 200:
        raise InstallError("Could not open the admin panel to patch the APK.")
    csrf = patcher_csrf(page)
    fingerprint = fingerprint_file(apk)
    status = _urlencoded(site, csrf, "status")
    if status["phase"] == "idle":
        status = _urlencoded(
            site, csrf, "start", filename=apk.name, size=apk.stat().st_size,
            server_url=origin, alongside="1" if alongside else "0",
            fingerprint=fingerprint,
        )
    elif (status.get("kind") != "apk" or status.get("fingerprint") != fingerprint
          or status.get("server_url") != origin):
        raise InstallError("A different patch job is already active. "
                           "Finish or reset it on the Client Patcher page.")

    if not re.fullmatch(r"[a-f0-9]{32}", str(status.get("id", ""))):
        raise InstallError("The website returned an invalid patch job ID.")
    if status.get("phase") == "upload":
        with apk.open("rb") as stream:
            while status.get("phase") == "upload":
                offset = int(status.get("offset", 0))
                if offset >= apk.stat().st_size:
                    status = _urlencoded(site, csrf, "step")
                    break
                stream.seek(offset)
                chunk = stream.read(min(CHUNK_SIZE, apk.stat().st_size - offset))
                if not chunk:
                    raise InstallError("Could not read the APK to resume upload.")
                try:
                    status = _chunk_request(site, csrf, offset, chunk)
                except InstallError:
                    # Retry ambiguity: query the server instead of duplicating a chunk.
                    fresh = _urlencoded(site, csrf, "status")
                    if int(fresh.get("offset", -1)) == offset + len(chunk):
                        status = fresh
                    else:
                        raise
                if offset == 0 or status.get("offset", 0) == apk.stat().st_size or \
                        int(status.get("offset", 0)) // (16 * CHUNK_SIZE) != offset // (16 * CHUNK_SIZE):
                    progress("APK uploaded: %s of %s MiB" %
                             (int(status.get("offset", 0)) // CHUNK_SIZE,
                              (apk.stat().st_size + CHUNK_SIZE - 1) // CHUNK_SIZE))

    started = time.monotonic()
    steps = 0
    while status.get("phase") != "done":
        if time.monotonic() - started > 1800 or steps > 2500:
            raise InstallError("APK signing is incomplete. Open Client Patcher in the admin panel to continue.")
        previous = status.get("phase")
        status = _urlencoded(site, csrf, "step")
        if status.get("phase") != previous:
            progress("APK: " + str(status.get("phase")) +
                     " (" + str(status.get("progress")) + "%)")
        steps += 1

    report = status.get("report", {})
    if (not isinstance(report, dict)
            or not report.get("signed")
            or set(report.get("signature_schemes", [])) != {"v1", "v2"}
            or not re.fullmatch(r"[a-f0-9]{64}", str(report.get("output_sha256", "")))
            or int(report.get("output_size", 0)) < 1024
            or report.get("server_url") != origin
            or int(report.get("replacement_count", 0)) < 1):
        raise InstallError("The APK signature, URL, size, or SHA-256 check failed.")
    report = dict(report)
    report["kind"] = "apk"
    report["job_id"] = status["id"]
    return report


def cleanup_published_job(site: HttpsSite, job_id: str) -> bool:
    """Free private APK upload/scratch space once the PUBLIC copy is verified.

    The original server-side patcher retains the persistent APK signing key,
    independent of its temporary job. Never touch another job or an active job.
    """
    if not re.fullmatch(r"[a-f0-9]{32}", str(job_id)):
        raise InstallError("The temporary job ID is invalid.")
    code, html = site.request("/mc/admin.php?page=clientpatcher")
    if code != 200:
        raise InstallError("Could not open the admin panel to remove temporary files.")
    csrf = patcher_csrf(html)
    status = _urlencoded(site, csrf, "status")
    if status.get("id") != job_id or status.get("phase") != "done":
        return False
    result = _urlencoded(site, csrf, "reset")
    return result.get("phase") == "idle"


def _ftp_size(ftp: FtpSite, rel: str) -> int | None:
    ftp.cd(ftp.root)
    ftp.conn.voidcmd("TYPE I")
    try:
        value = ftp.conn.size(rel)
    except Exception:
        return None
    return int(value) if value is not None else None


def _ftp_prepare(ftp: FtpSite):
    if ftp.root is None or not ftp.exists_dir(ftp.root, "mc"):
        raise InstallError("Verify that MuchoCore is installed on this 5v.pl site first.")
    ftp.ensure_dir(ftp.root, CLIENT_DIR)


def _public_url(origin: str, filename: str) -> str:
    if not re.fullmatch(r"GeometryDash-MuchoCore-[a-f0-9]{12}\.(?:exe|apk)", filename):
        raise InstallError("The published client filename is invalid.")
    return site_origin(origin) + PUBLIC_PREFIX + filename


def _probe_public(site: HttpsSite, url: str, size: int) -> None:
    if not url.startswith(site.origin + PUBLIC_PREFIX):
        raise InstallError("The client is outside the verified site.")
    req = urllib.request.Request(url, method="HEAD",
        headers={"User-Agent": "MuchoCore-5V-Installer/1.1", "Accept": "*/*"})
    try:
        with site.opener.open(req, timeout=25) as response:
            if response.status != 200:
                raise InstallError("The published client is temporarily unavailable over HTTPS.")
            content_type = response.headers.get("Content-Type", "").lower()
            if "text/html" in content_type:
                raise InstallError("The website returned an HTML page instead of the client.")
            stated = response.headers.get("Content-Length", "")
            if stated.isdigit() and int(stated) != size:
                raise InstallError("The HTTPS download size differs from the verified client size.")
            return
    except urllib.error.HTTPError as exc:
        if exc.code != 405:
            raise InstallError("The HTTPS download failed (HTTP %d)." % exc.code)
    except urllib.error.URLError as exc:
        raise InstallError("Could not verify the client link over HTTPS.") from exc

    # Some hosts prohibit HEAD. Probe just one byte, never download an APK blindly.
    probe = urllib.request.Request(url, method="GET",
        headers={"Range": "bytes=0-0", "Accept": "*/*"})
    try:
        with site.opener.open(probe, timeout=25) as response:
            if response.status not in (200, 206) or \
                    "text/html" in response.headers.get("Content-Type", "").lower():
                raise InstallError("Could not verify a direct download for the client.")
            response.read(1)
    except urllib.error.HTTPError as exc:
        raise InstallError("Could not verify the download (HTTP %d)." % exc.code)


def _publish_stream(ftp: FtpSite, source: Path, origin: str, kind: str,
                    digest: str, size: int) -> str:
    if not re.fullmatch("[a-f0-9]{64}", digest):
        raise InstallError("The client checksum is invalid.")
    if source.stat().st_size != size or sha256_file(source) != digest:
        raise InstallError("The client failed its integrity check before FTP upload.")
    _ftp_prepare(ftp)
    name = "GeometryDash-MuchoCore-" + digest[:12] + "." + kind
    destination = CLIENT_DIR + "/" + name
    if _ftp_size(ftp, destination) is not None:
        raise InstallError("This client version is already published. Setup kept the existing file.")
    tmp = CLIENT_DIR + "/.upload-" + secrets.token_hex(12) + ".part"
    try:
        parent, _, leaf = tmp.rpartition("/")
        ftp.ensure_dir(ftp.root, parent)
        with source.open("rb") as reader:
            ftp.conn.storbinary("STOR " + leaf, reader, blocksize=256 * 1024)
        if _ftp_size(ftp, tmp) != size:
            raise InstallError("The FTP upload did not transfer the complete client.")
        ftp.rename(ftp.root, tmp, destination)
    finally:
        ftp.delete(ftp.root, tmp)
    return _public_url(origin, name)


def publish_windows(ftp: FtpSite, site: HttpsSite, patched: Path, report: dict) -> str:
    url = _publish_stream(ftp, patched, site.origin, "exe",
                          report["sha256"], report["size"])
    _probe_public(site, url, report["size"])
    return url


def _download_authenticated(site: HttpsSite, job_id: str, expected: int,
                            sha: str, local: Path) -> None:
    query = "/mc/admin.php?shared_patcher_download=" + urllib.parse.quote(job_id)
    request = urllib.request.Request(site.origin + query, method="GET",
        headers={"User-Agent": "MuchoCore-5V-Installer/1.1",
                 "Accept": "application/octet-stream"})
    count = 0
    digest = hashlib.sha256()
    try:
        with site.opener.open(request, timeout=40) as response, local.open("xb") as sink:
            if response.status != 200:
                raise InstallError("The website did not return a signed APK.")
            while True:
                part = response.read(1024 * 1024)
                if not part:
                    break
                count += len(part)
                if count > MAX_APK or count > expected:
                    raise InstallError("The signed APK exceeded its expected size.")
                digest.update(part)
                sink.write(part)
    except urllib.error.URLError as exc:
        raise InstallError("Could not download the signed APK from the admin panel.") from exc
    if count != expected or digest.hexdigest() != sha:
        raise InstallError("The signed APK failed its SHA-256 check after download.")


def download_android_private(site: HttpsSite, report: dict,
                            outdir: Path) -> Path:
    """Download the signed APK to the operator's PC without public hosting."""
    digest = str(report.get("output_sha256", ""))
    size = int(report.get("output_size", 0))
    job = str(report.get("job_id", ""))
    if (not re.fullmatch(r"[a-f0-9]{64}", digest)
            or not re.fullmatch(r"[a-f0-9]{32}", job)
            or not 1024 <= size <= MAX_APK
            or report.get("server_url") != site.origin
            or report.get("signed") is not True
            or set(report.get("signature_schemes", [])) != {"v1", "v2"}
            or int(report.get("replacement_count", 0)) < 1):
        raise InstallError("The signed APK has invalid metadata.")
    outdir.mkdir(parents=True, exist_ok=True)
    output = outdir / ("GeometryDash-MuchoCore-" + digest[:12] + ".apk")
    if output.exists():
        if output.stat().st_size == size and sha256_file(output) == digest:
            return output
        raise InstallError("An APK with the same filename already exists and has a different SHA-256 hash.")
    with tempfile.TemporaryDirectory(prefix="mucho-apk-", dir=outdir) as temp:
        temporary = Path(temp) / "verified.apk"
        _download_authenticated(site, job, size, digest, temporary)
        temporary.replace(output)
    return output


def publish_android(ftp: FtpSite, site: HttpsSite, report: dict,
                    outdir: Path) -> str:
    """Try in-place FTP rename; use authenticated HTTPS streaming as fallback."""
    digest = str(report["output_sha256"])
    size = int(report["output_size"])
    job = str(report["job_id"])
    if not re.fullmatch("[a-f0-9]{64}", digest) or \
            not re.fullmatch("[a-f0-9]{32}", job) or not 1024 <= size <= MAX_APK:
        raise InstallError("The signed APK has invalid metadata.")
    _ftp_prepare(ftp)
    name = "GeometryDash-MuchoCore-" + digest[:12] + ".apk"
    destination = CLIENT_DIR + "/" + name
    if _ftp_size(ftp, destination) is not None:
        raise InstallError("This APK version is already published. Setup kept the existing file.")
    source = "mc/storage/shared-client-patcher/" + job + "/result.apk"
    moved = False
    if _ftp_size(ftp, source) == size:
        try:
            ftp.rename(ftp.root, source, destination)
            moved = _ftp_size(ftp, destination) == size
        except Exception:
            moved = False
    if not moved:
        outdir.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="mucho-apk-", dir=outdir) as temp:
            temp_file = Path(temp) / "verified.apk"
            _download_authenticated(site, job, size, digest, temp_file)
            url = _publish_stream(ftp, temp_file, site.origin, "apk", digest, size)
            _probe_public(site, url, size)
            return url
    url = _public_url(site.origin, name)
    _probe_public(site, url, size)
    return url


def publish_index(ftp: FtpSite, site: HttpsSite, links: dict) -> str:
    """Keep old client links and preserve unrelated custom index pages."""
    _ftp_prepare(ftp)
    previous = ftp.get(ftp.root, CLIENT_DIR + "/index.html", optional=True)
    if previous is not None and b"MUCHOCORE_CLIENTS_INDEX_V1" not in previous:
        raise InstallError("The clients folder already has an index.html. "
                           "Setup kept the existing page and direct links.")
    entries = {}
    if previous is not None:
        for found in re.findall(rb'href="([^"]+)"', previous):
            try:
                url = html.unescape(found.decode("ascii"))
                if not url.startswith(site.origin + PUBLIC_PREFIX):
                    continue
                filename = url[len(site.origin + PUBLIC_PREFIX):]
                if not re.fullmatch(
                    r"GeometryDash-MuchoCore-[a-f0-9]{12}\.(?:exe|apk)",
                    filename,
                ):
                    continue
                entries[url] = (filename.endswith(".apk"), "")
            except (ValueError, UnicodeDecodeError):
                continue

    for kind in ("exe", "apk"):
        entry = links.get(kind)
        if not entry:
            continue
        url = entry["url"]
        if url != _public_url(site.origin, url.rsplit("/", 1)[-1]):
            raise InstallError("The client link must belong to this website.")
        entries[url] = (kind == "apk", entry["sha256"])

    rows = []
    for url, (android, sha) in entries.items():
        name = "Android APK" if android else "Windows EXE"
        extra = " · SHA-256: <code>" + html.escape(sha, quote=True) + "</code>" if sha else ""
        rows.append('<li><a href="' + html.escape(url, quote=True) +
                    '">' + name + '</a>' + extra + '</li>')
    markup = ('<!doctype html><!-- MUCHOCORE_CLIENTS_INDEX_V1 -->'
        '<html lang="en"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        "<title>MuchoCore clients</title><style>body{background:#15121d;color:#f1ecfa;"
        'font:16px system-ui;max-width:720px;margin:10vh auto;padding:20px}'
        'a{color:#cfb2f5}li{margin:16px 0}code{word-break:break-all}</style></head>'
        "<body><h1>MuchoCore clients</h1><p>Server: <strong>" +
        html.escape(site.origin, quote=True) + '</strong></p><ul>' +
        ''.join(rows) + "</ul><p>Windows requires DLLs and Resources "
        "from your installed copy of the game. For Android updates, back up the private "
        "signing key at config/php7-apk-signer.json."
        '</p></body></html>')
    tmp = CLIENT_DIR + "/.index-" + secrets.token_hex(8) + ".tmp"
    try:
        ftp.put(ftp.root, tmp, markup.encode("utf-8"))
        if previous is not None:
            backup = CLIENT_DIR + "/index-before-" + secrets.token_hex(6) + ".html"
            ftp.rename(ftp.root, CLIENT_DIR + "/index.html", backup)
            try:
                ftp.rename(ftp.root, tmp, CLIENT_DIR + "/index.html")
            except Exception:
                ftp.rename(ftp.root, backup, CLIENT_DIR + "/index.html")
                raise
        else:
            ftp.rename(ftp.root, tmp, CLIENT_DIR + "/index.html")
    finally:
        ftp.delete(ftp.root, tmp)
    return site.origin + PUBLIC_PREFIX


def save_links(outdir: Path, origin: str, links: dict):
    """Never persist FTP, MySQL, admin credentials or session cookies."""
    outdir.mkdir(parents=True, exist_ok=True)
    report = {"site": site_origin(origin), "clients": links,
              "apk_key_backup": "Back up private mc/config/php7-apk-signer.json securely."}
    target = outdir / "clients-publication.json"
    target.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    return target
