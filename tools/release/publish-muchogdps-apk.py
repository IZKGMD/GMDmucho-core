#!/usr/bin/env python3
"""Publish the verified MuchoGDPS APK from a Google Drive folder on a VPS.

The folder must contain MuchoGDPS-Fixed.apk.zip.000 through .008 and be
temporarily shared as "Anyone with the link: Viewer". Downloading and joining
takes place on the VPS: Android users only download ONE finished APK.
"""
from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import shutil
import sys
import tempfile
import zipfile

SHA256 = "e7c15e4d3b20e504bba14ee5fa779ff97b9320f4a178d6a17094101517f74883"
APK_SIZE = 179371788
APK_NAME = "GeometryDash-2.2081-MuchoGDPS-Fixed.apk"
CHUNK_PREFIX = "MuchoGDPS-Fixed.apk.zip"


def publish_parts(source: Path, destination: Path) -> Path:
    parts: list[Path] = []
    for index in range(9):
        filename = f"{CHUNK_PREFIX}.{index:03d}"
        found = list(source.rglob(filename))
        if len(found) != 1:
            raise RuntimeError(f"Missing {filename}. Check folder access and download.")
        part = found[0]
        expected = 20971520 if index < 8 else 11599856
        if part.stat().st_size != expected:
            raise RuntimeError(f"Invalid length for {filename}. Download was incomplete.")
        parts.append(part)

    destination.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".apk-publish-", dir=destination) as temporary:
        temp = Path(temporary)
        archive = temp / "combined.zip"
        with archive.open("wb") as out:
            for part in parts:
                with part.open("rb") as inp:
                    shutil.copyfileobj(inp, out, 4 * 1024 * 1024)

        pending = temp / APK_NAME
        with zipfile.ZipFile(archive) as z:
            if z.namelist() != [APK_NAME]:
                raise RuntimeError("Unexpected ZIP contents; refusing to publish.")
            if z.getinfo(APK_NAME).file_size != APK_SIZE:
                raise RuntimeError("ZIP contents size mismatch.")
            with z.open(APK_NAME) as inp, pending.open("wb") as out:
                shutil.copyfileobj(inp, out, 4 * 1024 * 1024)

        actual = hashlib.sha256()
        with pending.open("rb") as inp:
            for block in iter(lambda: inp.read(4 * 1024 * 1024), b""):
                actual.update(block)
        if pending.stat().st_size != APK_SIZE or actual.hexdigest() != SHA256:
            raise RuntimeError("APK SHA256 mismatch; refusing to publish.")
        print(f"[MuchoGDPS] VERIFIED SHA256={actual.hexdigest()}")
        pending.chmod(0o644)
        target = destination / APK_NAME
        os.replace(pending, target)
    return target


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--folder", help="Google Drive folder URL shared with anyone with the link")
    parser.add_argument("--local-parts", type=Path, help="Use existing parts (for offline testing)")
    parser.add_argument("--release-dir", type=Path,
                        default=Path("/opt/mucho-core/releases/android"))
    options = parser.parse_args()
    if not options.folder and not options.local_parts:
        parser.error("Specify --folder or --local-parts")

    try:
        if options.local_parts:
            target = publish_parts(options.local_parts, options.release_dir)
        else:
            try:
                import gdown
            except ImportError:
                raise RuntimeError("Install gdown in a Python virtualenv before running.")
            with tempfile.TemporaryDirectory(prefix="muchogdps-gdrive-") as temporary:
                print("[MuchoGDPS] Downloading APK parts directly to VPS...", flush=True)
                files = gdown.download_folder(url=options.folder, output=temporary,
                                              quiet=False)
                if not files:
                    raise RuntimeError(
                        "Google Drive denied access. Set folder to Anyone with the link: Viewer."
                    )
                target = publish_parts(Path(temporary), options.release_dir)
        print(f"[MuchoGDPS] PUBLISHED: {target}")
        print(f"[MuchoGDPS] URL: https://muchogdps.space/downloads/android/{APK_NAME}")
        return 0
    except (OSError, RuntimeError, zipfile.BadZipFile) as error:
        print(f"[MuchoGDPS] ERROR: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
