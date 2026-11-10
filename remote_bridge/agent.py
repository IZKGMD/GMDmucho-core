from __future__ import annotations

import base64
import getpass
import io
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any

import keyring
import psutil
import pyautogui
import requests
from PIL import Image

SERVICE = "MuchoCoreRemoteBridge"
APP_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / "MuchoCoreRemoteBridge"
CONFIG_FILE = APP_DIR / "agent.json"
VERSION = "0.1.0"
MAX_READ = 256 * 1024
MAX_WRITE = 256 * 1024
MAX_OUTPUT = 24000
DANGEROUS = re.compile(
    r"(?i)(remove-item|\\bdel\\b|\\berase\\b|\\brmdir\\b|\\brd\\s+/s|"
    r"format(-volume)?|diskpart|shutdown|restart-computer|stop-computer|"
    r"reg\\s+delete|bcdedit|git\\s+clean|git\\s+reset\\s+--hard|"
    r"docker\\s+compose\\s+down\\s+-v|drop\\s+database|truncate\\s+table|"
    r"rm\\s+-rf|mkfs|\\bdd\\s+if=)"
)


class AgentError(RuntimeError):
    pass


def load_config():
    if not CONFIG_FILE.is_file():
        raise AgentError("Agent is not paired. Run START_SETUP.cmd first.")
    data = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
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


def files_read(cfg, payload):
    path = safe_path(cfg, payload.get("root_index", 0), payload.get("path", ""))
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
    temp = path.with_name(path.name + ".remote-tmp")
    temp.write_text(content, encoding="utf-8", newline="")
    os.replace(temp, path)
    return {"written": str(path), "bytes": path.stat().st_size,
            "backup": str(backup) if backup else None}


def launch_app(cfg, payload):
    app_name = payload.get("name")
    apps = cfg.get("allowed_apps", {})
    if app_name not in apps:
        raise AgentError("Application is not in the local allowlist.")
    executable = apps[app_name]
    approval("pc.app.launch", {"name": app_name, "executable": executable})
    proc = subprocess.Popen([executable], shell=False)
    return {"launched": app_name, "pid": proc.pid}


def run_terminal(cfg, payload):
    root_index = payload.get("root_index", 0)
    cwd = safe_path(cfg, root_index, payload.get("cwd", ""))
    if not cwd.is_dir():
        raise AgentError("Terminal working directory does not exist.")
    command = payload.get("command")
    if not isinstance(command, str) or not command.strip() or len(command) > 6000:
        raise AgentError("Invalid PowerShell command.")
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
            "output": completed.stdout[-MAX_OUTPUT:],
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
    approval("pc.desktop.click", {"x": x, "y": y, "button": button})
    pyautogui.click(x=x, y=y, button=button)
    return {"clicked": True, "x": x, "y": y, "screen": [width, height]}


def desktop_type(payload):
    text = payload.get("text")
    if not isinstance(text, str) or len(text) > 2000:
        raise AgentError("Text must be at most 2000 characters.")
    approval("pc.desktop.type", {"text": text})
    pyautogui.write(text, interval=0.015)
    return {"typed_characters": len(text)}


def desktop_hotkey(payload):
    keys = payload.get("keys")
    if (not isinstance(keys, list) or not 1 <= len(keys) <= 5
            or any(not isinstance(k, str) or k not in pyautogui.KEYBOARD_KEYS for k in keys)):
        raise AgentError("Invalid keyboard shortcut.")
    approval("pc.desktop.hotkey", {"keys": keys})
    pyautogui.hotkey(*keys)
    return {"pressed": keys}


def desktop_scroll(payload):
    amount = payload.get("amount")
    if not isinstance(amount, int) or not -20 <= amount <= 20:
        raise AgentError("Scroll amount must be between -20 and 20.")
    approval("pc.desktop.scroll", {"amount": amount})
    pyautogui.scroll(amount)
    return {"scrolled": amount}


def dispatch(cfg, job):
    action, payload = job["action"], job["payload"]
    handlers = {
        "pc.status": lambda: system_status(),
        "pc.screenshot": lambda: take_screenshot(),
        "pc.files.list": lambda: files_list(cfg, payload),
        "pc.files.read": lambda: files_read(cfg, payload),
        "pc.files.write": lambda: files_write(cfg, payload),
        "pc.app.launch": lambda: launch_app(cfg, payload),
        "pc.terminal.run": lambda: run_terminal(cfg, payload),
        "pc.desktop.click": lambda: desktop_click(payload),
        "pc.desktop.type": lambda: desktop_type(payload),
        "pc.desktop.hotkey": lambda: desktop_hotkey(payload),
        "pc.desktop.scroll": lambda: desktop_scroll(payload),
    }
    if action not in handlers:
        raise AgentError("Unsupported action.")
    return handlers[action]()


def main():
    cfg = load_config()
    token = get_token(cfg)
    base = cfg["base_url"].rstrip("/")
    session = requests.Session()
    session.headers.update({"Authorization": "Bearer " + token})
    print("=" * 68)
    print("MuchoCore Remote Bridge Agent", VERSION)
    print("Connected target:", base)
    print("Agent:", cfg["name"], cfg["agent_id"])
    print("Allowed workspaces:")
    for index, root in enumerate(cfg["allowed_roots"]):
        print(" ", index, root)
    print("Visible foreground agent. Ctrl+C stops remote access immediately.")
    print("=" * 68)
    last_heartbeat = 0
    while True:
        try:
            if time.time() - last_heartbeat > 15:
                session.post(base + "/api/v1/agent/heartbeat",
                             json={"version": VERSION,
                                   "capabilities": ["status", "screenshot", "files",
                                                    "terminal", "desktop", "apps"]},
                             timeout=12).raise_for_status()
                last_heartbeat = time.time()
            response = session.post(base + "/api/v1/agent/poll", timeout=20)
            if response.status_code == 204:
                time.sleep(1.5)
                continue
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
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
