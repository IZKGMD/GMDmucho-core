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
            "https://muchogdps.space"
        )

    schemes = (None,) if bare else ("https", "http")

    for scheme in schemes:
        queue: deque[tuple[str, ...]] = deque([()])
        seen: set[tuple[str, ...]] = {()}

        while queue:
            parts = queue.popleft()
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
        raise ValueError(
            f"{label}: replacement length mismatch "
            f"(original={len(old)}, replacement={len(new)})."
        )

    count = data.count(old)
    if count == 0:
        return data, 0

    return data.replace(old, new), count


def run_self_test() -> int:
    server = "https://muchogdps.space"

    for length in (34, 33, 29, 28):
        value = compatibility_url(server, length)
        assert byte_len(value) == length, (length, value)
        print(f"PASS URL {length}: {value}")

    assert compatibility_url(server, 33).startswith("https://"), compatibility_url(server, 33)

    bare = compatibility_url(server, 26, bare=True)
    assert byte_len(bare) == 26, bare
    assert bare == "muchogdps.space/a/database", bare
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
                "https://muchogdps.space"
            )

        urls = {
            34: compatibility_url(server_value, 34),
            33: compatibility_url(server_value, 33),
            29: compatibility_url(server_value, 29),
            28: compatibility_url(server_value, 28),
            26: compatibility_url(server_value, 26, bare=True),
        }
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    data = input_path.read_bytes()
    replacements: dict[str, int] = {}

    plain_patterns = (
        (
            b"https://www.boomlings.com/database",
            urls[34].encode("ascii"),
            "GD 2.2 HTTPS database URL",
        ),
        (
            b"http://www.boomlings.com/database",
            urls[33].encode("ascii"),
            "Legacy HTTP database URL",
        ),
        (
            b"https://www.boomlings.com/",
            urls[29].encode("ascii"),
            "GD HTTPS root URL",
        ),
        (
            b"http://www.boomlings.com/",
            urls[28].encode("ascii"),
            "Legacy HTTP root URL",
        ),
        (
            b"www.boomlings.com/database",
            urls[26].encode("ascii"),
            "Legacy bare database URL",
        ),
    )

    for old, new, label in plain_patterns:
        data, count = replace_exact(data, old, new, label)
        replacements[label] = count

    if not args.skip_http_base64:
        b64_patterns = (
            (
                b64("http://www.boomlings.com/database"),
                b64(urls[33]),
                "Base64 legacy database URL",
            ),
            (
                b64("https://www.boomlings.com/database"),
                b64(urls[34]),
                "Base64 HTTPS database URL",
            ),
            (
                b64("http://www.boomlings.com/"),
                b64(urls[28]),
                "Base64 legacy root URL",
            ),
            (
                b64("https://www.boomlings.com/"),
                b64(urls[29]),
                "Base64 HTTPS root URL",
            ),
        )

        for old, new, label in b64_patterns:
            data, count = replace_exact(data, old, new, label)
            replacements[label] = count

    if (
        replacements["GD 2.2 HTTPS database URL"] == 0
        and replacements["Base64 HTTPS database URL"] == 0
        and replacements["Legacy HTTP database URL"] == 0
        and replacements["Base64 legacy database URL"] == 0
        and replacements["Legacy bare database URL"] == 0
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
