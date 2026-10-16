#!/usr/bin/env python3
"""Read a Windows GD executable without executing or modifying it.

Reports contain hashes, PE metadata and PHP endpoint names only, never arbitrary
strings, account data or executable bytes. Static discovery is not a runtime
compatibility certificate.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import re
import struct
from pathlib import Path

ENDPOINT = re.compile(rb"(?<![A-Za-z0-9_])([A-Za-z][A-Za-z0-9_]{2,79}\.php)(?![A-Za-z0-9_])", re.I)
ENCODED = re.compile(rb"(?<![A-Za-z0-9+/])[A-Za-z0-9+/]{24,2048}={0,2}(?![A-Za-z0-9+/=])")


def probe(data: bytes) -> dict:
    if len(data) < 64 or data[:2] != b"MZ":
        raise ValueError("Not a Windows PE executable.")
    pe = struct.unpack_from("<I", data, 0x3c)[0]
    if pe < 64 or pe + 24 > len(data) or data[pe:pe + 4] != b"PE\0\0":
        raise ValueError("Invalid PE header.")
    machine, count, timestamp = struct.unpack_from("<HHI", data, pe + 4)
    optional_size = struct.unpack_from("<H", data, pe + 20)[0]
    optional = pe + 24
    table = optional + optional_size
    if count < 1 or count > 96 or optional_size < 64 or table + count * 40 > len(data):
        raise ValueError("Invalid PE section table.")
    magic = struct.unpack_from("<H", data, optional)[0]
    if (machine, magic) not in {(0x8664, 0x20b), (0x14c, 0x10b)}:
        raise ValueError("Expected an x86 or x64 Windows executable.")
    text_sha = None
    for index in range(count):
        at = table + index * 40
        name = data[at:at + 8].rstrip(b"\0")
        size, offset = struct.unpack_from("<II", data, at + 16)
        if offset + size > len(data):
            raise ValueError("PE section extends past the end of the file.")
        if name == b".text":
            if text_sha is not None:
                raise ValueError("Duplicate .text sections.")
            text_sha = hashlib.sha256(data[offset:offset + size]).hexdigest()
    if text_sha is None:
        raise ValueError("No .text section.")

    sources: dict[str, set[str]] = {}

    def collect(blob: bytes, encoding: str) -> None:
        for match in ENDPOINT.finditer(blob):
            name = match[1].decode("ascii")
            sources.setdefault(name, set()).add(encoding)

    collect(data, "ascii")
    # Both alignments are possible in packed/modified clients.
    for alignment in (0, 1):
        for match in re.finditer(rb"(?:[ -~]\x00){6,4096}", data[alignment:]):
            wide = match[0][::2]
            start = alignment + match.start()
            # The last ASCII character + its NUL can look like a wide
            # character immediately preceding a real UTF-16 string.
            if start > 0 and 32 <= data[start - 1] <= 126:
                wide = wide[1:]
            collect(wide, "utf16le")
    for match in ENCODED.finditer(data):
        value = match[0]
        if len(value) % 4:
            continue
        try:
            decoded = base64.b64decode(value, validate=True)
        except ValueError:
            continue
        # Only known HTTP URL shapes, not arbitrary decoded binary strings.
        if decoded.startswith((b"https://", b"http://")):
            collect(decoded.split(b"?", 1)[0].split(b"#", 1)[0], "base64")
    return {
        "schema_version": 1,
        "sha256": hashlib.sha256(data).hexdigest(),
        "size_bytes": len(data),
        "architecture": "x64" if machine == 0x8664 else "x86",
        "coff_timestamp": timestamp,
        "entry_point_rva": struct.unpack_from("<I", data, optional + 16)[0],
        "text_sha256": text_sha,
        "endpoints": [
            {"path": "/" + name, "encodings": sorted(encodings)}
            for name, encodings in sorted(sources.items())
        ],
        "runtime_verified": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--claimed-version", default="", help="User-supplied label; not version detection.")
    args = parser.parse_args()
    if args.input.resolve() == args.output.resolve():
        parser.error("Output must be different from the input executable.")
    try:
        if args.input.stat().st_size > 256 * 1024 * 1024:
            raise ValueError("Input exceeds 256 MiB.")
        report = probe(args.input.read_bytes())
        report["claimed_version"] = args.claimed_version
        args.output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    except (OSError, ValueError) as error:
        parser.exit(2, f"Probe failed: {error}\n")
    print(f"Found {len(report['endpoints'])} endpoint names; wrote {args.output}.")
    print("The EXE was not executed or modified. Runtime compatibility remains unverified.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
