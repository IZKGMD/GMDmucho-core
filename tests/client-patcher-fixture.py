#!/usr/bin/env python3

from __future__ import annotations

import base64
import hashlib
import os
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATCHER = ROOT / "tools" / "client-patch.py"
SERVER = "https://gdps.example.com"

patterns = [
    b"https://www.boomlings.com/database",
    b"http://www.boomlings.com/database",
    b"https://www.boomlings.com/",
    b"http://www.boomlings.com/",
    b"www.boomlings.com/database",
]

binary = bytearray(b"MAGIC\x00HEADER\x00")
for value in patterns:
    binary.extend(value)
    binary.extend(b"\x00\x01RANDOMDATA\x00")
for value in patterns[:2]:
    binary.extend(base64.b64encode(value))
    binary.extend(b"\x00")

with tempfile.TemporaryDirectory() as temp:
    temp_dir = Path(temp)
    source = temp_dir / "GeometryDash.exe"
    output = temp_dir / "GeometryDash-MuchoCore.exe"

    source.write_bytes(binary)
    original_hash = hashlib.sha256(source.read_bytes()).hexdigest()
    original_size = source.stat().st_size

    result = subprocess.run(
        [
            os.fspath(Path(os.sys.executable)),
            os.fspath(PATCHER),
            "--input",
            os.fspath(source),
            "--output",
            os.fspath(output),
            "--server-url",
            SERVER,
        ],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )

    if result.returncode != 0:
        print(result.stdout)
        print(result.stderr)
        raise SystemExit(result.returncode)

    if not output.is_file():
        raise SystemExit("Fixture test failed: patched output was not created.")

    if source.stat().st_size != original_size:
        raise SystemExit("Fixture test failed: source size changed.")

    if hashlib.sha256(source.read_bytes()).hexdigest() != original_hash:
        raise SystemExit("Fixture test failed: source file was modified.")

    patched = output.read_bytes()

    if len(patched) != original_size:
        raise SystemExit("Fixture test failed: output size changed.")

    for old in patterns:
        if old in patched:
            raise SystemExit(
                f"Fixture test failed: original URL remained: {old!r}"
            )

    print("PATCHER_FIXTURE_OK")
    print(f"Size: {original_size} bytes")
