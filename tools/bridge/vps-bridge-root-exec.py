#!/usr/bin/env python3
"""Owner-authorized arbitrary root commands for a disposable MuchoCore test VPS.

Explicitly opt-in, not installed by the normal restricted bridge installer.
The public GitHub issue is the command/audit surface. Never run with secrets.
"""
from __future__ import annotations

import base64
import binascii
import os
import re
import subprocess
import sys

MAX_ENCODED = 4096
MAX_DECODED = 3000
PATTERN = re.compile(r"[A-Za-z0-9_-]{1,4096}\Z", flags=re.ASCII)


def decode_command(payload: str) -> str:
    if not PATTERN.fullmatch(payload) or len(payload) > MAX_ENCODED:
        raise ValueError("invalid base64url command payload")
    padded = payload + "=" * (-len(payload) % 4)
    try:
        raw = base64.b64decode(padded, altchars=b"-_", validate=True)
        command = raw.decode("utf-8", errors="strict")
    except (ValueError, UnicodeError, binascii.Error) as exc:
        raise ValueError("malformed command payload") from exc
    if not raw or len(raw) > MAX_DECODED or b"\x00" in raw or b"\r" in raw:
        raise ValueError("invalid length or control character in command")
    if any(ord(ch) < 32 and ch not in "\t\n" for ch in command):
        raise ValueError("invalid control character in command")
    return command


def main(argv: list[str]) -> int:
    if os.geteuid() != 0:
        print("Root executor must run through the explicitly installed sudoers entry.", file=sys.stderr)
        return 77
    if len(argv) != 2:
        print("Exactly one encoded command argument required.", file=sys.stderr)
        return 64
    try:
        command = decode_command(argv[1])
    except ValueError as exc:
        print(f"Rejected: {exc}", file=sys.stderr)
        return 64

    # Full root execution is intentional and owner-authorized. Shell commands,
    # including their stdout/stderr, may appear in a PUBLIC GitHub issue.
    try:
        result = subprocess.run(
            ["/usr/bin/timeout", "--signal=TERM", "--kill-after=5s",
             "90s", "/bin/bash", "--noprofile", "--norc", "-c", command],
            cwd="/",
            env={
                "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
                "HOME": "/root",
                "LANG": "C.UTF-8",
            },
            check=False,
        )
        return result.returncode
    except OSError as exc:
        print(f"Could not launch command: {type(exc).__name__}", file=sys.stderr)
        return 69


if __name__ == "__main__":
    sys.exit(main(sys.argv))
