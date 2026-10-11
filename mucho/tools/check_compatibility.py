"""Check source contracts, and optionally verify all named native PE exports."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument("checkout", type=Path)
parser.add_argument("--baseline-dll", type=Path)
parser.add_argument("--loader-dll", type=Path)
parser.add_argument("--out", type=Path)
args = parser.parse_args()
pin = json.loads((args.checkout / "mucho/upstream.json").read_text())
changed = subprocess.check_output(["git", "diff", "--name-only", pin["commit"]], cwd=args.checkout, text=True).splitlines()
allowed = {"CMakeLists.txt", "loader/src/loader/updater.cpp"}
unexpected = [p for p in changed if p not in allowed and not p.startswith("mucho/") and not p.startswith(".github/workflows/mucho") and p != "README_MUCHO.md"]
if unexpected:
    raise SystemExit("Native compatibility surface changed: " + repr(unexpected))
for name in ["VERSION", "loader/resources/mod.json.in", "cmake/Platform.cmake"]:
    original = subprocess.check_output(["git", "show", pin["commit"] + ":" + name], cwd=args.checkout)
    if (args.checkout / name).read_bytes() != original:
        raise SystemExit("Compatibility identity changed: " + name)
headers = list((args.checkout / "loader/include").rglob("*.hpp"))
report = {"geode": pin["tag"], "upstream_commit": pin["commit"], "gd": pin["gd"],
          "public_headers_unchanged": True, "native_identity_unchanged": True,
          "checked_headers": len(headers), "game_runtime_tested": False,
          "all_mods_compatible": "unverified", "python_platforms": ["Windows x64"]}
if bool(args.baseline_dll) != bool(args.loader_dll):
    raise SystemExit("Supply both DLLs")
if args.baseline_dll:
    import pefile
    def exports(path):
        image = pefile.PE(str(path))
        if image.FILE_HEADER.Machine != 0x8664:
            raise SystemExit("Expected AMD64 PE")
        return {s.name.decode("ascii") for s in image.DIRECTORY_ENTRY_EXPORT.symbols if s.name}
    original, candidate = exports(args.baseline_dll), exports(args.loader_dll)
    missing = sorted(original - candidate)
    report.update({"baseline_exports": len(original), "candidate_exports": len(candidate), "missing_exports": missing,
                   "loader_sha256": hashlib.sha256(args.loader_dll.read_bytes()).hexdigest()})
    if missing:
        if args.out: args.out.write_text(json.dumps(report, indent=2))
        raise SystemExit("Missing " + str(len(missing)) + " upstream native exports")
if args.out:
    args.out.write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2))
