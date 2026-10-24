"""Build a harmless unsigned APK with the Android resource compiler, for tests only."""
import os
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

destination = Path(sys.argv[1]).resolve()
destination.parent.mkdir(parents=True, exist_ok=True)
with tempfile.TemporaryDirectory() as tmp:
    manifest = Path(tmp) / "AndroidManifest.xml"
    manifest.write_text('<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="com.robtopx.geometryjump"/>')
    subprocess.run([os.environ.get("AAPT", "aapt"), "package", "-f", "-M", str(manifest), "-F", str(destination)], check=True)
with zipfile.ZipFile(destination, "a") as archive:
    archive.writestr("lib/arm64-v8a/libcocos2dcpp.so", b"\x7fELF\0https://www.boomlings.com/database/getGJLevels21.php\0" + b"\0" * 4096)
    archive.writestr("assets/compressed.bin", os.urandom(2 * 1024 * 1024), compress_type=zipfile.ZIP_DEFLATED)
    archive.writestr("assets/large.bin", b"preserve-these-bytes" * 1200000, compress_type=zipfile.ZIP_DEFLATED)
    archive.writestr("META-INF/OLD.SF", "old invalid signature")
    archive.writestr("META-INF/OLD.RSA", "old invalid signature")
print(destination)
