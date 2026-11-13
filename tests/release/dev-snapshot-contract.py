#!/usr/bin/env python3
"""Guard development archives against misleading labels and secret leakage."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
workflow = (ROOT / ".github/workflows/build-vendor-archive.yml").read_text()

checks = {
    "immutable commit identity":
        'SHORT_SHA="${GITHUB_SHA:0:12}"' in workflow
        and "dev-${SHORT_SHA}.zip" in workflow,
    "tracked source plus production dependencies":
        "git archive --format=tar" in workflow
        and 'cp -a vendor "$stage/$root/vendor"' in workflow,
    "private paths excluded":
        all(
            "':(exclude)" + path + "'" in workflow
            for path in (
                ".release/**",
                ".secrets/**",
                "storage/**",
                "config/cloudsave.key",
                ".env",
            )
        ),
    "archive contents inspected":
        'unzip -t "$ARCHIVE"' in workflow
        and "Snapshot contains sensitive or runtime-only paths" in workflow,
    "checksum generated and verified":
        'sha256sum "$ARCHIVE" vendor-muchocore-php83.zip > checksums.sha256'
        in workflow
        and "sha256sum --check checksums.sha256" in workflow,
    "development label":
        'name: MuchoCore-PHP8-dev-${{ github.sha }}' in workflow,
    "not masquerading as a stable release":
        "gh release" not in workflow
        and "action-gh-release" not in workflow,
}

for name, ok in checks.items():
    if not ok:
        raise RuntimeError("PHP 8 development archive contract failed: " + name)
print("MUCHOCORE_PHP8_DEV_SNAPSHOT_CONTRACT_OK")
