#!/usr/bin/env python3
"""Verify the public 1.1.1 PHP8 release is actually installable and safe.

Static mode runs in Validate before publication. Archive mode inspects actual
ZIP members built by the release job, not a source-tree approximation.
"""
from __future__ import annotations

import argparse
import hashlib
from pathlib import Path
import re
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
VERSION = "1.1.1"
PREFIX = "muchocore/"
NEEDED_SHARED = {
    ".htaccess", "VERSION", "README.md", "START_HERE.md",
    "composer.json", "composer.lock", "vendor/autoload.php",
    "public/install.php", "public/shared-install.php",
    "public/index.php", "config/", "src/", "database/",
    "docs/SHARED_HOSTING.md", "docs/CLIENT_SETUP.md",
}
NEEDED_SOURCE = {
    "VERSION", "install.sh", "install-remote.sh", "update.sh",
    "docker-compose.yml", "composer.json", "composer.lock",
    "public/install.php", "public/shared-install.php",
    "tools/release/build-shared-hosting.sh",
}
BAD_SEGMENTS = {".git", ".secrets", "storage", "__pycache__"}
BAD_FILES = {".env", "cloudsave.key", "php7-local.php",
             "php7-installed.lock", "php7-mode.on"}


def require(ok: bool, reason: str) -> None:
    if not ok:
        raise AssertionError("MuchoCore 1.1.1 package: " + reason)


def check_static() -> None:
    require((ROOT / "VERSION").read_text().strip() == VERSION,
            "release source VERSION must equal 1.1.1")
    workflow = (ROOT / ".github/workflows/release-php8-v111.yml").read_text()
    require("PHP 8 HTTP acceptance" in workflow
            and "workflow_run" in workflow
            and "github.event.workflow_run.event == 'push'" in workflow
            and "github.event.workflow_run.conclusion == 'success'" in workflow,
            "publication must depend on successful PHP8 HTTP push workflow")
    require("validate.yml" in workflow and "head_sha=" in workflow
            and "sha=" in workflow and "CURRENT_MAIN" in workflow,
            "same-sha Validate and immutable main gate required")
    require("php8-v1.1.1.ready" in workflow
            and ".release/v1.1.1.ready" not in workflow,
            "PHP8 release needs independent, explicit one-time marker")
    require("build-shared-hosting.sh" in workflow
            and "composer install --no-dev" in workflow
            and "v111-package-check.py --shared" in workflow,
            "published ZIP must be self-contained and verified")
    require("sha256sum" in workflow and "--draft" in workflow
            and "gh release upload" in workflow
            and "gh release edit" in workflow,
            "draft until both archives and checksums are uploaded")
    require("PHP7-shared-hosting.zip" not in workflow
            and "build-php7-native.sh" not in workflow,
            "must NOT publish an unverified PHP7 native asset")
    require((ROOT / ".release/php8-v1.1.1.ready").is_file(),
            "explicit 1.1.1 publication marker missing")
    notes = (ROOT / "docs/RELEASE_NOTES_1.1.1.md").read_text()
    require("MuchoCore-v1.1.1-shared-hosting.zip" in notes
            and "160" in notes and "PHP 7" in notes
            and "install.php" in notes and "Cloud Save" in notes,
            "real installation/known-limit notes absent")
    print("MUCHOCORE_V111_RELEASE_PIPELINE_CONTRACT_OK")


def check_archive(path: Path, prefix: str, required: set[str]) -> str:
    require(path.is_file() and path.stat().st_size > 100_000,
            "ZIP missing or implausibly small: " + str(path))
    with ZipFile(path) as zf:
        names = zf.namelist()
        require(len(names) == len(set(names)), "duplicate ZIP members")
        require(zf.testzip() is None, "corrupt ZIP member")
        entries = set()
        for item in names:
            require(item.startswith(prefix), "unexpected ZIP root: " + item)
            relative = item[len(prefix):]
            if not relative:
                continue
            relative = relative.rstrip("/")
            parts = relative.split("/")
            require(all(part not in ("", ".", "..") for part in parts),
                    "unsafe archive path: " + item)
            require(not any(part in BAD_SEGMENTS for part in parts),
                    "unsafe runtime directory: " + item)
            require(not any(part in BAD_FILES for part in parts),
                    "operator secret/state in archive: " + item)
            require(not relative.startswith(".release/"),
                    "release control metadata in archive")
            entries.add(relative)
        for item in required:
            suffix = item.rstrip("/")
            require(suffix in entries
                    or any(name.startswith(suffix + "/") for name in entries),
                    "missing required file or directory: " + item)
        require(zf.read(prefix + "VERSION").decode().strip() == VERSION,
                "archive does not carry exact 1.1.1 version")
        if prefix == PREFIX:
            entry = zf.read(prefix + "public/install.php")
            require(b"PHP_VERSION_ID >= 80200" in entry
                    and b"shared-install.php" in entry,
                    "installation preflight not present in distribution")
            require(b"Composer" in zf.read(prefix + "README.md")
                    or b"shared-hosting" in zf.read(prefix + "README.md"),
                    "archive documentation missing")
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--static", action="store_true")
    p.add_argument("--shared", type=Path)
    p.add_argument("--source", type=Path)
    args = p.parse_args()
    require(args.static or args.shared or args.source,
            "provide a check mode")
    if args.static:
        check_static()
    if args.shared:
        require(args.shared.name == f"MuchoCore-v{VERSION}-shared-hosting.zip",
                "incorrect shared archive filename")
        digest = check_archive(args.shared, PREFIX, NEEDED_SHARED)
        print("MUCHOCORE_V111_INSTALLABLE_PHP8_ZIP_OK sha256=" + digest)
    if args.source:
        require(args.source.name == f"MuchoCore-v{VERSION}-source.zip",
                "incorrect source archive filename")
        digest = check_archive(args.source, f"MuchoCore-v{VERSION}/",
                               NEEDED_SOURCE)
        print("MUCHOCORE_V111_SOURCE_ZIP_OK sha256=" + digest)


if __name__ == "__main__":
    main()
