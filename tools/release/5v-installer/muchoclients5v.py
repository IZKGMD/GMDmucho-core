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
        raise InstallError("Выбери существующий оригинальный файл " + suffix + ".")
    size = file.stat().st_size
    maximum = MAX_EXE if suffix == ".exe" else MAX_APK
    if not 1024 <= size <= maximum:
        raise InstallError("Размер исходного файла выходит за поддерживаемые пределы.")
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
        raise InstallError("EXE не подходит для безопасного патча GD 2.209: " + str(exc)) from exc
    digest = sha256(patched)
    outdir.mkdir(parents=True, exist_ok=True)
    output = outdir / ("GeometryDash-MuchoCore-" + digest[:12] + ".exe")
    if output.exists() and sha256_file(output) != digest:
        raise InstallError("Локальный файл клиента с тем же именем повреждён.")
    if not output.exists():
        part = output.with_name(output.name + ".partial-" + secrets.token_hex(6))
        try:
            part.write_bytes(patched)
            if sha256_file(part) != digest:
                raise InstallError("Ошибка проверки EXE после патча.")
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
        raise InstallError("Админка не открыла патчер клиентов. Нужен вход владельца и завершённая миграция.")
    return match.group(1)


def _urlencoded(site: HttpsSite, csrf: str, action: str, **fields) -> dict:
    form = {"csrf": csrf, "action": "shared-patcher-" + action}
    form.update({key: str(value) for key, value in fields.items()})
    code, body = site.request("/mc/admin.php?page=clientpatcher", form)
    try:
        data = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        raise InstallError("Патчер не вернул JSON. Проверь авторизацию владельца и работу PHP7.")
    if code != 200 or data.get("ok") is not True:
        raise InstallError("Патчер 5v.pl: " + str(data.get("error", "HTTP " + str(code))))
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
        raise InstallError("HTTPS-отправка APK прервалась. Состояние можно восстановить.") from exc
    try:
        data = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        raise InstallError("Сервер вернул некорректный ответ при загрузке APK.")
    if code != 200 or data.get("ok") is not True:
        raise InstallError("Ошибка загрузки APK: " + str(data.get("error", "HTTP " + str(code))))
    return data


