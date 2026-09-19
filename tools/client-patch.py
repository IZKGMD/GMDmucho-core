#!/usr/bin/env python3
"""Patch a legally obtained Geometry Dash client binary for a MuchoCore GDPS."""

from __future__ import annotations

import argparse
import base64
import pathlib
import sys


PLAIN_DATABASE = b"https://www.boomlings.com/database"
PLAIN_ROOT_HTTP = b"http://www.boomlings.com/"
PLAIN_DATABASE_HTTP = b"http://www.boomlings.com/database"
PLAIN_GEOMETRYDASH_DATABASE = b"https://www.geometrydash.com/database"


def b64(value: str) -> bytes:
    return base64.b64encode(value.encode("ascii"))


def replace_exact(data: bytes, old: bytes, new: bytes, label: str) -> tuple[bytes, int]:
    if len(new) != len(old):
        raise ValueError(
            f"{label}: replacement length mismatch "
            f"(original={len(old)}, replacement={len(new)}). "
            "Use a server URL with the required byte length."
        )
    count = data.count(old)
    if count == 0:
        return data, 0
    return data.replace(old, new), count


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Patch a Geometry Dash client binary for a MuchoCore GDPS."
    )
    parser.add_argument("--input", required=True, help="Original client binary.")
    parser.add_argument("--output", required=True, help="Patched client output.")
    parser.add_argument(
        "--server-url",
        required=True,
        help="Public server root, for example https://gdps.example.com",
    )
    parser.add_argument(
        "--skip-http-base64",
        action="store_true",
        help="Do not patch the legacy HTTP Base64 URLs.",
    )
    args = parser.parse_args()

    server = args.server_url.rstrip("/")
    if not server.startswith(("http://", "https://")):
        print("ERROR: --server-url must start with http:// or https://", file=sys.stderr)
        return 2

    database_url = server + "/database"
    http_server = server.replace("https://", "http://", 1).replace(
        "http://http://", "http://"
    )
    http_database = http_server + "/database"

    input_path = pathlib.Path(args.input)
    output_path = pathlib.Path(args.output)

    if not input_path.is_file():
        print(f"ERROR: input file does not exist: {input_path}", file=sys.stderr)
        return 2
    if output_path.resolve() == input_path.resolve():
        print("ERROR: output must be a different file from input.", file=sys.stderr)
        return 2

    data = input_path.read_bytes()
    original_size = len(data)
    replacements: dict[str, int] = {}

    try:
        data, n = replace_exact(
            data, PLAIN_DATABASE, database_url.encode("ascii"), "HTTPS database URL"
        )
        replacements["HTTPS database URL"] = n

        if not args.skip_http_base64:
            replacements["Base64 HTTP /database"] = 0
            replacements["Base64 HTTP root"] = 0

            old = b64("http://www.boomlings.com/database")
            new = b64(http_database)
            data, n = replace_exact(data, old, new, "Base64 HTTP /database")
            replacements["Base64 HTTP /database"] = n

            old = b64("http://www.boomlings.com/")
            new = b64(http_server + "/")
            data, n = replace_exact(data, old, new, "Base64 HTTP root")
            replacements["Base64 HTTP root"] = n
    except (UnicodeEncodeError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    # Geometry Dash 2.2 clients may contain additional GeometryDash.com
    # multiplayer URLs. MuchoCore does not currently implement those endpoints,
    # so the patcher deliberately leaves them unchanged.
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_bytes(data)

    print("MuchoCore client patch complete.")
    print(f"Input:  {input_path}")
    print(f"Output: {output_path}")
    print(f"Size:   {original_size} bytes")
    print(f"Server: {server}")
    for name, count in replacements.items():
        print(f"{name}: {count} replacement(s)")

    if replacements["HTTPS database URL"] == 0:
        print(
            "WARNING: the main HTTPS database URL was not found. "
            "Make sure the client version contains the standard endpoint.",
            file=sys.stderr,
        )

    if sum(replacements.values()) == 0:
        print(
            "ERROR: no known MuchoCore endpoint strings were found. "
            "Do not distribute this binary until you verify the client.",
            file=sys.stderr,
        )
        output_path.unlink(missing_ok=True)
        return 3

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
