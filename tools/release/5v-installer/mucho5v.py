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
import ssl
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

SITE_RE = re.compile(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.5v\.pl", re.I)
FTP_RE = re.compile(r"[a-z0-9-]+(?:\.[a-z0-9-]+)+", re.I)
BEGIN = "# BEGIN MUCHOCORE 5V.PL ROUTES"
END = "# END MUCHOCORE 5V.PL ROUTES"


class InstallError(RuntimeError):
    """Safe, user-readable installation error."""


class FtpTlsUnavailable(InstallError):
    """The FTP server explicitly rejected the AUTH TLS command."""


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
    lines = [line.strip() for line in raw.splitlines()
             if line.strip().startswith("RewriteRule ")]
    if len(lines) < 8 or any("mc/public/php7.php" not in line for line in lines):
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
            self.conn = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=25)
        else:
            self.conn = ftplib.FTP(timeout=25)
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
        if parent:
            self.ensure_dir(base, parent)
        else:
            self.cd(base)
        self.conn.storbinary("STOR " + name, io.BytesIO(data), blocksize=65536)
        self.cd(base)
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
    def find_root(self, origin: str, hint: str = "") -> str:
        https = HttpsSite(origin)
        base = self.conn.pwd()
        host = urllib.parse.urlsplit(origin).hostname
        paths = [hint] if hint else [
            ".", "htdocs", "www", "public_html", "httpdocs", "public",
            "domains/" + host, "domains/" + host + "/public_html",
            "www/" + host, "public_html/" + host,
        ]
        token = secrets.token_hex(24)
        name = "muchocore-probe-" + secrets.token_hex(8) + ".txt"
        http_failures = set()
        for candidate in paths:
            try:
                self.cd(base)
                if candidate != ".":
                    self.cd(base)
                    for part in safe_relative(candidate).split("/"):
                        self.conn.cwd(part)
                folder = self.conn.pwd()
                self.put(folder, name, token.encode("ascii"))
                try:
                    status, body = https.request("/" + name)
                    if status == 200 and body.strip() == token.encode("ascii"):
                        self.root = folder
                        return folder
                    if status >= 500:
                        http_failures.add(status)
                finally:
                    self.delete(folder, name)
            except ftplib.error_perm:
                continue
            except ftplib.all_errors:
                raise InstallError("FTP login succeeded, but the file transfer failed. Check passive FTP access and the site's write permissions.") from None
        if http_failures:
            codes = ", ".join(str(code) for code in sorted(http_failures))
            raise InstallError(f"FTP login succeeded, but the website returned HTTP {codes}. Check that the site opens over HTTPS before retrying.")
        raise InstallError("FTP login succeeded, but Setup could not verify the website folder over HTTPS. Enter the folder in Advanced settings.")
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
