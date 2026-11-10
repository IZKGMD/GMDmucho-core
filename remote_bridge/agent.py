from __future__ import annotations

import base64
import getpass
import io
import json
import os
import re
import subprocess
import sys
import tempfile
import threading
import time
from datetime import datetime
from pathlib import Path
from typing import Any

import keyring
import psutil
import pyautogui
import pyperclip
import requests
from PIL import Image

from ftp_client import (
    FTPBridgeError, ftp_backup_tree, ftp_clear_root, ftp_download,
    ftp_list, ftp_preview_clear_root, ftp_read_text, ftp_status,
    ftp_upload,
)

SERVICE = "MuchoCoreRemoteBridge"
FTP_SERVICE = "MuchoCoreRemoteBridgeFTP"
APP_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / "MuchoCoreRemoteBridge"
CONFIG_FILE = APP_DIR / "agent.json"
VERSION = "0.1.0"
MAX_READ = 256 * 1024
MAX_WRITE = 256 * 1024
MAX_OUTPUT = 24000
DANGEROUS = re.compile(
    r"(?i)(remove-item|\bdel\b|\berase\b|\brmdir\b|\brd\s+/s|"
    r"format(-volume)?|diskpart|shutdown|restart-computer|stop-computer|"
    r"reg\s+delete|bcdedit|git\s+clean|git\s+reset\s+--hard|"
    r"docker\s+compose\s+down\s+-v|drop\s+database|truncate\s+table|"
    r"rm\s+-rf|mkfs|\bdd\s+if=)"
)
SENSITIVE_READ = re.compile(
    r"(?i)(\.env\b|\.secrets\b|id_rsa|id_ed25519|"
    r"php7-apk-signer|credentials\.json|private[_-]?key)"
)


class AgentError(RuntimeError):
    pass


def load_config():
    if not CONFIG_FILE.is_file():
        raise AgentError("Agent is not paired. Run START_SETUP.cmd first.")
    data = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    from urllib.parse import urlparse
    parsed = urlparse(data.get("base_url", ""))
    if parsed.scheme != "https" or parsed.hostname != "muchogdps.space" or parsed.path.rstrip("/") != "/remote":
        raise AgentError("Agent config has an untrusted bridge URL; re-run START_SETUP.cmd.")
    if not data.get("allowed_roots"):
        raise AgentError("No approved workspace folders configured.")
    return data


def get_token(cfg):
    token = keyring.get_password(SERVICE, cfg["credential_key"])
    if not token:
        raise AgentError("Agent token is missing from Windows Credential Manager.")
    return token


def safe_path(cfg, root_index: int, relative: str = "") -> Path:
    roots = cfg["allowed_roots"]
    if not isinstance(root_index, int) or not 0 <= root_index < len(roots):
        raise AgentError("Unknown workspace root index.")
    root = Path(roots[root_index]).resolve(strict=True)
    if not root.is_dir():
        raise AgentError("Configured workspace root is not a directory.")
    if not isinstance(relative, str) or "\x00" in relative:
        raise AgentError("Invalid relative path.")
    candidate = (root / relative).resolve(strict=False)
    try:
        candidate.relative_to(root)
    except ValueError:
        raise AgentError("Path is outside the locally approved workspace.")
    # Refuse symlink traversal, including links that resolve inside another approved root.
    cursor = root
    for part in Path(relative).parts:
        if part in ("", ".", ".."):
            if part == "..":
                raise AgentError("Path traversal is blocked.")
            continue
        cursor = cursor / part
        if cursor.is_symlink():
            raise AgentError("Symbolic links are blocked for remote file operations.")
    return candidate


