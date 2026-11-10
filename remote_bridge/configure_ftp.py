from __future__ import annotations

import getpass
import json
import os
import re
from pathlib import Path

import keyring

SERVICE = "MuchoCoreRemoteBridgeFTP"
CONFIG = Path(os.environ.get("APPDATA", str(Path.home()))) / "MuchoCoreRemoteBridge" / "agent.json"


def main():
    if not CONFIG.is_file():
        raise SystemExit("Run START_SETUP.cmd and pair the Windows Agent first.")
    cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    print("=" * 60)
    print("MuchoCore Remote Bridge — optional 5v.pl FTPS setup")
    print("The FTP password is stored only in Windows Credential Manager.")
    print("Do not paste FTP credentials into ChatGPT.")
    print("=" * 60)
    host = input("FTPS host from your 5v.pl control panel: ").strip()
    if not re.fullmatch(r"[A-Za-z0-9.-]{1,253}", host):
        raise SystemExit("Invalid FTP host.")
    port_text = input("Explicit FTPS port [21]: ").strip() or "21"
    if not port_text.isdigit() or not 1 <= int(port_text) <= 65535:
        raise SystemExit("Invalid FTP port.")
    username = input("FTP username: ").strip()
    if not username or len(username) > 255:
        raise SystemExit("Invalid FTP username.")
    remote_root = input("Exact remote root (example: mc; empty only for read-only discovery): ").strip()
    if "\\" in remote_root or "\x00" in remote_root or any(x == ".." for x in remote_root.split("/")):
        raise SystemExit("Invalid remote root.")
    password = getpass.getpass("FTP password (hidden): ")
    if not password:
        raise SystemExit("Password cannot be empty.")
    credential_key = host.lower() + "|" + username
    keyring.set_password(SERVICE, credential_key, password)
    if keyring.get_password(SERVICE, credential_key) != password:
        raise SystemExit("Windows Credential Manager verification failed.")
    cfg["ftp"] = {
        "host": host,
        "port": int(port_text),
        "username": username,
        "root": remote_root,
        "credential_key": credential_key,
    }
    CONFIG.write_text(json.dumps(cfg, ensure_ascii=False, indent=2), encoding="utf-8")
    try:
        os.chmod(CONFIG, 0o600)
    except OSError:
        pass
    print("\nFTPS settings saved locally. No password was written to agent.json.")
    print("Restart START_AGENT.cmd, then run ftp.status from the web panel.")
    print("Do not clear any remote directory before a full FTP backup and separate MySQL export.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
