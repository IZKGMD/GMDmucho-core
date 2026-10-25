#!/usr/bin/env python3
"""Inspect embedded GD API URLs without executing, uploading or editing an EXE.

Route presence is a source audit, never evidence of native client acceptance.
Only URL strings inside PE .rdata are considered; external links are excluded.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import struct
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[2]
URL = re.compile(rb"(?:https?://(?:www\.)?(?:boomlings|geometrydash)\.com|%s)"
                 rb"/(?:database|databas)/[A-Za-z0-9._/-]+\.php\x00")


def inspect(binary, root=ROOT):
    if len(binary) < 64 or binary[:2] != b"MZ":
        raise ValueError("Not a Windows PE executable")
    pe = struct.unpack_from("<I", binary, 60)[0]
    if pe > len(binary) - 24 or binary[pe:pe + 4] != b"PE\x00\x00":
        raise ValueError("Invalid PE signature")
    machine, count = struct.unpack_from("<HH", binary, pe + 4)
    optional = struct.unpack_from("<H", binary, pe + 20)[0]
    sections = pe + 24 + optional
    if count < 1 or count > 96 or sections + count * 40 > len(binary):
        raise ValueError("Invalid PE section table")
    rdata = []
    for i in range(count):
        at = sections + i * 40
        if binary[at:at + 8].rstrip(b"\x00") != b".rdata":
            continue
        size, offset = struct.unpack_from("<II", binary, at + 16)
        if size == 0 or offset > len(binary) - size:
            raise ValueError("Invalid .rdata bounds")
        rdata.append((offset, binary[offset:offset + size]))
    if len(rdata) != 1:
        raise ValueError("Expected one .rdata section")

    router = (root / "src/Routing/Router.php").read_text()
    aliases = dict(re.findall(r"'(/[^']+)'\s*=>\s*'(/[^']+)'", router))

    def normalize(path):
        path = re.sub(r"^/(?:(?:database|accounts|api|a)(?:/|$))+", "/", path,
                      flags=re.IGNORECASE)
        return aliases.get(re.sub(r"\.php$", "", path, flags=re.IGNORECASE).lower(),
                           re.sub(r"\.php$", "", path, flags=re.IGNORECASE).lower())

    source = (root / "src/Core/Application.php").read_text()
    routes = {normalize(p) for p in re.findall(r"\$route\(\s*'([^']+)'", source)}
    rows = []
    offset, data = rdata[0]
    for match in URL.finditer(data):
        url = match.group()[:-1].decode("ascii")
        path = urlsplit(url).path if not url.startswith("%s") else url[2:]
        repaired = path.replace("/databas/checkIfServerOnline.php",
                                "/database/checkIfServerOnline.php")
        canonical = normalize(repaired)
        service = "unimplemented"
        if canonical in routes:
            service = "application"
        elif canonical == "/checkifserveronline":
            service = "router-health"
        elif canonical in {"/lostusername", "/lostpassword"}:
            service = "shared-recovery"
        rows.append(dict(offset=offset + match.start(), url=url,
                         template=url.startswith("%s"), canonical_path=canonical,
                         path_repair_required=path != repaired, service=service))
    return dict(kind="static-binary-only", native_client_certified=False,
                sha256=hashlib.sha256(binary).hexdigest(), size=len(binary),
                pe_machine=hex(machine), direct_urls=sum(not r["template"] for r in rows),
                url_templates=sum(r["template"] for r in rows),
                unimplemented_paths=sorted({r["canonical_path"] for r in rows
                                            if r["service"] == "unimplemented"}),
                endpoints=rows)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("exe", type=Path)
    args = parser.parse_args()
    try:
        result = inspect(args.exe.read_bytes())
    except (OSError, ValueError, struct.error) as error:
        parser.exit(2, str(error) + "\n")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
