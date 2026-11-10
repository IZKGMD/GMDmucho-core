from __future__ import annotations

import hashlib
import json
import posixpath
import secrets
import ssl
from datetime import datetime, timezone
from ftplib import FTP_TLS
from pathlib import Path
from typing import Any

import keyring

FTP_SERVICE = "MuchoCoreRemoteBridgeFTP"
MAX_TEXT = 256 * 1024
MAX_FILE = 128 * 1024 * 1024
MAX_TREE_FILES = 10000
MAX_TREE_BYTES = 1024 * 1024 * 1024


class FTPBridgeError(RuntimeError):
    pass


def safe_remote_path(value: str, allow_root: bool = True) -> str:
    if not isinstance(value, str):
        raise FTPBridgeError("Remote path must be text.")
    value = value.strip()
    if "\x00" in value or "\\" in value or value.startswith("/"):
        raise FTPBridgeError("Use a relative path inside the configured FTPS root.")
    if value in ("", "."):
        if allow_root:
            return ""
        raise FTPBridgeError("The configured remote root cannot be used as a file path.")
    parts = value.split("/")
    if any(part in ("", ".", "..") for part in parts):
        raise FTPBridgeError("Path traversal and empty path components are blocked.")
    if any(any(ord(ch) < 32 for ch in part) for part in parts):
        raise FTPBridgeError("Control characters are blocked in remote paths.")
    return "/".join(parts)


def blocked_remote_secret(path: str) -> bool:
    parts = [part.lower() for part in path.split("/") if part]
    blocked = {
        ".env", ".secrets", "secrets", "config.php", "config.json",
        "database.php", "db.php", "credentials.json", "php7-apk-signer.json",
        "admin_password", "id_rsa", "id_ed25519",
    }
    return any(part in blocked or part.startswith("secret") for part in parts) or any(
        part.endswith((".pem", ".key", ".p12", ".pfx", ".ppk")) for part in parts
    )


def _within(path: Path, root: Path) -> bool:
    try:
        path.resolve().relative_to(root.resolve())
        return True
    except ValueError:
        return False


