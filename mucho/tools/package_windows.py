import argparse
import hashlib
import json
from pathlib import Path
import shutil
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument("checkout", type=Path)
parser.add_argument("python", type=Path)
parser.add_argument("output", type=Path)
args = parser.parse_args()
root = args.checkout
dist = args.output / "MuchoLoader-v0.1.0-Windows"
dist.mkdir(parents=True, exist_ok=False)
payload = dist / "payload"
payload.mkdir()
binpath = root / "bin/nightly"
for name in ["Geode.dll", "XInput1_4.dll", "GeodeUpdater.exe"]:
    candidates = [p for p in binpath.iterdir() if p.name.lower() == name.lower()]
    if len(candidates) != 1: raise SystemExit("Missing native binary: " + name)
    shutil.copy2(candidates[0], payload / name)
resource_target = payload / "geode/resources/geode.loader"
shutil.copytree(binpath / "resources", resource_target, ignore=shutil.ignore_patterns(".geode_cache"))
runtime = payload / "geode/mucholoader"
shutil.copytree(root / "mucho/sdk", runtime / "sdk", ignore=shutil.ignore_patterns("__pycache__", "*.pyc"))
shutil.copytree(root / "mucho/examples", runtime / "plugins", ignore=shutil.ignore_patterns("__pycache__", "*.pyc"))
shutil.copytree(args.python, runtime / "python")
# Windows resolves loader imports before entry, so Python's DLLs must be alongside GD.
for dll in (runtime / "python").glob("*.dll"):
    shutil.copy2(dll, payload / dll.name)
for name in ["LICENSE.txt", "EULA"]:
    shutil.copy2(root / name, dist / name)
shutil.copy2(root / "mucho/install/install.ps1", dist / "install.ps1")
shutil.copy2(root / "mucho/install/restore.ps1", dist / "restore.ps1")
shutil.copy2(root / "mucho/README.md", dist / "README.md")
shutil.copy2(root / "mucho/docs/INSTALL-RU.md", dist / "INSTALL-RU.md")
if (root / "compatibility.json").exists(): shutil.copy2(root / "compatibility.json", dist / "compatibility.json")
manifest = [{"path": p.relative_to(payload).as_posix(), "sha256": hashlib.sha256(p.read_bytes()).hexdigest()} for p in sorted(payload.rglob("*")) if p.is_file()]
(dist / "package.json").write_text(json.dumps({"version": "0.1.0", "files": manifest}, indent=2))
archive = args.output / (dist.name + ".zip")
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as output:
    for path in sorted(dist.rglob("*")):
        if path.is_file(): output.write(path, path.relative_to(args.output))
print(archive)
