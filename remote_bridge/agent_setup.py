from __future__ import annotations

import getpass
import json
import os
import re
import shutil
import sys
from pathlib import Path
from urllib.parse import urlparse

import keyring
import requests
import tkinter as tk
from tkinter import filedialog

SERVICE = "MuchoCoreRemoteBridge"
APP_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / "MuchoCoreRemoteBridge"
CONFIG = APP_DIR / "agent.json"
ALLOWED_HOSTS = {"muchogdps.space"}


def choose_roots():
    root = tk.Tk()
    root.withdraw()
    selected = []
    print("Select one or more workspace folders. Only these folders are accessible remotely.")
    while len(selected) < 4:
        path = filedialog.askdirectory(
            title="Choose a folder allowed for MuchoCore Remote Bridge"
        )
        if not path:
            break
        resolved = str(Path(path).resolve())
        if resolved not in selected:
            selected.append(resolved)
        again = input("Add another allowed folder? [y/N]: ").strip().lower()
        if again != "y":
            break
    root.destroy()
    if not selected:
        raise SystemExit("At least one workspace folder must be selected.")
    return selected


def main():
    print("=" * 62)
    print("MuchoCore Remote Bridge — Windows Agent setup")
    print("Visible agent. No inbound ports. No administrator rights.")
    print("=" * 62)
    base_url = input("Bridge URL [https://muchogdps.space/remote]: ").strip()
    if not base_url:
        base_url = "https://muchogdps.space/remote"
    parsed = urlparse(base_url)
    if (parsed.scheme != "https" or parsed.hostname not in ALLOWED_HOSTS
            or parsed.path.rstrip("/") != "/remote" or parsed.query or parsed.fragment):
        raise SystemExit("Only https://muchogdps.space/remote is accepted in this build.")
    base_url = base_url.rstrip("/")
    pairing = getpass.getpass("One-time pairing token (paste locally; do not send in chat): ").strip()
    if len(pairing) < 32:
        raise SystemExit("Pairing token looks invalid.")
    name = input("Agent name [Edward-PC]: ").strip() or "Edward-PC"
    if not re.fullmatch(r"[A-Za-z0-9 _.-]{1,64}", name):
        raise SystemExit("Invalid agent name.")
    roots = choose_roots()

    print("\nPairing with the VPS...")
    response = requests.post(
        base_url + "/api/v1/agent/pair",
        json={"pairing_token": pairing, "name": name},
        timeout=15,
        verify=True,
    )
    if response.status_code != 200:
        raise SystemExit("Pairing failed: HTTP " + str(response.status_code) + " " + response.text[:300])
    data = response.json()
    agent_id = data["agent_id"]
    token = data["agent_token"]
    credential_key = agent_id
    keyring.set_password(SERVICE, credential_key, token)
    if keyring.get_password(SERVICE, credential_key) != token:
        raise SystemExit("Windows Credential Manager verification failed.")
    APP_DIR.mkdir(parents=True, exist_ok=True)
    config = {
        "base_url": base_url,
        "agent_id": agent_id,
        "credential_key": credential_key,
        "name": name,
        "allowed_roots": roots,
        "allowed_apps": {
            "notepad": shutil.which("notepad.exe") or "notepad.exe",
            "explorer": shutil.which("explorer.exe") or "explorer.exe",
            "powershell": shutil.which("powershell.exe") or "powershell.exe",
            "cmd": shutil.which("cmd.exe") or "cmd.exe",
        },
    }
    CONFIG.write_text(json.dumps(config, ensure_ascii=False, indent=2), encoding="utf-8")
    try:
        os.chmod(CONFIG, 0o600)
    except OSError:
        pass
    print("\nPairing complete.")
    print("Agent ID:", agent_id)
    print("Allowed workspaces:")
    for item in roots:
        print(" -", item)
    print("Token stored in Windows Credential Manager.")
    print("Start the visible agent with START_AGENT.cmd.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