def approval(action: str, payload: dict[str, Any], *, dangerous: bool = False) -> None:
    print("\n" + "!" * 68)
    print("REMOTE ACTION REQUEST — local approval required")
    print("Action:", action)
    safe_payload = dict(payload)
    if "content" in safe_payload:
        safe_payload["content"] = "[file content omitted from local approval display]"
    print(json.dumps(safe_payload, ensure_ascii=False, indent=2)[:5000])
    answer = input("Approve this action on your PC? Type YES: ").strip()
    if answer != "YES":
        raise PermissionError("Denied locally by the PC owner.")
    if dangerous:
        phrase = input("Dangerous command detected. Type I UNDERSTAND THE RISK: ").strip()
        if phrase != "I UNDERSTAND THE RISK":
            raise PermissionError("Dangerous action was not confirmed.")
    print("Local approval granted.")


_DESKTOP_LEASE_UNTIL = 0.0


def desktop_approval(action: str, payload: dict[str, Any]) -> None:
    global _DESKTOP_LEASE_UNTIL
    print("\n[DESKTOP ACTION]", action)
    print(json.dumps(payload, ensure_ascii=False, indent=2)[:2500])
    if time.monotonic() < _DESKTOP_LEASE_UNTIL:
        print("Covered by the locally approved five-minute desktop-control session.")
        return
    print("This grants mouse/keyboard control for five minutes. Ctrl+C stops the agent.")
    answer = input("Type exactly ALLOW DESKTOP 5M: ").strip()
    if answer != "ALLOW DESKTOP 5M":
        raise PermissionError("Desktop-control session was not approved locally.")
    _DESKTOP_LEASE_UNTIL = time.monotonic() + 300
    print("Desktop-control session approved for five minutes.")


def system_status():
    vm = psutil.virtual_memory()
    return {
        "computer": os.environ.get("COMPUTERNAME", "Windows"),
        "user": os.environ.get("USERNAME", ""),
        "platform": sys.platform,
        "cpu_percent": psutil.cpu_percent(interval=0.5),
        "ram_total_mb": round(vm.total / 1048576),
        "ram_used_mb": round(vm.used / 1048576),
        "ram_percent": vm.percent,
        "uptime_seconds": int(time.time() - psutil.boot_time()),
        "processes": [
            {"name": p.info.get("name"), "pid": p.info.get("pid")}
            for p in psutil.process_iter(["name", "pid"])
            if p.info.get("name")
        ][:35],
    }


def take_screenshot():
    approval("pc.screenshot", {"screen": "primary desktop"})
    image = pyautogui.screenshot()
    image.thumbnail((1600, 1000))
    buffer = io.BytesIO()
    image.convert("RGB").save(buffer, format="JPEG", quality=72, optimize=True)
    return {"screenshot_base64": base64.b64encode(buffer.getvalue()).decode("ascii"),
            "mime_type": "image/jpeg", "width": image.width, "height": image.height}


def files_list(cfg, payload):
    folder = safe_path(cfg, payload.get("root_index", 0), payload.get("path", ""))
    if not folder.is_dir():
        raise AgentError("Folder does not exist.")
    rows = []
    for p in sorted(folder.iterdir(), key=lambda x: (not x.is_dir(), x.name.lower()))[:250]:
        rows.append({
            "name": p.name, "type": "directory" if p.is_dir() else "file",
            "size": p.stat().st_size if p.is_file() and not p.is_symlink() else None,
            "symlink": p.is_symlink(),
        })
    return {"path": str(folder), "entries": rows}


def is_sensitive_path(path: Path) -> bool:
    names = {part.lower() for part in path.parts}
    blocked = {".env", ".secrets", "secrets", "credentials.json", "config.php", "config.json", "id_rsa", "id_ed25519", "php7-apk-signer.json"}
    return bool(names & blocked) or path.suffix.lower() in {".pem", ".key", ".p12", ".pfx", ".ppk"}