class FTPSession:
    def __init__(self, cfg: dict[str, Any]):
        self.cfg = cfg
        self.ftp: FTP_TLS | None = None
        self.root_pwd = ""

    def __enter__(self):
        required = ("host", "port", "username", "root", "credential_key")
        if any(key not in self.cfg for key in required):
            raise FTPBridgeError("FTPS is not configured on this Windows Agent.")
        password = keyring.get_password(FTP_SERVICE, self.cfg["credential_key"])
        if not password:
            raise FTPBridgeError("FTPS password is missing from Windows Credential Manager.")
        ftp = FTP_TLS(context=ssl.create_default_context())
        try:
            ftp.connect(self.cfg["host"], int(self.cfg["port"]), timeout=25)
            ftp.auth()
            ftp.login(self.cfg["username"], password)
            ftp.prot_p()
            ftp.set_pasv(True)
            root = self.cfg["root"].strip()
            if "\\" in root or "\x00" in root or any(part == ".." for part in root.split("/")):
                raise FTPBridgeError("Invalid configured FTPS root.")
            if root:
                ftp.cwd(root)
            self.root_pwd = ftp.pwd()
            self.ftp = ftp
            return self
        except Exception as exc:
            try:
                ftp.close()
            except Exception:
                pass
            if isinstance(exc, FTPBridgeError):
                raise
            raise FTPBridgeError(
                "FTPS connection failed. Check the 5v.pl host, credentials, explicit TLS support and remote root. "
                + type(exc).__name__ + ": " + str(exc)[:180]
            ) from exc

    def __exit__(self, exc_type, exc, tb):
        if self.ftp is not None:
            try:
                self.ftp.quit()
            except Exception:
                try:
                    self.ftp.close()
                except Exception:
                    pass
            self.ftp = None

    def _ftp(self) -> FTP_TLS:
        if self.ftp is None:
            raise FTPBridgeError("FTPS session is not connected.")
        return self.ftp

    def cwd(self, relative: str = ""):
        ftp = self._ftp()
        ftp.cwd(self.root_pwd)
        if relative:
            for part in relative.split("/"):
                ftp.cwd(part)
                current = posixpath.normpath(ftp.pwd())
                root = posixpath.normpath(self.root_pwd)
                if current != root and not current.startswith(root.rstrip("/") + "/"):
                    raise FTPBridgeError("FTPS server resolved a path outside the configured root.")

    def parent(self, relative: str):
        rel = safe_remote_path(relative, allow_root=False)
        parent, name = posixpath.split(rel)
        if not name:
            raise FTPBridgeError("Remote filename is required.")
        self.cwd(parent)
        return parent, name

    def remote_exists(self, name: str) -> bool:
        ftp = self._ftp()
        try:
            ftp.sendcmd("MLST " + name)
            return True
        except Exception as mlst_error:
            code = str(mlst_error)[:3]
            if code not in {"500", "502", "504", "550"}:
                raise FTPBridgeError("Could not safely determine whether the remote destination exists.")
            try:
                return bool(ftp.nlst(name))
            except Exception as nlst_error:
                if str(nlst_error).startswith("550"):
                    return False
                raise FTPBridgeError("Server cannot safely check remote destination existence.") from nlst_error

    def list_dir(self, relative: str = "") -> list[dict[str, Any]]:
        rel = safe_remote_path(relative)
        self.cwd(rel)
        rows = []
        for name, facts in self._ftp().mlsd():
            if name in (".", "..") or "/" in name or "\\" in name:
                continue
            kind = facts.get("type", "unknown").lower()
            if kind in ("cdir", "pdir"):
                continue
            if "slink" in kind or kind == "symlink":
                kind = "symlink"
            try:
                size = int(facts["size"]) if facts.get("size") is not None else None
            except (TypeError, ValueError):
                size = None
            rows.append({"name": name, "type": kind, "size": size,
                         "modified": facts.get("modify")})
        return sorted(rows, key=lambda item: (item["type"] != "dir", item["name"].lower()))

    def size(self, relative: str) -> int:
        parent, name = self.parent(relative)
        try:
            try:
                value = self._ftp().size(name)
                if value is not None:
                    return int(value)
            except Exception:
                pass
        finally:
            self.cwd("")
        return len(self.read_bytes(relative, MAX_FILE))

    def read_bytes(self, relative: str, limit: int = MAX_FILE) -> bytes:
        parent, name = self.parent(relative)
        data = bytearray()
        try:
            size = self._ftp().size(name)
            if size is not None and int(size) > limit:
                raise FTPBridgeError("Remote file exceeds the configured download limit.")
            def receive(chunk: bytes):
                data.extend(chunk)
                if len(data) > limit:
                    raise FTPBridgeError("Remote file exceeded the configured download limit.")
            self._ftp().retrbinary("RETR " + name, receive)
            return bytes(data)
        finally:
            self.cwd("")

    def upload_bytes(self, relative: str, data: bytes, overwrite: bool = False):
        parent, name = self.parent(relative)
        ftp = self._ftp()
        exists = self.remote_exists(name)
        if exists and not overwrite:
            raise FTPBridgeError("Remote destination already exists.")
        temp = ".mc-upload-" + secrets.token_hex(8) + ".tmp"
        backup = None
        try:
            import io
            ftp.storbinary("STOR " + temp, io.BytesIO(data))
            if exists:
                stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
                backup = name + ".before-remote-" + stamp
                ftp.rename(name, backup)
            try:
                ftp.rename(temp, name)
            except Exception:
                if backup:
                    ftp.rename(backup, name)
                raise
            return {"remote_backup": (parent + "/" if parent else "") + backup if backup else None}
        except Exception:
            try:
                ftp.delete(temp)
            except Exception:
                pass
            raise
        finally:
            self.cwd("")

    def mkdir(self, relative: str):
        parent, name = self.parent(relative)
        self._ftp().mkd(name)
        self.cwd("")

    def rename(self, source: str, destination: str):
        src = safe_remote_path(source, allow_root=False)
        dst = safe_remote_path(destination, allow_root=False)
        ftp = self._ftp()
        self.cwd("")
        if self.remote_exists(dst):
            raise FTPBridgeError("Remote destination already exists.")
        ftp.rename(src, dst)
        self.cwd("")

    def move_to_backup(self, relative: str, marker: str = ".deleted-"):
        parent, name = self.parent(relative)
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        backup = name + marker + stamp + "-" + secrets.token_hex(3)
        self._ftp().rename(name, backup)
        self.cwd("")
        return (parent + "/" if parent else "") + backup

    def walk(self) -> tuple[list[dict[str, Any]], list[str]]:
        files: list[dict[str, Any]] = []
        directories: list[str] = []

        def visit(folder: str):
            for item in self.list_dir(folder):
                child = (folder + "/" if folder else "") + item["name"]
                child = safe_remote_path(child, allow_root=False)
                if item["type"] == "dir":
                    directories.append(child)
                    if len(files) + len(directories) > MAX_TREE_FILES:
                        raise FTPBridgeError("Remote tree exceeds the 10,000-entry backup limit.")
                    visit(child)
                elif item["type"] == "file":
                    size = item["size"]
                    if size is None:
                        size = self.size(child)
                    files.append({"path": child, "size": int(size or 0)})
                    if len(files) + len(directories) > MAX_TREE_FILES:
                        raise FTPBridgeError("Remote tree exceeds the 10,000-entry backup limit.")
        visit("")
        if sum(item["size"] for item in files) > MAX_TREE_BYTES:
            raise FTPBridgeError("Remote tree exceeds the 1 GiB backup limit.")
        return files, sorted(directories, key=lambda item: (item.count("/"), item))

    def verify_tree(self, manifest: dict[str, Any]):
        current_files, current_dirs = self.walk()
        expected_files = sorted(
            [{"path": item["path"], "size": int(item["size"])} for item in manifest["files"]],
            key=lambda item: item["path"],
        )
        if expected_files != sorted(current_files, key=lambda item: item["path"]):
            raise FTPBridgeError("Remote file list or sizes changed since backup.")
        if sorted(manifest["directories"]) != current_dirs:
            raise FTPBridgeError("Remote directories changed since backup.")
        for item in manifest["files"]:
            data = self.read_bytes(item["path"], MAX_FILE)
            digest = hashlib.sha256(data).hexdigest()
            if digest != item.get("sha256") or len(data) != int(item["size"]):
                raise FTPBridgeError("Remote file content changed since backup: " + item["path"])
        return True

    def clear_tree(self, manifest: dict[str, Any]):
        # Re-hash every remote file immediately before deletion. A size-only
        # comparison is not sufficient for a destructive reset.
        self.verify_tree(manifest)
        current_files, current_dirs = self.walk()
        for item in sorted(current_files, key=lambda value: value["path"].count("/"), reverse=True):
            parent, name = self.parent(item["path"])
            self._ftp().delete(name)
        for directory in sorted(current_dirs, key=lambda value: value.count("/"), reverse=True):
            parent, name = self.parent(directory)
            self._ftp().rmd(name)
        self.cwd("")
        return {"deleted_files": len(current_files), "removed_directories": len(current_dirs),
                "configured_root_preserved": True}


