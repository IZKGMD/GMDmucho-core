#!/usr/bin/env python3
"""Patch a legally obtained Geometry Dash client binary for a MuchoCore GDPS."""

from __future__ import annotations

import argparse
import base64
import pathlib
import sys
from collections import deque
from urllib.parse import urlsplit, urlunsplit

SEGMENTS = ("a", "api", "database", "accounts")


def byte_len(value: str) -> int:
    return len(value.encode("ascii"))


def compatibility_url(server: str, desired_length: int, *, bare: bool = False) -> str:
    value = server if "://" in server else f"https://{server}"
    parsed = urlsplit(value.rstrip("/"))

    if parsed.scheme not in {"http", "https"}:
        raise ValueError("server URL must use http:// or https://")

    if parsed.path not in {"", "/"} or parsed.query or parsed.fragment:
        raise ValueError(
            "server URL must be the server root only, for example "
            "https://gdps.example.com"
        )

    # Only try the scheme the user actually asked for. Previously this
    # always tried "https" first regardless of the requested scheme,
    # which silently produced https:// compatibility URLs even when the
    # server has no SSL (e.g. free-tier shared hosting) -- the client
    # would then fail to connect ("Something went wrong") on those
    # specific fixed-length fields.
    schemes = (None,) if bare else (parsed.scheme,)

    for scheme in schemes:
        queue: deque[tuple[str, ...]] = deque([()])
        seen: set[tuple[str, ...]] = {()}

        while queue:
            parts = queue.popleft()

            if bare:
                prefix = "" if not parts else "/" + "/".join(parts)
                path = prefix + "/database"
            else:
                path = "" if not parts else "/" + "/".join(parts)

            if bare:
                candidate = f"{parsed.netloc}{path}"
            else:
                candidate = urlunsplit((scheme, parsed.netloc, path, "", ""))

            if byte_len(candidate) == desired_length:
                return candidate

            if len(parts) >= 6:
                continue

            for segment in SEGMENTS:
                if bare and segment == "database":
                    continue

                next_parts = parts + (segment,)
                if next_parts not in seen:
                    seen.add(next_parts)
                    queue.append(next_parts)

    raise ValueError(
        f"could not build a compatible {desired_length}-byte URL "
        "for this domain"
    )


def b64(value: str) -> bytes:
    return base64.b64encode(value.encode("ascii"))


def replace_exact(
    data: bytes,
    old: bytes,
    new: bytes,
    label: str,
) -> tuple[bytes, int]:
    if len(new) != len(old):
        # Some of these patterns describe different fixed-size binary
        # fields used across Geometry Dash client versions/builds; not
        # every pattern is expected to apply to every domain length.
        # Skip silently instead of failing the whole patch (matches the
        # tested behaviour of tools/client-patcher.ps1).
        return data, 0

    count = data.count(old)
    if count == 0:
        return data, 0

    return data.replace(old, new), count