def files_read(cfg, payload):
    path = safe_path(cfg, payload.get("root_index", 0), payload.get("path", ""))
    if is_sensitive_path(path):
        raise AgentError("Reading credential or private-key files is blocked.")
    if not path.is_file() or path.is_symlink():
        raise AgentError("File not found or unsupported.")
    if path.stat().st_size > MAX_READ:
        raise AgentError("File exceeds 256 KiB read limit.")
    try:
        content = path.read_text(encoding="utf-8-sig")
    except UnicodeDecodeError:
        raise AgentError("Only UTF-8 text files can be read through this action.")
    return {"path": str(path), "bytes": path.stat().st_size, "content": content}


def files_write(cfg, payload):
    path = safe_path(cfg, payload.get("root_index", 0), payload.get("path", ""))
    if is_sensitive_path(path):
        raise AgentError("Writing credential or private-key files is blocked.")
    content = payload.get("content")
    if not isinstance(content, str) or len(content.encode("utf-8")) > MAX_WRITE:
        raise AgentError("File content must be UTF-8 text under 256 KiB.")
    if not path.parent.is_dir():
        raise AgentError("Parent directory does not exist; remote agent will not create directories.")
    if path.exists() and not path.is_file():
        raise AgentError("Destination is not a regular file.")
    approval("pc.files.write", {"path": str(path), "bytes": len(content.encode("utf-8")),
                               "overwrite": path.exists()})
    backup = None
    if path.exists():
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        backup = path.with_name(path.name + ".before-remote-" + stamp + ".bak")
        if backup.exists():
            raise AgentError("Backup filename collision; refusing overwrite.")
        backup.write_bytes(path.read_bytes())
    temp_name = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", newline="", dir=path.parent,
            prefix=".mc-remote-", suffix=".tmp", delete=False,
        ) as temp:
            temp_name = temp.name
            temp.write(content)
            temp.flush()
            os.fsync(temp.fileno())
        os.replace(temp_name, path)
    finally:
        if temp_name and os.path.exists(temp_name):
            os.unlink(temp_name)
    return {"written": str(path), "bytes": path.stat().st_size,
            "backup": str(backup) if backup else None}


def files_delete(cfg, payload):
    path = safe_path(cfg, payload.get("root_index", 0), payload.get("path", ""))
    if is_sensitive_path(path):
        raise AgentError("Deleting credential or private-key files is blocked.")
    if not path.is_file() or path.is_symlink():
        raise AgentError("Only one regular file can be deleted; folders and links are blocked.")
    approval("pc.files.delete", {"path": str(path)}, dangerous=True)
    phrase = input("This is recoverable: type DELETE FILE to move it into a local backup: ").strip()
    if phrase != "DELETE FILE":
        raise PermissionError("File deletion was not confirmed.")
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    backup = path.with_name(path.name + ".deleted-" + stamp + ".bak")
    if backup.exists():
        raise AgentError("Backup filename collision; refusing to overwrite.")
    os.replace(path, backup)
    return {"moved_to_recovery_backup": str(backup), "original_path": str(path)}


def launch_app(cfg, payload):
    app_name = payload.get("name")
    apps = cfg.get("allowed_apps", {})
    if app_name not in apps:
        raise AgentError("Application is not in the local allowlist.")
    executable = apps[app_name]
    approval("pc.app.launch", {"name": app_name, "executable": executable})
    proc = subprocess.Popen([executable], shell=False)
    return {"launched": app_name, "pid": proc.pid}


def redact_output(value: str) -> str:
    patterns = [
        (re.compile(r"(?i)(password|passwd|token|secret|api[_-]?key|access[_-]?key|authorization)(\s*[:=]\s*)([^\s,;]+)"), r"\1\2[REDACTED]"),
        (re.compile(r"(?i)gh[pousr]_[A-Za-z0-9_]{20,}"), "[REDACTED_GITHUB_TOKEN]"),
        (re.compile(r"(?i)sk-[A-Za-z0-9_-]{20,}"), "[REDACTED_API_KEY]"),
        (re.compile(r"AKIA[0-9A-Z]{16}"), "[REDACTED_AWS_KEY]"),
        (re.compile(r"(?i)(https?://[^:/\s]+:)[^@/\s]+@"), r"\1[REDACTED]@"),
    ]
    for pattern, replacement in patterns:
        value = pattern.sub(replacement, value)
    return value