def ftp_status(cfg: dict[str, Any]) -> dict[str, Any]:
    with FTPSession(cfg) as session:
        cipher = None
        try:
            cipher = session.ftp.sock.cipher()[0] if session.ftp and session.ftp.sock else None
        except Exception:
            pass
        return {
            "connected": True, "protocol": "explicit FTPS with certificate verification and protected data channel",
            "host": cfg["host"], "port": int(cfg["port"]),
            "username": cfg["username"], "configured_root": cfg["root"],
            "remote_root_pwd": session.root_pwd, "tls_cipher": cipher,
        }


def ftp_list(cfg: dict[str, Any], path: str = ""):
    with FTPSession(cfg) as session:
        return session.list_dir(path)


def ftp_read_text(cfg: dict[str, Any], path: str):
    rel = safe_remote_path(path, allow_root=False)
    if blocked_remote_secret(rel):
        raise FTPBridgeError("Reading credential or signing-key paths is blocked.")
    with FTPSession(cfg) as session:
        data = session.read_bytes(rel, MAX_TEXT)
    try:
        return {"path": rel, "bytes": len(data), "content": data.decode("utf-8-sig")}
    except UnicodeDecodeError:
        raise FTPBridgeError("Remote file is not UTF-8 text.")


def ftp_upload(cfg: dict[str, Any], local_path: Path, remote_path: str, overwrite: bool):
    if not local_path.is_file() or local_path.is_symlink():
        raise FTPBridgeError("Local source must be a regular file.")
    if local_path.stat().st_size > MAX_FILE:
        raise FTPBridgeError("Local file exceeds the 128 MiB upload limit.")
    data = local_path.read_bytes()
    with FTPSession(cfg) as session:
        result = session.upload_bytes(remote_path, data, overwrite=overwrite)
    return {"uploaded": remote_path, "bytes": len(data), **result}


def ftp_download(cfg: dict[str, Any], remote_path: str, local_path: Path, overwrite: bool):
    if not _within(local_path, Path(cfg["local_root"])):
        raise FTPBridgeError("Download destination is outside the approved workspace.")
    if local_path.exists() and not overwrite:
        raise FTPBridgeError("Local destination already exists.")
    if local_path.exists() and not local_path.is_file():
        raise FTPBridgeError("Local destination is not a regular file.")
    with FTPSession(cfg) as session:
        data = session.read_bytes(remote_path, MAX_FILE)
    local_path.parent.mkdir(parents=True, exist_ok=True)
    backup = None
    if local_path.exists():
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        backup = local_path.with_name(local_path.name + ".before-ftp-" + stamp + ".bak")
        backup.write_bytes(local_path.read_bytes())
    temp = local_path.with_name(".mc-ftp-" + secrets.token_hex(6) + ".tmp")
    try:
        temp.write_bytes(data)
        temp.replace(local_path)
    finally:
        if temp.exists():
            temp.unlink()
    return {"downloaded": remote_path, "local_path": str(local_path),
            "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest(),
            "local_backup": str(backup) if backup else None}


