"""Validate the universal APK against the payload manifest before signing."""
import hashlib
import json
import sys
import zipfile


def verify(path):
    with zipfile.ZipFile(path) as z:
        assert z.testzip() is None
        manifest = json.loads(z.read("assets/mucho/payload.json"))
        assert manifest["client_version"] == "1.2.0"
        assert manifest["geode_version"] == "5.10.1"
        for entry in manifest["files"]:
            data = z.read("assets/" + entry["asset"])
            assert hashlib.sha256(data).hexdigest() == entry["sha256"], entry["path"]
        for abi, digest in manifest["loaders"].items():
            assert hashlib.sha256(z.read(f"lib/{abi}/libgeode.so")).hexdigest() == digest
            assert z.read(f"lib/{abi}/liblauncherfix.so")[:4] == b"\x7fELF"
        assert set(manifest["loaders"]) == {"arm64-v8a", "armeabi-v7a"}
        assert not any("libcocos2dcpp.so" in name or "libfmod.so" in name for name in z.namelist())
        assert "assets/mucho/upstream-license.txt" in z.namelist()
        print(f"APK verified: {len(manifest['files'])} payload files, both ARM loaders; no game binary.")


if __name__ == "__main__":
    verify(sys.argv[1])