def run_terminal(cfg, payload):
    root_index = payload.get("root_index", 0)
    cwd = safe_path(cfg, root_index, payload.get("cwd", ""))
    if not cwd.is_dir():
        raise AgentError("Terminal working directory does not exist.")
    command = payload.get("command")
    if not isinstance(command, str) or not command.strip() or len(command) > 6000:
        raise AgentError("Invalid PowerShell command.")
    if SENSITIVE_READ.search(command):
        raise AgentError("Commands that directly read known credential or private-key files are blocked.")
    dangerous = bool(DANGEROUS.search(command))
    approval("pc.terminal.run", {"cwd": str(cwd), "command": command,
                                 "timeout_seconds": 120}, dangerous=dangerous)
    completed = subprocess.run(
        ["powershell.exe", "-NoLogo", "-NoProfile", "-NonInteractive",
         "-ExecutionPolicy", "RemoteSigned", "-Command", command],
        cwd=str(cwd), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT, text=True, encoding="utf-8", errors="replace",
        timeout=120, check=False, shell=False,
    )
    return {"returncode": completed.returncode,
            "output": redact_output(completed.stdout[-MAX_OUTPUT:]),
            "truncated": len(completed.stdout) > MAX_OUTPUT,
            "cwd": str(cwd)}


def desktop_click(payload):
    x, y = int(payload["x"]), int(payload["y"])
    width, height = pyautogui.size()
    if not (0 <= x < width and 0 <= y < height):
        raise AgentError("Click is outside the current screen.")
    button = payload.get("button", "left")
    if button not in {"left", "right", "middle"}:
        raise AgentError("Unsupported mouse button.")
    desktop_approval("pc.desktop.click", {"x": x, "y": y, "button": button})
    pyautogui.click(x=x, y=y, button=button)
    return {"clicked": True, "x": x, "y": y, "screen": [width, height]}


def desktop_type(payload):
    text = payload.get("text")
    if not isinstance(text, str) or len(text) > 2000:
        raise AgentError("Text must be at most 2000 characters.")
    desktop_approval("pc.desktop.type", {"text": text})
    previous_clipboard = pyperclip.paste()
    try:
        pyperclip.copy(text)
        pyautogui.hotkey("ctrl", "v")
    finally:
        pyperclip.copy(previous_clipboard)
    return {"typed_characters": len(text)}


def desktop_hotkey(payload):
    keys = payload.get("keys")
    if (not isinstance(keys, list) or not 1 <= len(keys) <= 5
            or any(not isinstance(k, str) or k not in pyautogui.KEYBOARD_KEYS for k in keys)):
        raise AgentError("Invalid keyboard shortcut.")
    desktop_approval("pc.desktop.hotkey", {"keys": keys})
    pyautogui.hotkey(*keys)
    return {"pressed": keys}


def desktop_scroll(payload):
    amount = payload.get("amount")
    if not isinstance(amount, int) or not -20 <= amount <= 20:
        raise AgentError("Scroll amount must be between -20 and 20.")
    desktop_approval("pc.desktop.scroll", {"amount": amount})
    pyautogui.scroll(amount)
    return {"scrolled": amount}


def ftp_config(cfg):
    value = cfg.get("ftp")
    if not isinstance(value, dict):
        raise AgentError("5v.pl FTPS is not configured. Run START_FTP_SETUP.cmd first.")
    return value


def ftp_list_action(cfg, payload):
    return {"entries": ftp_list(ftp_config(cfg), payload.get("path", ""))}


def ftp_read_action(cfg, payload):
    return ftp_read_text(ftp_config(cfg), payload.get("path", ""))


