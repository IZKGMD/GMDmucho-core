"""Safe local installer primitives for the MuchoCore 5v.pl native release.

Python 3.10+, standard library only. No VPS, shell commands, SSH or
central credential collection. FTP operations are limited to an explicitly
verified site document root. Do not import this file from a live web server.
"""
from __future__ import annotations

import ftplib
import http.cookiejar
import io
import os
from pathlib import Path
import re
import secrets
import socket
import ssl
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from dataclasses import dataclass
from html.parser import HTMLParser

SITE_RE = re.compile(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.5v\.pl", re.I)
FTP_RE = re.compile(r"[a-z0-9-]+(?:\.[a-z0-9-]+)+", re.I)
BEGIN = "# BEGIN MUCHOCORE 5V.PL ROUTES"
END = "# END MUCHOCORE 5V.PL ROUTES"


class InstallError(RuntimeError):
    """Safe, user-readable installation error."""


class FtpTlsUnavailable(InstallError):
    """The FTP server explicitly rejected the AUTH TLS command."""


@dataclass(frozen=True)
class VerifiedFtpFolder:
    origin: str
    host: str
    user: str
    secure: bool
    port: int
    hint: str
    peer: str
    home: str
    folder: str
    data_mode: str


def ftp_failure(exc: BaseException) -> str:
    """Report server status without including account or server-supplied text."""
    if isinstance(exc, ftplib.Error):
        status = re.match(r"^[1-5][0-9]{2}(?:\s|$)", str(exc))
        if status:
            return "FTP " + status[0].strip()
    if isinstance(exc, TimeoutError):
        return "timed out"
    if isinstance(exc, EOFError):
        return "server closed the connection"
    return type(exc).__name__


class PassiveFtp(ftplib.FTP):
    """Select a working passive data mode without trusting a PASV address."""

    data_mode = "EPSV"

    def ntransfercmd(self, cmd, rest=None):
        modes = (self.data_mode,)
        if self.af == socket.AF_INET:
            modes += ("PASV" if self.data_mode == "EPSV" else "EPSV",)
        peer = self.sock.getpeername()
        failures = []
        for mode in modes:
            try:
                reply = self.sendcmd(mode)
                port = (ftplib.parse229(reply, peer) if mode == "EPSV"
                        else ftplib.parse227(reply))[1]
                if not 1 <= port <= 65535:
                    raise ValueError("Invalid passive port")
            except (ftplib.Error, ValueError) as exc:
                failures.append(f"{mode}: {ftp_failure(exc)}")
                continue
            try:
                channel = socket.create_connection((peer[0], port), min(self.timeout, 8),
                                                   source_address=self.source_address)
            except OSError as exc:
                failures.append(f"{mode}: {ftp_failure(exc)}")
                continue
            try:
                channel.settimeout(self.timeout)
                if rest is not None:
                    self.sendcmd("REST " + str(rest))
                reply = self.sendcmd(cmd)
                if reply.startswith("2"):
                    reply = self.getresp()
                if not reply.startswith("1"):
                    raise ftplib.error_reply(reply)
                size = ftplib.parse150(reply) if reply.startswith("150") else None
            except ftplib.error_temp as exc:
                channel.close()
                if ftp_failure(exc) != "FTP 425":
                    raise
                failures.append(f"{mode}: FTP 425")
                continue
            except BaseException:
                channel.close()
                raise
            self.data_mode = mode
            return channel, size

        detail = "; ".join(failures)
        command = cmd.partition(" ")[0]
        raise InstallError(f"FTP login succeeded, but the data connection for {command} could not open ({detail}).")


class PassiveFtps(ftplib.FTP_TLS, PassiveFtp):
    """Use the same data negotiation before wrapping each socket in TLS."""


def site_origin(value: str) -> str:
    value = value.strip()
    # Accept a pasted site hostname while keeping HTTPS mandatory for requests.
    if SITE_RE.fullmatch(value.rstrip('/')):
        value = 'https://' + value
    try:
        parsed = urllib.parse.urlsplit(value.strip())
        host = parsed.hostname or ""
        valid = (parsed.scheme == "https" and SITE_RE.fullmatch(host)
                 and not parsed.username and not parsed.password
                 and parsed.port is None and parsed.path in ("", "/")
                 and not parsed.query and not parsed.fragment)
    except ValueError:
        valid = False
    if not valid:
        raise InstallError("Enter an HTTPS website at name.5v.pl without a path or port.")
    return "https://" + host.lower()


def valid_ftp_host(value: str) -> str:
    host = value.strip().lower()
    if not FTP_RE.fullmatch(host) or len(host) > 253:
        raise InstallError("Enter the FTP server hostname shown in your 5v.pl dashboard.")
    return host


def php_quote(value: str) -> str:
    if not isinstance(value, str) or any(ord(c) < 32 or ord(c) == 127 for c in value):
        raise InstallError("The PHP configuration contains invalid characters.")
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def private_config(site: str, dbhost: str, dbport: int,
                   database: str, username: str, password: str) -> bytes:
    if not re.fullmatch(r"[A-Za-z0-9._-]{1,120}", dbhost):
        raise InstallError("The MySQL hostname is invalid.")
    if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", database):
        raise InstallError("The MySQL database name is invalid.")
    if not 1 <= dbport <= 65535 or not 1 <= len(username) <= 128:
        raise InstallError("The MySQL port or username is invalid.")
    if not 8 <= len(password) <= 256:
        raise InstallError("The MySQL password must be 8–256 characters.")
    entries = {
        "host": dbhost, "port": dbport, "database": database,
        "username": username, "password": password,
        "auth_pepper": secrets.token_hex(32),
        "site_url": site,
        "enable_accounts": False, "enable_registration": False,
        "enable_writes": False, "enable_cloudsave": False,
        "enable_social": False, "enable_native_news": False,
    }
    def encode(value: object) -> str:
        if isinstance(value, bool):
            return "true" if value else "false"
        if isinstance(value, int):
            return str(value)
        return php_quote(str(value))
    php = "<?php\nreturn array(\n" + "".join(
        "    " + php_quote(key) + " => " + encode(value) + ",\n"
        for key, value in entries.items()
    ) + ");\n"
    return php.encode("utf-8")


def merge_rules(original: bytes | None, rules: bytes, *, resume: bool = False) -> bytes:
    content = original or b""
    if len(content) > 1024 * 1024 or b"\0" in content:
        raise InstallError("The existing .htaccess is too large or invalid.")
    text = content.decode("utf-8-sig")
    raw = rules.decode("utf-8-sig")
    file_guard = "RewriteCond %{REQUEST_FILENAME} !-f"
    lines = [line.strip() for line in raw.splitlines()
             if line.strip() and not line.lstrip().startswith("#")]
    rules_count = 0
    for index, line in enumerate(lines):
        fields = line.split()
        if (len(fields) == 4 and fields[0] == "RewriteRule" and fields[2] == "mc/public/php7.php"
                and re.fullmatch(r"\[[A-Z,]+\]", fields[3])):
            rules_count += 1
        elif line != file_guard or index + 1 >= len(lines) or not lines[index + 1].startswith("RewriteRule "):
            raise InstallError("The verified 5v.pl routing rules are invalid.")
    if rules_count < 8:
        raise InstallError("The verified 5v.pl routing rules are missing from the archive.")
    if BEGIN in text or END in text:
        expected = BEGIN + "\n" + "\n".join(lines) + "\n" + END
        normalized = text.replace("\r\n", "\n")
        if (resume and normalized.count(BEGIN) == 1 and normalized.count(END) == 1
                and expected in normalized):
            return content
        raise InstallError("MuchoCore routes already exist or have been edited. Setup preserved your .htaccess.")
    block = "RewriteEngine On\n" + BEGIN + "\n" + "\n".join(lines) + "\n" + END + "\n"
    return (block + "\n" + text).encode("utf-8")


def safe_relative(path: str) -> str:
    if (not path or path.startswith("/") or "\\" in path
            or "\0" in path or any(p in ("", ".", "..") for p in path.split("/"))):
        raise InstallError("Invalid FTP path: " + path)
    return path


class SameOriginRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, origin: str):
        self.origin = origin
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        candidate = urllib.parse.urlsplit(newurl)
        original = urllib.parse.urlsplit(self.origin)
        if candidate.scheme != "https" or candidate.hostname != original.hostname \
                or candidate.port is not None or candidate.username or candidate.password:
            raise InstallError("The website redirected the request to another address.")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class HttpsSite:
    def __init__(self, origin: str):
        self.origin = site_origin(origin)
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
            SameOriginRedirect(self.origin),
        )
    def request(self, path: str, form: dict | None = None) -> tuple[int, bytes]:
        if not path.startswith("/") or path.startswith("//"):
            raise InstallError("Invalid HTTPS path.")
        data = None if form is None else urllib.parse.urlencode(form).encode("utf-8")
        req = urllib.request.Request(
            self.origin + path, data=data,
            headers={"User-Agent": "MuchoCore-5V-Installer/1.0",
                     "Accept": "text/html,application/json,text/plain"},
            method="POST" if form is not None else "GET",
        )
        try:
            with self.opener.open(req, timeout=18) as res:
                if int(res.headers.get("Content-Length", "0")) > 2_000_000:
                    raise InstallError("The website returned a response that was too large.")
                content = res.read(2_000_001)
                if len(content) > 2_000_000:
                    raise InstallError("The website returned a response that was too large.")
                return res.status, content
        except urllib.error.HTTPError as exc:
            return exc.code, exc.read(4096)
        except (urllib.error.URLError, TimeoutError) as exc:
            raise InstallError("Could not connect to the website over HTTPS. Check the address and TLS certificate.") from exc


