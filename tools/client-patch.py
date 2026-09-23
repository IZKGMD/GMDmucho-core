#!/usr/bin/env python3
"""MuchoCore Geometry Dash client patcher.

Canonical client patching implementation used by the Windows launcher and CI.
It performs conservative, fixed-length replacements for known Geometry Dash
server URL layouts and validates the resulting Windows PE executable.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import pathlib
import re
import sys
from collections import deque
from dataclasses import dataclass
from urllib.parse import urlsplit, urlunsplit

SEGMENTS = ("a", "api", "database", "accounts")
KNOWN_HOSTS = (
    "c92935bj.beget.tech",
    "www.boomlings.com",
    "boomlings.com",
    "www.geometrydash.com",
    "geometrydash.com",
    "www.gdserver.net",
    "gdserver.net",
)
KNOWN_URLS = (
    "https://www.boomlings.com/database",
    "http://www.boomlings.com/database",
    "https://www.boomlings.com/",
    "http://www.boomlings.com/",
    "www.boomlings.com/database",
)


@dataclass(frozen=True)
class PatchStat:
    label: str
    count: int
    replacement: str


def byte_len(value: str) -> int:
    return len(value.encode("ascii"))


def validate_server(value: str) -> str:
    value = value.strip()
    if not value:
        raise ValueError("server URL is required")

    if "://" not in value:
        value = "https://" + value

    parsed = urlsplit(value.rstrip("/"))
    if parsed.scheme not in {"http", "https"}:
        raise ValueError("server URL must use http:// or https://")

    if not parsed.netloc:
        raise ValueError("server URL must include a hostname")

    if parsed.path not in {"", "/"} or parsed.query or parsed.fragment:
        raise ValueError(
            "server URL must be the server root only, for example "
            "https://gdps.example.com"
        )

    return urlunsplit((parsed.scheme, parsed.netloc, "", "", "")).rstrip("/")


def compatibility_url(server: str, desired_length: int, *, bare: bool = False) -> str:
    server = validate_server(server)
    parsed = urlsplit(server)

    queue: deque[tuple[str, ...]] = deque([()])
    seen: set[tuple[str, ...]] = {()}

    while queue:
        parts = queue.popleft()

        if bare:
            prefix = "" if not parts else "/" + "/".join(parts)
            candidate = f"{parsed.netloc}{prefix}/database"
        else:
            path = "" if not parts else "/" + "/".join(parts)
            candidate = urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))

        if byte_len(candidate) == desired_length:
            return candidate

        if len(parts) >= 6:
            continue

        for segment in SEGMENTS:
            if bare and segment == "database":
                continue
            nxt = parts + (segment,)
            if nxt not in seen:
                seen.add(nxt)
                queue.append(nxt)

    raise ValueError(
        f"could not build a compatible {desired_length}-byte URL for {server}"
    )


def is_pe_file(data: bytes) -> bool:
    if len(data) < 0x40 or data[:2] != b"MZ":
        return False

    pe_offset = int.from_bytes(data[0x3C:0x40], "little")
    return (
        0x40 <= pe_offset <= len(data) - 4
        and data[pe_offset:pe_offset + 4] == b"PE\0\0"
    )


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def replace_fixed(data: bytes, old: bytes, new: bytes, label: str) -> tuple[bytes, int]:
    if len(old) != len(new):
        raise ValueError(
            f"{label}: replacement size mismatch ({len(old)} != {len(new)})"
        )
    count = data.count(old)
    return (data.replace(old, new) if count else data), count


def replace_ascii_url(
    data: bytes,
    old_url: str,
    new_url: str,
    label: str,
) -> tuple[bytes, int]:
    old = old_url.encode("ascii")
    new = new_url.encode("ascii")
    return replace_fixed(data, old, new, label)


def replace_utf16_url(
    data: bytes,
    old_url: str,
    new_url: str,
    label: str,
) -> tuple[bytes, int]:
    old = old_url.encode("utf-16le")
    new = new_url.encode("utf-16le")
    return replace_fixed(data, old, new, label)


def replace_base64_url(
    data: bytes,
    old_url: str,
    new_url: str,
    label: str,
) -> tuple[bytes, int]:
    old = base64.b64encode(old_url.encode("ascii"))
    new = base64.b64encode(new_url.encode("ascii"))
    return replace_fixed(data, old, new, label)


def replace_null_terminated_url(
    data: bytes,
    old_host: str,
    new_host: str,
    label: str,
) -> tuple[bytes, int]:
    """Replace a host inside a null-terminated HTTP(S) URL, preserving total field size."""
    old_host_b = old_host.encode("ascii")
    new_host_b = new_host.encode("ascii")
    count = 0
    pos = 0
    data_mut = bytearray(data)

    while True:
        i = data_mut.find(old_host_b, pos)
        if i < 0:
            break

        start = max(0, data_mut.rfind(b"\x00", 0, i) + 1)
        end = data_mut.find(b"\x00", i)
        if end < 0:
            end = i + len(old_host_b)

        prefix = bytes(data_mut[start:i])
        if not prefix.endswith((b"http://", b"https://")):
            pos = i + len(old_host_b)
            continue

        url = bytes(data_mut[start:end])
        patched = url.replace(old_host_b, new_host_b, 1)
        if len(patched) > len(url):
            raise ValueError(
                f"{label}: new hostname is longer than the fixed-size URL field"
            )

        data_mut[start:end] = patched + b"\x00" * (len(url) - len(patched))
        count += 1
        pos = end

    return bytes(data_mut), count


def replace_null_terminated_utf16_host(
    data: bytes,
    old_host: str,
    new_host: str,
    label: str,
) -> tuple[bytes, int]:
    old_b = old_host.encode("utf-16le")
    new_b = new_host.encode("utf-16le")
    zero = b"\x00\x00"
    http = b"http://".decode().encode("utf-16le")
    https = b"https://".decode().encode("utf-16le")

    data_mut = bytearray(data)
    count = 0
    pos = 0

    while True:
        i = data_mut.find(old_b, pos)
        if i < 0:
            break

        start = data_mut.rfind(zero, 0, i) + 2
        end = data_mut.find(zero, i)
        if end < 0:
            end = i + len(old_b)

        prefix = bytes(data_mut[start:i])
        if not prefix.endswith((http, https)):
            pos = i + len(old_b)
            continue

        raw = bytes(data_mut[start:end])
        patched = raw.replace(old_b, new_b, 1)
        if len(patched) > len(raw):
            raise ValueError(
                f"{label}: new UTF-16 hostname is longer than the fixed-size URL field"
            )

        data_mut[start:end] = patched + zero * ((len(raw) - len(patched)) // 2)
        count += 1
        pos = end

    return bytes(data_mut), count


def replace_base64_embedded_host(
    data: bytes,
    old_host: str,
    new_host: str,
    label: str,
) -> tuple[bytes, int]:
    """Patch Base64-encoded null/ASCII URL strings without touching unrelated Base64."""
    old_b = old_host.encode("ascii")
    new_b = new_host.encode("ascii")
    mutated = bytearray(data)
    count = 0

    # Candidate strings are long enough to represent typical URLs and are
    # decoded/validated before touching anything.
    for match in list(re.finditer(rb"[A-Za-z0-9+/]{24,}={0,2}", bytes(mutated))):
        raw = match.group(0)
        try:
            decoded = base64.b64decode(raw, validate=True)
        except (ValueError, base64.binascii.Error):
            continue

        if not decoded.startswith((b"http://", b"https://")):
            continue
        if old_b not in decoded:
            continue

        patched_decoded = decoded.replace(old_b, new_b, 1)
        encoded = base64.b64encode(patched_decoded)

        if len(encoded) > len(raw):
            raise ValueError(
                f"{label}: Base64 replacement changed field size"
            )

        start, end = match.span()
        mutated[start:end] = encoded + b"\x00" * (len(raw) - len(encoded))
        count += 1

    return bytes(mutated), count


def scan_urls(data: bytes) -> list[str]:
    hits: set[str] = set()
    for raw in re.findall(rb"https?://[^\x00\r\n\t ]{3,160}", data):
        try:
            hits.add(raw.decode("ascii"))
        except UnicodeDecodeError:
            continue
    return sorted(hits)


def patch_client(data: bytes, server: str) -> tuple[bytes, list[PatchStat]]:
    server = validate_server(server)
    stats: list[PatchStat] = []

    # 1) Exact legacy layouts with Geometry Dash's fixed-size fields.
    for length, old_url, label in (
        (34, "https://www.boomlings.com/database", "GD 2.2 HTTPS database URL"),
        (33, "http://www.boomlings.com/database", "Legacy HTTP database URL"),
        (26, "https://www.boomlings.com/", "GD HTTPS root URL"),
        (25, "http://www.boomlings.com/", "Legacy HTTP root URL"),
    ):
        try:
            new_url = compatibility_url(server, length)
        except ValueError:
            continue
        data, count = replace_ascii_url(data, old_url, new_url, label)
        if count:
            stats.append(PatchStat(label, count, new_url))

        data, utf_count = replace_utf16_url(
            data,
            old_url,
            new_url,
            label + " [UTF-16]",
        )
        if utf_count:
            stats.append(PatchStat(label + " [UTF-16]", utf_count, new_url))

        data, b64_count = replace_base64_url(
            data,
            old_url,
            new_url,
            label + " [Base64]",
        )
        if b64_count:
            stats.append(PatchStat(label + " [Base64]", b64_count, new_url))

    # 2) Legacy bare database URL.
    try:
        bare_new = compatibility_url(server, 26, bare=True)
        data, count = replace_ascii_url(
            data,
            "www.boomlings.com/database",
            bare_new,
            "Legacy bare database URL",
        )
        if count:
            stats.append(PatchStat("Legacy bare database URL", count, bare_new))
    except ValueError:
        pass

    # 3) Generic known-host URL fields. This catches builds where the host
    # appears in another fixed-size endpoint string not covered above.
    parsed = urlsplit(server)
    for old_host in KNOWN_HOSTS:
        if old_host in parsed.netloc:
            continue

        data, count = replace_null_terminated_url(
            data,
            old_host,
            parsed.netloc,
            f"Known host {old_host}",
        )
        if count:
            stats.append(PatchStat(f"Known host {old_host}", count, parsed.netloc))

        data, utf_count = replace_null_terminated_utf16_host(
            data,
            old_host,
            parsed.netloc,
            f"Known host {old_host} [UTF-16]",
        )
        if utf_count:
            stats.append(PatchStat(
                f"Known host {old_host} [UTF-16]",
                utf_count,
                parsed.netloc,
            ))

        data, b64_count = replace_base64_embedded_host(
            data,
            old_host,
            parsed.netloc,
            f"Known host {old_host} [Base64]",
        )
        if b64_count:
            stats.append(PatchStat(
                f"Known host {old_host} [Base64]",
                b64_count,
                parsed.netloc,
            ))

    return data, stats


def validate_result(original: bytes, patched: bytes, server: str, stats: list[PatchStat]) -> None:
    if not is_pe_file(original):
        raise ValueError("input is not a Windows PE executable (MZ/PE signature missing)")
    if not is_pe_file(patched):
        raise ValueError("patched output is not a valid Windows PE executable")
    if patched == original:
        raise ValueError("patch produced no binary changes")

    old_remaining = [
        host.encode("ascii")
        for host in KNOWN_HOSTS
        if host not in validate_server(server)
    ]

    raw = bytes(patched)
    for host in old_remaining:
        if host in raw:
            # Only fail when it still appears as a direct URL or decoded Base64 URL.
            for url in scan_urls(raw):
                if host.decode("ascii") in url:
                    raise ValueError(
                        f"old server host remains in a direct URL: {url}"
                    )

    if not any(s.count > 0 for s in stats):
        raise ValueError("no supported Geometry Dash server URL was found")

    parsed = urlsplit(validate_server(server))
    if parsed.netloc.encode("ascii") not in raw:
        # Compatibility paths still contain the hostname, so this is a strong sanity check.
        raise ValueError("target hostname is not present in the patched executable")


def build_output_path(input_path: pathlib.Path) -> pathlib.Path:
    base = input_path.with_name(input_path.stem + "-MuchoCore" + input_path.suffix)
    if not base.exists():
        return base
    for index in range(2, 1000):
        candidate = input_path.with_name(
            f"{input_path.stem}-MuchoCore-{index}{input_path.suffix}"
        )
        if not candidate.exists():
            return candidate
    raise ValueError("could not find a free output filename")


def write_report(
    report_path: pathlib.Path,
    input_path: pathlib.Path,
    output_path: pathlib.Path,
    server: str,
    original: bytes,
    patched: bytes,
    stats: list[PatchStat],
) -> None:
    lines = [
        "MuchoCore Client Patch Report",
        "=============================",
        f"Input:      {input_path.resolve()}",
        f"Output:     {output_path.resolve()}",
        f"Server:     {server}",
        f"Input size:  {len(original)} bytes",
        f"Output size: {len(patched)} bytes",
        f"Input SHA256:  {sha256(original)}",
        f"Output SHA256: {sha256(patched)}",
        "",
        "Replacements:",
    ]
    if stats:
        lines.extend(
            f"- {item.label}: {item.count} -> {item.replacement}"
            for item in stats
        )
    else:
        lines.append("- none")
    lines.extend(["", "Detected direct URLs after patch:"])
    urls = scan_urls(patched)
    lines.extend(f"- {url}" for url in urls) if urls else lines.append("- none")
    report_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def run_self_test() -> int:
    server = "https://gdps.example.com"
    for length in (34, 33, 28, 26):
        value = compatibility_url(server, length)
        assert byte_len(value) == length, (length, value)
        print(f"PASS URL {length}: {value}")

    try:
        compatibility_url(server, 29)
    except ValueError:
        print("PASS URL 29: unavailable as expected")
    else:
        raise AssertionError("URL length 29 unexpectedly exists")

    http_server = "http://gdps.example.com"
    for length in (29, 25):
        value = compatibility_url(http_server, length)
        assert value.startswith("http://")
        assert byte_len(value) == length
        print(f"PASS HTTP URL {length}: {value}")

    source = (
        b"MZ" + b"\x00" * 100 +
        b"https://www.boomlings.com/database" +
        b"\x00" * 4
    )
    # Add a PE signature at the location declared by the DOS header.
    source = bytearray(source)
    source[0x3C:0x40] = (0x80).to_bytes(4, "little")
    if len(source) < 0x84:
        source.extend(b"\x00" * (0x84 - len(source)))
    source[0x80:0x84] = b"PE\0\0"

    patched, stats = patch_client(bytes(source), "https://gdps.example.com")
    validate_result(bytes(source), patched, "https://gdps.example.com", stats)
    assert stats
    print("PASS binary patch + PE validation")
    print("MUCHOCORE_CLIENT_PATCHER_OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Patch a Geometry Dash Windows client for a MuchoCore GDPS."
    )
    parser.add_argument("--input", help="Path to original GeometryDash.exe")
    parser.add_argument("--output", help="Output path; defaults to *-MuchoCore.exe")
    parser.add_argument(
        "--server-url",
        default="https://muchogdps.space",
        help="Server root URL (default: https://muchogdps.space)",
    )
    parser.add_argument(
        "--report",
        help="Write a detailed text report next to the output by default.",
    )
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        return run_self_test()

    try:
        input_value = args.input or input(
            "Path to your Geometry Dash client: "
        ).strip()
        input_path = pathlib.Path(input_value).expanduser()

        if not input_path.is_file():
            raise ValueError(f"input file does not exist: {input_path}")
        if input_path.suffix.lower() != ".exe":
            raise ValueError("choose the Geometry Dash .exe file")

        output_path = (
            pathlib.Path(args.output).expanduser()
            if args.output
            else build_output_path(input_path)
        )
        if output_path.resolve() == input_path.resolve():
            raise ValueError("output must be a different file from input")

        server = validate_server(args.server_url)
        original = input_path.read_bytes()

        if not is_pe_file(original):
            raise ValueError("input is not a Windows PE executable")

        patched, stats = patch_client(original, server)
        validate_result(original, patched, server, stats)

        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_bytes(patched)

        report_path = (
            pathlib.Path(args.report).expanduser()
            if args.report
            else output_path.with_suffix(".patch-report.txt")
        )
        write_report(
            report_path,
            input_path,
            output_path,
            server,
            original,
            patched,
            stats,
        )

        print()
        print("MuchoCore client patch complete.")
        print(f"Input:   {input_path}")
        print(f"Output:  {output_path}")
        print(f"Report:  {report_path}")
        print(f"Server:  {server}")
        print(f"SHA256:  {sha256(patched)}")
        print()
        for item in stats:
            print(f"{item.label}: {item.count} -> {item.replacement}")

        return 0

    except (OSError, ValueError, AssertionError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