def patch_android(site: HttpsSite, apk: Path, origin: str, *,
                  alongside: bool = False, progress=print) -> dict:
    """Authenticated, resumable web patcher. Never bypasses PHP7 job security."""
    apk = read_source(apk, ".apk")
    origin = site_origin(origin)
    code, page = site.request("/mc/admin.php?page=clientpatcher")
    if code != 200:
        raise InstallError("Не удалось открыть защищённую админку для патчинга APK.")
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
        raise InstallError("В админке уже есть ДРУГАЯ задача патчера. "
                           "Заверши или сбрось её на странице Client Patcher.")

    if not re.fullmatch(r"[a-f0-9]{32}", str(status.get("id", ""))):
        raise InstallError("Сервер вернул неправильный ID задания.")
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
                    raise InstallError("Невозможно прочитать APK для продолжения загрузки.")
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
                    progress("APK: отправлено %s / %s МиБ" %
                             (int(status.get("offset", 0)) // CHUNK_SIZE,
                              (apk.stat().st_size + CHUNK_SIZE - 1) // CHUNK_SIZE))

    started = time.monotonic()
    steps = 0
    while status.get("phase") != "done":
        if time.monotonic() - started > 1800 or steps > 2500:
            raise InstallError("Подпись APK не завершена. Открой Client Patcher в админке для продолжения.")
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
        raise InstallError("APK не прошёл проверку подписи, URL, размера или SHA-256.")
    report = dict(report)
    report["kind"] = "apk"
    report["job_id"] = status["id"]
    return report


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
        raise InstallError("Сначала проверь установленный MuchoCore на том же 5v.pl.")
    ftp.ensure_dir(ftp.root, CLIENT_DIR)


def _public_url(origin: str, filename: str) -> str:
    if not re.fullmatch(r"GeometryDash-MuchoCore-[a-f0-9]{12}\.(?:exe|apk)", filename):
        raise InstallError("Недопустимое имя опубликованного клиента.")
    return site_origin(origin) + PUBLIC_PREFIX + filename


def _probe_public(site: HttpsSite, url: str, size: int) -> None:
    if not url.startswith(site.origin + PUBLIC_PREFIX):
        raise InstallError("Попытка проверить клиент вне подтверждённого сайта.")
    req = urllib.request.Request(url, method="HEAD",
        headers={"User-Agent": "MuchoCore-5V-Installer/1.1", "Accept": "*/*"})
    try:
        with site.opener.open(req, timeout=25) as response:
            if response.status != 200:
                raise InstallError("Сайт пока не выдаёт опубликованный клиент (HTTP).")
            content_type = response.headers.get("Content-Type", "").lower()
            if "text/html" in content_type:
                raise InstallError("Вместо клиента сайт отдаёт HTML-заглушку.")
            stated = response.headers.get("Content-Length", "")
            if stated.isdigit() and int(stated) != size:
                raise InstallError("Размер клиента по HTTPS не совпадает с проверенным размером.")
            return
    except urllib.error.HTTPError as exc:
        if exc.code != 405:
            raise InstallError("Сайт не выдаёт файл по HTTPS (HTTP %d)." % exc.code)
    except urllib.error.URLError as exc:
        raise InstallError("Невозможно проверить ссылку на клиент по HTTPS.") from exc

    # Some hosts prohibit HEAD. Probe just one byte, never download an APK blindly.
    probe = urllib.request.Request(url, method="GET",
        headers={"Range": "bytes=0-0", "Accept": "*/*"})
    try:
        with site.opener.open(probe, timeout=25) as response:
            if response.status not in (200, 206) or \
                    "text/html" in response.headers.get("Content-Type", "").lower():
                raise InstallError("Сайт не подтвердил прямое скачивание клиента.")
            response.read(1)
    except urllib.error.HTTPError as exc:
        raise InstallError("Сайт не подтвердил скачивание (HTTP %d)." % exc.code)


def _publish_stream(ftp: FtpSite, source: Path, origin: str, kind: str,
                    digest: str, size: int) -> str:
    if not re.fullmatch("[a-f0-9]{64}", digest):
        raise InstallError("Неверная контрольная сумма клиента.")
    if source.stat().st_size != size or sha256_file(source) != digest:
        raise InstallError("Клиент не прошёл проверку целостности перед FTP.")
    _ftp_prepare(ftp)
    name = "GeometryDash-MuchoCore-" + digest[:12] + "." + kind
    destination = CLIENT_DIR + "/" + name
    if _ftp_size(ftp, destination) is not None:
        raise InstallError("Версия клиента уже опубликована. Не перезаписываем существующий файл.")
    tmp = CLIENT_DIR + "/.upload-" + secrets.token_hex(12) + ".part"
    try:
        parent, _, leaf = tmp.rpartition("/")
        ftp.ensure_dir(ftp.root, parent)
        with source.open("rb") as reader:
            ftp.conn.storbinary("STOR " + leaf, reader, blocksize=256 * 1024)
        if _ftp_size(ftp, tmp) != size:
            raise InstallError("FTP загрузил клиент не полностью.")
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
                raise InstallError("Сайт не вернул подписанный APK.")
            while True:
                part = response.read(1024 * 1024)
                if not part:
                    break
                count += len(part)
                if count > MAX_APK or count > expected:
                    raise InstallError("Подписанный APK превысил ожидаемый размер.")
                digest.update(part)
                sink.write(part)
    except urllib.error.URLError as exc:
        raise InstallError("Не удалось забрать подписанный APK из админки.") from exc
    if count != expected or digest.hexdigest() != sha:
        raise InstallError("Подписанный APK не прошёл SHA-256 после HTTPS.")


def publish_android(ftp: FtpSite, site: HttpsSite, report: dict,
                    outdir: Path) -> str:
    """Try in-place FTP rename; use authenticated HTTPS streaming as fallback."""
    digest = str(report["output_sha256"])
    size = int(report["output_size"])
    job = str(report["job_id"])
    if not re.fullmatch("[a-f0-9]{64}", digest) or \
            not re.fullmatch("[a-f0-9]{32}", job) or not 1024 <= size <= MAX_APK:
        raise InstallError("Подписанный APK содержит неправильные метаданные.")
    _ftp_prepare(ftp)
    name = "GeometryDash-MuchoCore-" + digest[:12] + ".apk"
    destination = CLIENT_DIR + "/" + name
    if _ftp_size(ftp, destination) is not None:
        raise InstallError("Эта версия APK уже опубликована. Старый клиент не перезаписывается.")
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
        raise InstallError("В папке clients уже есть своя index.html. "
                           "Не перезаписываем чужую страницу; прямые ссылки сохранены.")
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
            raise InstallError("Ссылка на клиент должна принадлежать этому сайту.")
        entries[url] = (kind == "apk", entry["sha256"])

    rows = []
    for url, (android, sha) in entries.items():
        name = "Android APK" if android else "Windows EXE"
        extra = " · SHA-256: <code>" + html.escape(sha, quote=True) + "</code>" if sha else ""
        rows.append('<li><a href="' + html.escape(url, quote=True) +
                    '">' + name + '</a>' + extra + '</li>')
    markup = ('<!doctype html><!-- MUCHOCORE_CLIENTS_INDEX_V1 -->'
        '<html lang="ru"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        '<title>MuchoCore — клиенты</title><style>body{background:#15121d;color:#f1ecfa;'
        'font:16px system-ui;max-width:720px;margin:10vh auto;padding:20px}'
        'a{color:#cfb2f5}li{margin:16px 0}code{word-break:break-all}</style></head>'
        '<body><h1>Клиенты MuchoCore</h1><p>Сервер: <strong>' +
        html.escape(site.origin, quote=True) + '</strong></p><ul>' +
        ''.join(rows) + '</ul><p>Windows: для запуска нужны DLL и Resources '
        'из установленной копии игры. Для Android сохраняйте закрытый '
        'ключ подписи в config/php7-apk-signer.json для будущих обновлений.'
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
