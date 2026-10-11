"""Reconstruct the standalone fork from a pinned upstream checkout and this overlay."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument("checkout", type=Path)
args = parser.parse_args()
overlay = Path(__file__).resolve().parents[1]
pin = json.loads((overlay / "upstream.json").read_text())
actual = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=args.checkout, text=True).strip()
if actual != pin["commit"]:
    raise SystemExit("Unexpected Geode commit; refusing to apply overlay")
shutil.copytree(overlay, args.checkout / "mucho", dirs_exist_ok=True, ignore=shutil.ignore_patterns("__pycache__"))
readme = args.checkout / "README.md"
credit = args.checkout / "README_GEODE.md"
if not credit.exists():
    shutil.copy2(readme, credit)
shutil.copy2(overlay / "README.md", readme)
root = args.checkout / "CMakeLists.txt"
if "include(mucho/Integrate.cmake)" not in root.read_text():
    root.write_text(root.read_text() + '\n# MuchoLoader standalone integration\nif(PROJECT_IS_TOP_LEVEL AND TARGET geode-loader)\n  include(mucho/Integrate.cmake)\nendif()\n')
path = args.checkout / "loader/src/loader/updater.cpp"
text = path.read_text()
for signature, guard in [
    ('void updater::downloadLoaderUpdate(std::string url, std::string hash) {', '    LoaderUpdateEvent().send(UpdateFailed("Use the MuchoLoader installer to update this fork"));\n    return;'),
    ('Result<> updater::installLoaderUpdate(utils::web::WebResponse response, std::string_view expectedHash) {', '    return Err("Upstream loader updates are disabled in MuchoLoader");'),
    ('void updater::checkForLoaderUpdates() {', '    log::info("MuchoLoader keeps its pinned native compatibility base; upstream loader replacement is disabled");\n    return;'),
]:
    marker = signature + '\n#ifdef MUCHO_LOADER'
    if marker in text:
        continue
    if text.count(signature) != 1:
        raise SystemExit("Upstream integration point changed")
    text = text.replace(signature, signature + '\n#ifdef MUCHO_LOADER\n' + guard + '\n#endif\n')
path.write_text(text)
print("Prepared standalone MuchoLoader on " + pin["tag"])