def ftp_upload_action(cfg, payload):
    local = safe_path(cfg, payload.get("root_index", 0), payload.get("local_path", ""))
    if is_sensitive_path(local):
        raise AgentError("Uploading credential or private-key files is blocked.")
    remote = payload.get("remote_path", "")
    overwrite = bool(payload.get("overwrite", False))
    if overwrite and payload.get("confirmation") != "OVERWRITE " + remote:
        raise AgentError("Remote overwrite confirmation phrase does not match.")
    approval("ftp.upload_file", {
        "local_path": str(local), "remote_path": remote, "overwrite": overwrite,
    })
    return ftp_upload(ftp_config(cfg), local, remote, overwrite)


def ftp_download_action(cfg, payload):
    remote = payload.get("remote_path", "")
    from ftp_client import blocked_remote_secret, safe_remote_path
    remote = safe_remote_path(remote, allow_root=False)
    if blocked_remote_secret(remote):
        raise AgentError("Downloading credential or signing-key paths is blocked.")
    local = safe_path(cfg, payload.get("root_index", 0), payload.get("local_path", ""))
    if is_sensitive_path(local):
        raise AgentError("Writing credential or private-key paths is blocked.")
    overwrite = bool(payload.get("overwrite", False))
    if overwrite and payload.get("confirmation") != "OVERWRITE " + str(local):
        raise AgentError("Local overwrite confirmation phrase does not match.")
    approval("ftp.download_file", {
        "remote_path": remote, "local_path": str(local), "overwrite": overwrite,
    })
    ftp_cfg = dict(ftp_config(cfg))
    ftp_cfg["local_root"] = str(Path(cfg["allowed_roots"][payload.get("root_index", 0)]).resolve())
    return ftp_download(ftp_cfg, remote, local, overwrite)


def ftp_mkdir_action(cfg, payload):
    from ftp_client import FTPSession, safe_remote_path
    remote = safe_remote_path(payload.get("path", ""), allow_root=False)
    approval("ftp.mkdir", {"remote_path": remote})
    with FTPSession(ftp_config(cfg)) as session:
        session.mkdir(remote)
    return {"created": remote}


def ftp_rename_action(cfg, payload):
    from ftp_client import FTPSession, safe_remote_path
    source = safe_remote_path(payload.get("source", ""), allow_root=False)
    destination = safe_remote_path(payload.get("destination", ""), allow_root=False)
    approval("ftp.rename", {"source": source, "destination": destination})
    with FTPSession(ftp_config(cfg)) as session:
        session.rename(source, destination)
    return {"renamed": source, "to": destination}


def ftp_delete_action(cfg, payload):
    from ftp_client import FTPSession, safe_remote_path
    remote = safe_remote_path(payload.get("path", ""), allow_root=False)
    approval("ftp.delete_file", {"remote_path": remote}, dangerous=True)
    phrase = input("This is recoverable. Type MOVE REMOTE FILE to rename it into a backup: ").strip()
    if phrase != "MOVE REMOTE FILE":
        raise PermissionError("Remote file move was not confirmed.")
    with FTPSession(ftp_config(cfg)) as session:
        backup = session.move_to_backup(remote)
    return {"original_path": remote, "moved_to_recovery_name": backup}


def ftp_backup_root(cfg) -> Path:
    root = Path(cfg.get("backup_root", str(Path.home() / "Documents" / "MuchoCoreRemoteBridge" / "FTPBackups"))).resolve()
    root.mkdir(parents=True, exist_ok=True)
    return root


def ftp_backup_action(cfg, payload):
    local_root = ftp_backup_root(cfg)
    approval("ftp.backup_tree", {"remote_root": ftp_config(cfg).get("root", ""),
                                 "local_backup_root": str(local_root)})
    return ftp_backup_tree(ftp_config(cfg), local_root)