class FormFields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.fields = {}
    def handle_starttag(self, tag, attrs):
        if tag.lower() == "input":
            item = dict(attrs)
            if item.get("name") and "value" in item:
                self.fields[item["name"]] = item["value"]


class FtpSite:
    def __init__(self, host: str, user: str, password: str,
                 *, secure: bool = True, port: int = 21):
        self.host = valid_ftp_host(host)
        self.user = user
        self.password = password
        self.secure = secure
        self.port = port
        self.conn = None
        self.root = None
    def __enter__(self):
        if self.secure:
            self.conn = PassiveFtps(context=ssl.create_default_context(), timeout=25)
        else:
            self.conn = PassiveFtp(timeout=25)
        phase = "connect"
        try:
            self.conn.connect(self.host, self.port, timeout=25)
            if self.secure:
                phase = "tls"
                self.conn.auth()
            phase = "login"
            self.conn.login(self.user, self.password)
            if self.secure:
                phase = "data"
                self.conn.prot_p()
            self.conn.set_pasv(True)
            return self
        except ftplib.all_errors as exc:
            self.conn.close()
            self.conn = None
            code = re.match(r"^([1-5][0-9]{2})(?:\s|$)", str(exc)) if isinstance(
                exc, (ftplib.error_perm, ftplib.error_temp)
            ) else None
            status = f" (FTP {code[1]})" if code else ""
            if phase == "tls" and isinstance(exc, ftplib.error_perm) and code and code[1] in {"500", "502", "504"}:
                raise FtpTlsUnavailable(f"The server rejected explicit FTPS{status}.") from None
            if isinstance(exc, ssl.SSLCertVerificationError):
                raise InstallError("The FTP server's TLS certificate could not be verified. Use the exact FTP hostname from 5v.pl.") from None
            if phase == "connect":
                raise InstallError(f"Could not reach the FTP server on port {self.port}{status}. Check the FTP hostname and network.") from None
            if phase == "login":
                raise InstallError(f"FTP login failed{status}. Check the unique FTP username and password in the 5v.pl dashboard.") from None
            if phase == "data":
                raise InstallError(f"FTP login succeeded, but the server could not enable encrypted file transfers{status}.") from None
            raise InstallError(f"Could not establish the FTPS connection{status}. Check the FTP hostname and TLS settings.") from None
    def __exit__(self, *_):
        self.close()
    def close(self):
        if self.conn:
            try:
                self.conn.quit()
            except Exception:
                self.conn.close()
            self.conn = None
    def cd(self, base: str):
        self.conn.cwd(base)
    def exists_dir(self, base: str, rel: str) -> bool:
        safe_relative(rel)
        current = self.conn.pwd()
        try:
            self.cd(base)
            self.conn.cwd(rel)
            return True
        except ftplib.error_perm:
            return False
        finally:
            self.cd(current)
    def ensure_dir(self, base: str, rel: str):
        self.cd(base)
        for part in safe_relative(rel).split("/"):
            try:
                self.conn.cwd(part)
            except ftplib.error_perm:
                self.conn.mkd(part)
                self.conn.cwd(part)
    def put(self, base: str, rel: str, data: bytes):
        path = safe_relative(rel)
        parent, _, name = path.rpartition("/")
        for attempt in range(3):
            if parent:
                self.ensure_dir(base, parent)
            else:
                self.cd(base)
            peer = self.conn.sock.getpeername()[0]
            try:
                self.conn.storbinary("STOR " + name, io.BytesIO(data), blocksize=65536)
            except (TimeoutError, ConnectionError, EOFError, ftplib.error_temp) as exc:
                if attempt == 2 or (isinstance(exc, ftplib.error_temp) and
                                     ftp_failure(exc) not in {"FTP 421", "FTP 425", "FTP 426", "FTP 451"}):
                    raise
                self._reconnect_upload(peer)
            else:
                self.cd(base)
                return

    def _reconnect_upload(self, peer: str):
        mode = self.conn.data_mode
        self.conn.close()
        self.__enter__()
        if self.conn.sock.getpeername()[0] != peer:
            self.close()
            raise InstallError("The FTP server changed during an upload. Check the website and restart installation.")
        if self.conn.af == socket.AF_INET:
            self.conn.data_mode = "PASV" if mode == "EPSV" else "EPSV"
    def get(self, base: str, rel: str, *, optional: bool = False) -> bytes | None:
        path = safe_relative(rel)
        self.cd(base)
        chunks = []
        total = 0
        def collect(data):
            nonlocal total
            total += len(data)
            if total > 1024 * 1024:
                raise InstallError("The remote file exceeds the supported size.")
            chunks.append(data)
        try:
            self.conn.retrbinary("RETR " + path, collect)
        except ftplib.error_perm as exc:
            if optional and str(exc).startswith("550"):
                return None
            raise InstallError("Could not read " + path + " over FTP.") from exc
        return b"".join(chunks)
    def delete(self, base: str, rel: str):
        self.cd(base)
        try:
            self.conn.delete(safe_relative(rel))
        except ftplib.error_perm:
            pass
    def rename(self, base: str, before: str, after: str):
        self.cd(base)
        self.conn.rename(safe_relative(before), safe_relative(after))

    def _enter_folder(self, base: str, path: str) -> str:
        self.cd(base)
        if path != ".":
            for component in safe_relative(path).split("/"):
                self.conn.cwd(component)
        return self.conn.pwd()

    def _site_folders(self, base: str, host: str):
        label = host.split(".", 1)[0]
        standard = (".", host, label, "htdocs", "www", "public_html", "httpdocs", "public")
        likely = (*standard, "domains/" + host + "/public_html",
                  "domains/" + host, "sites/" + host, "www/" + host, "www/" + label,
                  "public_html/" + host, "public_html/" + label, host + "/public_html")
        yield from likely

        checked = set(likely)
        queue = deque([(".", 0)])
        listed = set()
        for _ in range(48):
            if not queue:
                break
            parent, depth = queue.popleft()
            if depth >= 3:
                continue
            try:
                folder = self._enter_folder(base, parent)
                if folder in listed:
                    continue
                listed.add(folder)
                entries = self.conn.nlst()
            except ftplib.error_perm:
                continue
            names = {entry.rstrip("/").rsplit("/", 1)[-1] for entry in entries}
            names = sorted(name for name in names if re.fullmatch(r"[A-Za-z0-9_.-]{1,255}", name)
                           and name not in (".", "..") and not name.startswith("."))
            names.sort(key=lambda name: (name.lower() not in (host, label),
                                         name.lower() not in standard))
            for name in names[:48]:
                path = name if parent == "." else parent + "/" + name
                if path not in checked:
                    checked.add(path)
                    yield path
                if depth < 2 and len(queue) < 48:
                    queue.append((path, depth + 1))

    def verify_folder(self, origin: str, hint: str = "") -> VerifiedFtpFolder:
        home = self.conn.pwd()
        folder = self.find_root(origin, hint)
        return VerifiedFtpFolder(site_origin(origin), self.host, self.user, self.secure,
                                 self.port, hint, self.conn.sock.getpeername()[0],
                                 home, folder, self.conn.data_mode)

    def use_verified_folder(self, origin: str, hint: str, verified: VerifiedFtpFolder) -> bool:
        if (verified.origin, verified.host, verified.user, verified.secure, verified.port, verified.hint) != (
                site_origin(origin), self.host, self.user, self.secure, self.port, hint):
            return False
        if self.conn.sock.getpeername()[0] != verified.peer or self.conn.pwd() != verified.home:
            return False
        try:
            self.cd(verified.folder)
        except ftplib.error_perm:
            return False
        self.root = verified.folder
        if self.conn.af == socket.AF_INET:
            self.conn.data_mode = verified.data_mode
        return True

    def find_root(self, origin: str, hint: str = "") -> str:
        https = HttpsSite(origin)
        try:
            base = self.conn.pwd()
        except ftplib.all_errors as exc:
            raise InstallError(f"FTP login succeeded, but the server could not return its starting folder ({ftp_failure(exc)}).") from None
        host = urllib.parse.urlsplit(https.origin).hostname
        paths = (hint,) if hint else self._site_folders(base, host)
        visited = set()
        http_failures = set()
        writable = False
        stage = "listing folders"
        try:
            for candidate in paths:
                try:
                    stage = "opening a site folder"
                    folder = self._enter_folder(base, candidate)
                    if folder in visited:
                        continue
                    visited.add(folder)
                    if len(visited) > 48:
                        break
                    token = secrets.token_hex(24)
                    name = "muchocore-probe-" + secrets.token_hex(12) + ".php"
                    script = ("<?php header('Content-Type: text/plain'); "
                              "header('Cache-Control: no-store'); "
                              f"echo '{token[:24]}' . '{token[24:]}';").encode("ascii")
                    verified = False
                    try:
                        stage = "uploading the temporary PHP check"
                        self.put(folder, name, script)
                        writable = True
                        status, body = https.request("/" + name)
                        if status == 200 and body.strip() == token.encode("ascii"):
                            verified = True
                        if status >= 500:
                            http_failures.add(status)
                    except (InstallError,) + ftplib.all_errors:
                        try:
                            if self.conn is not None:
                                self.delete(folder, name)
                        except ftplib.all_errors:
                            pass
                        raise
                    else:
                        stage = "removing the temporary PHP check"
                        self.delete(folder, name)
                    if verified:
                        self.root = folder
                        return folder
                except ftplib.error_perm:
                    pass
                stage = "listing folders"
        except ftplib.all_errors as exc:
            raise InstallError(f"FTP login succeeded, but Setup failed while {stage} ({ftp_failure(exc)}).") from None
        finally:
            if self.root is None:
                try:
                    self.cd(base)
                except ftplib.all_errors:
                    pass
        if http_failures:
            codes = ", ".join(str(code) for code in sorted(http_failures))
            raise InstallError(f"The website returned HTTP {codes} during setup. Check that the site opens over HTTPS and retry.")
        if not writable:
            raise InstallError("FTP login succeeded, but Setup could not write to a website folder. Use the FTP account for this site from the 5v.pl dashboard.")
        raise InstallError("Setup could not verify PHP on this website. Check that the website address and FTP account belong to the same site. For a newly created site, retry once it is active.")
    def upload_tree(self, folder: Path, progress=print):
        if self.root is None:
            raise InstallError("Verify the website folder first.")
        files = sorted(p for p in folder.rglob("*") if p.is_file())
        if not files:
            raise InstallError("The archive contains no mc/ files.")
        for i, file in enumerate(files, 1):
            if file.is_symlink():
                raise InstallError("The archive contains a symbolic link.")
            relative = file.relative_to(folder).as_posix()
            safe_relative(relative)
            if relative in {"config/php7-local.php", "public/php7-mode.on"} or \
                    relative.startswith("config/php7-owner"):
                raise InstallError("Setup stopped because the release contains a private configuration file.")
            self.put(self.root, "mc/" + relative, file.read_bytes())
            if i % 30 == 0 or i == len(files):
                progress("Uploaded %s of %s files" % (i, len(files)))
    def apply_routes(self, rules: bytes, *, resume: bool = False):
        if self.root is None:
            raise InstallError("The website folder has not been verified.")
        old = self.get(self.root, ".htaccess", optional=True)
        new = merge_rules(old, rules, resume=resume)
        if new == old:
            return old is not None
        if old is not None:
            self.put(self.root, "mc/config/htaccess-before-muchocore.txt", old)
        tmp = ".htaccess.mucho-" + secrets.token_hex(8)
        self.put(self.root, tmp, new)
        backup = ".htaccess.mucho-backup-" + secrets.token_hex(6) if old is not None else ""
        moved = False
        try:
            if backup:
                self.rename(self.root, ".htaccess", backup)
                moved = True
            try:
                self.rename(self.root, tmp, ".htaccess")
            except Exception:
                if moved:
                    self.rename(self.root, backup, ".htaccess")
                raise
            if moved:
                self.delete(self.root, backup)
        finally:
            self.delete(self.root, tmp)
        return old is not None


def verify_private_folder(ftp: FtpSite, https: HttpsSite):
    token = secrets.token_hex(16)
    name = "mc/config/mucho-private-test-" + secrets.token_hex(6) + ".txt"
    ftp.put(ftp.root, name, token.encode("ascii"))
    try:
        code, body = https.request("/" + name)
        if code not in (401, 403, 404) or token.encode("ascii") in body:
            raise InstallError("Your site exposes its private config/ folder. Setup stopped before uploading passwords.")
    finally:
        ftp.delete(ftp.root, name)