def ftp_backup_tree(cfg: dict[str, Any], local_root: Path):
    backup_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + secrets.token_hex(4)
    target = local_root / "MuchoCoreFTPBackups" / backup_id
    target.mkdir(parents=True, exist_ok=False)
    with FTPSession(cfg) as session:
        files, directories = session.walk()
        for directory in directories:
            (target / directory).mkdir(parents=True, exist_ok=True)
        saved = []
        total = 0
        for item in files:
            data = session.read_bytes(item["path"], MAX_FILE)
            total += len(data)
            if total > MAX_TREE_BYTES:
                raise FTPBridgeError("Downloaded backup exceeded 1 GiB; remote files were not changed.")
            local = target / item["path"]
            local.parent.mkdir(parents=True, exist_ok=True)
            local.write_bytes(data)
            saved.append({"path": item["path"], "size": len(data),
                          "sha256": hashlib.sha256(data).hexdigest()})
        manifest = {
            "backup_id": backup_id, "created_utc": datetime.now(timezone.utc).isoformat(),
            "host": cfg["host"], "username": cfg["username"], "root": cfg["root"],
            "remote_root_pwd": session.root_pwd, "files": saved,
            "directories": directories, "total_bytes": total, "database_included": False,
        }
        (target / "manifest.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8"
        )
    return {"backup_id": backup_id, "local_directory": str(target),
            "files": len(saved), "directories": len(directories), "bytes": total,
            "database_included": False,
            "note": "FTP files only; export MySQL separately before a full reset."}


def load_backup_manifest(local_root: Path, backup_id: str) -> tuple[Path, dict[str, Any]]:
    import re
    if not re.fullmatch(r"\d{8}T\d{6}Z-[a-f0-9]{8}", backup_id):
        raise FTPBridgeError("Invalid backup ID.")
    folder = local_root / "MuchoCoreFTPBackups" / backup_id
    manifest_path = folder / "manifest.json"
    if not manifest_path.is_file() or manifest_path.is_symlink():
        raise FTPBridgeError("Local backup manifest was not found.")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest.get("backup_id") != backup_id:
        raise FTPBridgeError("Backup manifest ID mismatch.")
    for item in manifest.get("files", []):
        rel = safe_remote_path(item.get("path", ""), allow_root=False)
        local = folder.joinpath(*rel.split("/"))
        if not _within(local, folder) or local.is_symlink() or not local.is_file():
            raise FTPBridgeError("A local backup file is missing or unsafe: " + rel)
        data = local.read_bytes()
        if len(data) != int(item.get("size", -1)) or hashlib.sha256(data).hexdigest() != item.get("sha256"):
            raise FTPBridgeError("A local backup file failed SHA-256 verification: " + rel)
    return folder, manifest


def ftp_preview_clear_root(cfg: dict[str, Any], local_root: Path, backup_id: str):
    if not cfg.get("root", "").strip():
        raise FTPBridgeError("Full-root clearing is disabled until a specific remote root is configured.")
    _, manifest = load_backup_manifest(local_root, backup_id)
    if (manifest.get("host") != cfg["host"] or manifest.get("username") != cfg["username"]
            or manifest.get("root") != cfg["root"]):
        raise FTPBridgeError("Backup belongs to another FTPS account or configured root.")
    with FTPSession(cfg) as session:
        if manifest.get("remote_root_pwd") != session.root_pwd:
            raise FTPBridgeError("Backup root does not match the current remote root.")
        session.verify_tree(manifest)
    return {
        "backup_id": backup_id, "unchanged_since_backup": True,
        "files_to_delete": len(manifest["files"]),
        "directories_to_remove": len(manifest["directories"]),
        "configured_root_preserved": True,
        "confirmation_phrase": "CLEAR 5V " + backup_id,
        "database_included": False,
    }


def ftp_clear_root(cfg: dict[str, Any], local_root: Path, backup_id: str, confirmation: str):
    preview = ftp_preview_clear_root(cfg, local_root, backup_id)
    if not preview["unchanged_since_backup"]:
        raise FTPBridgeError("Remote tree differs from backup. No remote files were deleted.")
    if confirmation != preview["confirmation_phrase"]:
        raise FTPBridgeError("Exact clear confirmation phrase does not match.")
    _, manifest = load_backup_manifest(local_root, backup_id)
    with FTPSession(cfg) as session:
        if manifest.get("remote_root_pwd") != session.root_pwd:
            raise FTPBridgeError("Remote root changed since backup.")
        return session.clear_tree(manifest)