def ftp_preview_clear_action(cfg, payload):
    local_root = ftp_backup_root(cfg)
    return ftp_preview_clear_root(ftp_config(cfg), local_root, payload.get("backup_id", ""))


def ftp_clear_action(cfg, payload):
    local_root = ftp_backup_root(cfg)
    backup_id = payload.get("backup_id", "")
    confirmation = payload.get("confirmation", "")
    expected = "CLEAR 5V " + backup_id
    if confirmation != expected:
        raise AgentError("The server-side confirmation phrase must match the fresh backup ID.")
    approval("ftp.clear_root", {
        "remote_root": ftp_config(cfg).get("root", ""),
        "backup_id": backup_id,
        "files_and_directories_will_be_removed": True,
    }, dangerous=True)
    phrase = input("FULL RESET. Type exactly " + expected + " to continue: ").strip()
    if phrase != expected:
        raise PermissionError("FTPS root clear was not confirmed.")
    return ftp_clear_root(ftp_config(cfg), local_root, backup_id, confirmation)


def dispatch(cfg, job):
    action, payload = job["action"], job["payload"]
    handlers = {
        "pc.status": lambda: system_status(),
        "pc.screenshot": lambda: take_screenshot(),
        "pc.files.list": lambda: files_list(cfg, payload),
        "pc.files.read": lambda: files_read(cfg, payload),
        "pc.files.write": lambda: files_write(cfg, payload),
        "pc.files.delete": lambda: files_delete(cfg, payload),
        "pc.app.launch": lambda: launch_app(cfg, payload),
        "pc.terminal.run": lambda: run_terminal(cfg, payload),
        "pc.desktop.click": lambda: desktop_click(payload),
        "pc.desktop.type": lambda: desktop_type(payload),
        "pc.desktop.hotkey": lambda: desktop_hotkey(payload),
        "pc.desktop.scroll": lambda: desktop_scroll(payload),
        "ftp.status": lambda: ftp_status(ftp_config(cfg)),
        "ftp.list": lambda: ftp_list_action(cfg, payload),
        "ftp.read_text": lambda: ftp_read_action(cfg, payload),
        "ftp.upload_file": lambda: ftp_upload_action(cfg, payload),
        "ftp.download_file": lambda: ftp_download_action(cfg, payload),
        "ftp.mkdir": lambda: ftp_mkdir_action(cfg, payload),
        "ftp.rename": lambda: ftp_rename_action(cfg, payload),
        "ftp.delete_file": lambda: ftp_delete_action(cfg, payload),
        "ftp.backup_tree": lambda: ftp_backup_action(cfg, payload),
        "ftp.preview_clear_root": lambda: ftp_preview_clear_action(cfg, payload),
        "ftp.clear_root": lambda: ftp_clear_action(cfg, payload),
    }
    if action not in handlers:
        raise AgentError("Unsupported action.")
    return handlers[action]()


def heartbeat_loop(base: str, token: str, stop: threading.Event):
    session = requests.Session()
    session.trust_env = False
    session.max_redirects = 0
    session.headers.update({"Authorization": "Bearer " + token})
    while not stop.wait(15):
        try:
            response = session.post(
                base + "/api/v1/agent/heartbeat",
                json={"version": VERSION,
                      "capabilities": ["status", "screenshot", "files", "terminal",
                                       "desktop", "apps", "ftps"]},
                timeout=12,
                allow_redirects=False,
            )
            if response.status_code == 401:
                print("Agent token revoked or invalid. Stop and re-pair this PC.")
                stop.set()
                return
            if 300 <= response.status_code < 400:
                print("[NETWORK] Unexpected redirect refused.")
                stop.set()
                return
            response.raise_for_status()
        except requests.RequestException as exc:
            print("[HEARTBEAT]", type(exc).__name__, str(exc)[:180])