def run_self_test() -> int:
    server = "https://gdps.example.com"

    for length in (34, 33, 28, 26):
        value = compatibility_url(server, length)
        assert byte_len(value) == length, (length, value)
        print(f"PASS URL {length}: {value}")

    try:
        compatibility_url(server, 29)
    except ValueError:
        print("PASS URL 29: correctly unavailable for this domain/scheme")
    else:
        raise AssertionError(
            "expected 29-byte HTTPS URL to be unavailable for this domain"
        )

    assert compatibility_url(server, 33).startswith("https://"), compatibility_url(server, 33)

    http_server = "http://gdps.example.com"
    http29 = compatibility_url(http_server, 29)
    assert byte_len(http29) == 29, http29
    assert http29.startswith("http://"), http29
    print(f"PASS URL 29 (http scheme): {http29}")

    http25 = compatibility_url(http_server, 25)
    assert byte_len(http25) == 25, http25
    assert http25.startswith("http://"), http25
    print(f"PASS URL 25 (http scheme): {http25}")

    root26 = compatibility_url(server, 26)
    assert byte_len(root26) == 26, root26
    assert root26.startswith("https://"), root26
    print(f"PASS root URL 26: {root26}")

    bare_server = "https://school-gdps.com"
    bare = compatibility_url(bare_server, 26, bare=True)
    assert byte_len(bare) == 26, bare
    assert bare.endswith("/database"), bare
    print(f"PASS bare URL 26: {bare}")

    print("MUCHOCORE_CLIENT_PATCHER_OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Patch a Geometry Dash client binary for a MuchoCore GDPS."
    )
    parser.add_argument(
        "--input",
        help="Original client binary. If omitted, you will be asked.",
    )
    parser.add_argument(
        "--output",
        help="Patched output. If omitted, it is created next to the input.",
    )
    parser.add_argument(
        "--server-url",
        help="Public MuchoCore server URL, for example https://gdps.example.com",
    )
    parser.add_argument(
        "--skip-http-base64",
        action="store_true",
        help="Do not attempt legacy HTTP Base64 replacements.",
    )
    parser.add_argument(
        "--self-test",
        action="store_true",
        help="Run patcher URL-generation tests and exit.",
    )
    args = parser.parse_args()

    if args.self_test:
        return run_self_test()

    input_value = args.input or input(
        "Path to your Geometry Dash client: "
    ).strip()
    server_value = args.server_url or input(
        "MuchoCore server URL (for example https://gdps.example.com): "
    ).strip()

    if not input_value or not server_value:
        print(
            "ERROR: input file and server URL are required.",
            file=sys.stderr,
        )
        return 2

    input_path = pathlib.Path(input_value)
    output_path = (
        pathlib.Path(args.output)
        if args.output
        else input_path.with_name(
            input_path.stem + "-MuchoCore" + input_path.suffix
        )
    )

    if not input_path.is_file():
        print(
            f"ERROR: input file does not exist: {input_path}",
            file=sys.stderr,
        )
        return 2

    if output_path.resolve() == input_path.resolve():
        print(
            "ERROR: output must be a different file from input.",
            file=sys.stderr,
        )
        return 2

    try:
        server_value = (
            server_value
            if "://" in server_value
            else "https://" + server_value
        )
        parsed = urlsplit(server_value.rstrip("/"))
        if parsed.scheme not in {"http", "https"}:
            raise ValueError("server URL must use http:// or https://")
        if parsed.path not in {"", "/"} or parsed.query or parsed.fragment:
            raise ValueError(
                "server URL must be the server root only, for example "
                "https://gdps.example.com"
            )

        urls: dict[int, str] = {}
        for length in (34, 33, 29, 28, 26, 25):
            try:
                urls[length] = compatibility_url(server_value, length)
            except ValueError:
                # Not every fixed-length field can be represented with
                # the requested scheme/domain combination (e.g. an
                # http:// server may have no segment combination that
                # produces a 33-byte URL). Skip that field instead of
                # falling back to a different scheme -- a silent
                # scheme fallback would produce an https:// URL that
                # the client cannot reach on SSL-less hosts.
                pass

        try:
            urls[26] = compatibility_url(server_value, 26, bare=True)
        except ValueError:
            pass
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    data = input_path.read_bytes()
    replacements: dict[str, int] = {}

    plain_pattern_specs = (
        (34, b"https://www.boomlings.com/database", "GD 2.2 HTTPS database URL", False),
        (33, b"http://www.boomlings.com/database", "Legacy HTTP database URL", False),
        (26, b"https://www.boomlings.com/", "GD HTTPS root URL", False),
        (25, b"http://www.boomlings.com/", "Legacy HTTP root URL", False),
        (26, b"www.boomlings.com/database", "Legacy bare database URL", True),
    )

    for length, old, label, bare in plain_pattern_specs:
        if not bare and length not in urls:
            continue

        try:
            new_value = (
                compatibility_url(server_value, length, bare=True)
                if bare
                else urls[length]
            )
        except ValueError:
            continue

        data, count = replace_exact(
            data,
            old,
            new_value.encode("ascii"),
            label,
        )
        replacements[label] = count

    if not args.skip_http_base64:
        b64_pattern_specs = (
            (33, "http://www.boomlings.com/database", "Base64 legacy database URL"),
            (34, "https://www.boomlings.com/database", "Base64 HTTPS database URL"),
            (25, "http://www.boomlings.com/", "Base64 legacy root URL"),
            (26, "https://www.boomlings.com/", "Base64 HTTPS root URL"),
        )

        b64_patterns = tuple(
            (b64(old), b64(urls[length]), label)
            for length, old, label in b64_pattern_specs
            if length in urls
        )

        for old, new, label in b64_patterns:
            data, count = replace_exact(data, old, new, label)
            replacements[label] = count

    if not any(
        replacements.get(label, 0) > 0
        for label in (
            "GD 2.2 HTTPS database URL",
            "Base64 HTTPS database URL",
            "GD HTTPS root URL",
            "Base64 HTTPS root URL",
            "Legacy HTTP database URL",
            "Base64 legacy database URL",
            "Legacy HTTP root URL",
            "Base64 legacy root URL",
            "Legacy bare database URL",
        )
    ):
        print(
            "ERROR: no supported Geometry Dash server URL was found "
            "in this client.",
            file=sys.stderr,
        )
        print(
            "The original file was not changed.",
            file=sys.stderr,
        )
        return 3

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_bytes(data)

    print()
    print("MuchoCore client patch complete.")
    print(f"Input:  {input_path}")
    print(f"Output: {output_path}")
    print(f"Server: {server_value.rstrip('/')}")
    print()
    for name, count in replacements.items():
        if count:
            print(f"{name}: {count} replacement(s)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
