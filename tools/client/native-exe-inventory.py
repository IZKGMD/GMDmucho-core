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
                 rb"/(?:database|databas|a)/[A-Za-z0-9._/-]+\.php\x00")


def _url_pattern(patched_hosts):
    """Accept explicit GDPS hosts without treating arbitrary EXE URLs as game API."""
    hosts = []
    for value in patched_hosts:
        host = value.strip().lower()
        if re.fullmatch(r"[a-z0-9-]+(?:\.[a-z0-9-]+)+", host) is None:
            raise ValueError("Patched host must be a DNS name without scheme or port")
        hosts.append(re.escape(host.encode("ascii")))
    if not hosts:
        return URL
    return re.compile(
        rb"(?:https?://(?:(?:www\.)?(?:boomlings|geometrydash)\.com|"
        + b"|".join(hosts)
        + rb")|%s)/(?:database|databas|a)/[A-Za-z0-9._/-]+\.php\x00",
        re.IGNORECASE,
    )


def inspect(binary, root=ROOT, patched_hosts=()):
    pattern = _url_pattern(patched_hosts)

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
    source_hosts = {}
    offset, data = rdata[0]
    for match in pattern.finditer(data):
        url = match.group()[:-1].decode("ascii")
        is_template = url.startswith("%s")
        parsed = urlsplit(url) if not is_template else None
        host = parsed.hostname if parsed is not None else None
        if host is not None:
            source_hosts[host] = source_hosts.get(host, 0) + 1
        path = parsed.path if parsed is not None else url[2:]
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
                         template=is_template, source_host=host, canonical_path=canonical,
                         path_repair_required=path != repaired, service=service))
    return dict(kind="static-binary-only", native_client_certified=False,
                sha256=hashlib.sha256(binary).hexdigest(), size=len(binary),
                pe_machine=hex(machine), source_hosts=source_hosts,
                direct_urls=sum(not r["template"] for r in rows),
                url_templates=sum(r["template"] for r in rows),
                unimplemented_paths=sorted({r["canonical_path"] for r in rows
                                            if r["service"] == "unimplemented"}),
                endpoints=rows)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("exe", type=Path)
    parser.add_argument("--patched-host", action="append", default=[],
                        help="Explicit GDPS DNS host embedded by the Windows client patcher")
    args = parser.parse_args()
    try:
        result = inspect(args.exe.read_bytes(), patched_hosts=args.patched_host)
    except (OSError, ValueError, struct.error) as error:
        parser.exit(2, str(error) + "\n")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