def self_test():
    from ftp_client import FTPBridgeError, safe_remote_path
    assert safe_remote_path("mc/index.php", allow_root=False) == "mc/index.php"
    for unsafe in ("../outside", "/windows/system.ini", r"mc\..\config.php", "mc//file"):
        try:
            safe_remote_path(unsafe)
        except FTPBridgeError:
            continue
        raise AssertionError("Unsafe FTPS path was accepted: " + unsafe)
    assert DANGEROUS.search("Remove-Item -Recurse test")
    assert SENSITIVE_READ.search("Get-Content .env")
    assert "REDACTED" in redact_output("password=example-secret")
    import tempfile
    with tempfile.TemporaryDirectory() as root:
        cfg = {"allowed_roots": [root]}
        assert safe_path(cfg, 0, "notes.txt").parent == Path(root).resolve()
        try:
            safe_path(cfg, 0, "../outside")
        except AgentError:
            pass
        else:
            raise AssertionError("Workspace traversal was accepted")
    return True


def main():
    if '--self-test' in sys.argv:
        self_test()
        print('MuchoCore Remote Agent self-test: PASS')
        return 0
    cfg = load_config()
    token = get_token(cfg)
    base = cfg["base_url"].rstrip("/")
    session = requests.Session()
    session.trust_env = False
    session.max_redirects = 0
    session.headers.update({"Authorization": "Bearer " + token})
    stop = threading.Event()
    heartbeat = threading.Thread(
        target=heartbeat_loop, args=(base, token, stop), daemon=True,
    )
    heartbeat.start()
    print("=" * 68)
    print("MuchoCore Remote Bridge Agent", VERSION)
    print("Connected target:", base)
    print("Agent:", cfg["name"], cfg["agent_id"])
    print("Allowed workspaces:")
    for index, root in enumerate(cfg["allowed_roots"]):
        print(" ", index, root)
    print("Visible foreground agent. Ctrl+C stops remote access immediately.")
    print("=" * 68)
    while not stop.is_set():
        try:
            response = session.post(base + "/api/v1/agent/poll", timeout=20, allow_redirects=False)
            if response.status_code == 204:
                time.sleep(1.5)
                continue
            if 300 <= response.status_code < 400:
                raise AgentError("Unexpected redirect refused; check the configured HTTPS bridge URL.")
            response.raise_for_status()
            job = response.json()
            print("\n[REMOTE JOB]", job["job_id"], job["action"])
            try:
                result = dispatch(cfg, job)
                session.post(base + "/api/v1/agent/result",
                             json={"job_id": job["job_id"], "status": "completed",
                                   "result": result}, timeout=30).raise_for_status()
                print("[JOB COMPLETE]", job["job_id"])
            except PermissionError as exc:
                session.post(base + "/api/v1/agent/result",
                             json={"job_id": job["job_id"], "status": "denied",
                                   "result": {}, "error": str(exc)[:2000]},
                             timeout=20).raise_for_status()
                print("[JOB DENIED]", exc)
            except Exception as exc:
                session.post(base + "/api/v1/agent/result",
                             json={"job_id": job["job_id"], "status": "failed",
                                   "result": {}, "error": type(exc).__name__ + ": " + str(exc)[:1500]},
                             timeout=20).raise_for_status()
                print("[JOB FAILED]", type(exc).__name__, str(exc)[:300])
        except KeyboardInterrupt:
            print("\nAgent stopped by local user.")
            stop.set()
            break
        except requests.HTTPError as exc:
            if exc.response is not None and exc.response.status_code == 401:
                print("Agent token revoked or invalid. Stop and re-pair this PC.")
                return 2
            print("[NETWORK]", type(exc).__name__, str(exc)[:250])
            time.sleep(5)
        except requests.RequestException as exc:
            print("[NETWORK]", type(exc).__name__, str(exc)[:250])
            time.sleep(5)
        except Exception as exc:
            print("[ERROR]", type(exc).__name__, str(exc)[:300])
            time.sleep(3)
    stop.set()
    heartbeat.join(timeout=2)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
