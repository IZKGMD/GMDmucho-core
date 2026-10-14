"""Overlay a pinned, licensed Android launcher; embed a verified MuchoClient.

The game itself is never downloaded, bundled, patched or redistributed.
"""
from pathlib import Path
import argparse
import hashlib
import json
import shutil
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

UPSTREAM_SHA = "38bc97b7b6551acb1295dd241231ca22e2cd8995"
CLIENT_SHA = "bc6471de1920d36ca88b44fcdca5dbd3229214944ccae70d49be81edab4a699a"
GEODE_VERSION = "5.10.1"


def sha(data):
    return hashlib.sha256(data).hexdigest()


def change(root, name, old, new):
    path = root / name
    text = path.read_text()
    if old not in text:
        raise RuntimeError(f"Pinned upstream changed: {name}: {old[:70]}")
    path.write_text(text.replace(old, new))


def download(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "MuchoLauncher-build"}), timeout=120) as response:
        return response.read()


def prepare(root, client):
    source = Path(__file__).parent
    data = client.read_bytes()
    if sha(data) != CLIENT_SHA:
        raise RuntimeError("MuchoClient package SHA-256 mismatch")
    with zipfile.ZipFile(client) as z:
        metadata = json.loads(z.read("mod.json"))
        assert metadata["id"] == "izkgmd.muchoclient" and metadata["version"] == "v1.1.0"
        for platform in ["android32", "android64"]:
            assert f"izkgmd.muchoclient.{platform}.so" in z.namelist()

    for file in (source / "overlay").rglob("*"):
        if file.is_file():
            target = root / file.relative_to(source / "overlay")
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(file, target)

    package = "app/src/main/java/com/geode/launcher/"
    main = root / package / "MainActivity.kt"
    text = main.read_text()
    begin = text.index("class MainActivity :")
    end = text.index("data class LaunchStatusInfo", begin)
    main.write_text(text[:begin] + (source / "MainActivity.class.kt").read_text().replace("androidx.lifecycle.lifecycleScope", "lifecycleScope") + text[end:])
    change(root, package + "MainActivity.kt", "import android.content.Intent", "import android.content.Intent\nimport androidx.lifecycle.lifecycleScope")

    change(root, "app/build.gradle.kts", 'applicationId = "com.geode.launcher"', 'applicationId = "space.muchocore.launcher"')
    change(root, "app/build.gradle.kts", "versionCode = 29", "versionCode = 10001")
    change(root, "app/build.gradle.kts", 'versionName = "1.8.0"', 'versionName = "1.0.1"')
    change(root, "app/build.gradle.kts", 'resources {\n            excludes', 'jniLibs { useLegacyPackaging = true }\n        resources {\n            excludes')
    change(root, "app/build.gradle.kts", 'dependencies {', 'dependencies {\n    testImplementation("junit:junit:4.13.2")')

    change(root, package + "utils/LaunchUtils.kt", "val dir = context.externalMediaDirs.first()", 'val dir = File(context.filesDir, "mucho").apply { mkdirs() }')
    change(root, package + "utils/LaunchUtils.kt", "val currentProfile = ProfileManager.get(context).getCurrentProfile()", "val currentProfile: String? = null")
    change(root, package + "utils/GeodeUtils.kt", '"com.geode.launcher.user" == uri.authority', '"${BuildConfig.APPLICATION_ID}.user" == uri.authority')
    change(root, package + "utils/GeodeUtils.kt", "fun clearLaunchArguments() = setAdditionalLaunchArguments()", "fun clearLaunchArguments() = additionalLaunchArguments.clear()")
    change(root, package + "utils/GeodeUtils.kt", 'val userArgs = preferences.getString(PreferenceUtils.Key.LAUNCH_ARGUMENTS)', 'val userArgs: String? = null // MuchoClient must not be bypassed by safe-mode launch flags.')

    # Every package lookup (version, source APK, native libraries, diagnostics) must
    # use the same selected game. The isolated :game process initializes it again.
    change(root, package + "utils/GamePackageUtils.kt", "object GamePackageUtils {", '''object GamePackageUtils {
    @Volatile private var selectedPackage: String? = null
    fun usePackage(name: String) { selectedPackage = name }
    fun gamePackageName(): String = selectedPackage ?: Constants.PACKAGE_NAME
''')
    change(root, package + "utils/GamePackageUtils.kt", "getPackageInfo(Constants.PACKAGE_NAME,", "getPackageInfo(gamePackageName(),")
    change(root, package + "utils/GamePackageUtils.kt", "val game = packageManager.getPackageInfo(gamePackageName(), 0)\n\n        return if", '''val game = packageManager.getPackageInfo(gamePackageName(), 0)
        // Repackaged builds may change versionCode; Geode expects RobTop's code.
        if (com.geode.launcher.mucho.GameCompatibility.supportedVersion(game.versionName)) return 41L

        return if''')
    change(root, package + "utils/GamePackageUtils.kt", "!isGameInstalled(packageManager) || !identifyGameLegitimacy(packageManager)", "!isGameInstalled(packageManager)")

    manifest = root / "app/src/main/AndroidManifest.xml"
    text = manifest.read_text().replace('android:scheme="geode-launcher"', 'android:scheme="mucho-launcher"')
    text = text.replace('android:allowBackup="true"', 'android:allowBackup="false"')
    text = text.replace('android:name=".GeometryDashActivity"\n            android:configChanges', 'android:name=".GeometryDashActivity"\n            android:process=":game"\n            android:configChanges')
    # Explicit intents from other apps cannot bypass preparation.
    text = text.replace('android:exported="true"', 'android:exported="false"')
    text = text.replace('android:name=".MainActivity"\n            android:exported="false"', 'android:name=".MainActivity"\n            android:exported="true"')
    for permission in ["MANAGE_EXTERNAL_STORAGE", "REQUEST_INSTALL_PACKAGES", "RECORD_AUDIO"]:
        text = text.replace(f'    <uses-permission android:name="android.permission.{permission}" />\n', "")
    # The pinned release hardcodes provider authorities; main uses placeholders.
    # Use parsed attributes so changing an app ID cannot leave a broken provider.
    ET.register_namespace("android", "http://schemas.android.com/apk/res/android")
    ET.register_namespace("tools", "http://schemas.android.com/tools")
    tree = ET.fromstring(text)
    android = "{http://schemas.android.com/apk/res/android}"
    # Android 11+ hides renamed sideloaded apps unless we declare the picker query.
    queries = tree.find("queries")
    assert queries is not None
    query = ET.SubElement(queries, "intent")
    ET.SubElement(query, "action", {android + "name": "android.intent.action.MAIN"})
    ET.SubElement(query, "category", {android + "name": "android.intent.category.LAUNCHER"})
    providers = tree.findall("application/provider")
    for provider in providers:
        if provider.get(android + "name") == ".UserDirectoryProvider":
            provider.set(android + "exported", "true")
            provider.set(android + "authorities", "${applicationId}.user")
            assert provider.get(android + "permission") == "android.permission.MANAGE_DOCUMENTS"
        elif provider.get(android + "name") == "androidx.core.content.FileProvider":
            provider.set(android + "exported", "false")
            provider.set(android + "authorities", "${applicationId}.fileprovider")
    assert len(providers) == 2
    assert tree.find('application/activity[@' + android + 'name=".MainActivity"]').get(android + "exported") == "true"
    assert tree.find('application/activity[@' + android + 'name=".GeometryDashActivity"]').get(android + "exported") == "false"
    ET.indent(tree)
    manifest.write_text(ET.tostring(tree, encoding="unicode"))
    change(root, "app/src/main/res/values/strings.xml", '<string name="app_name" translatable="false">Geode</string>', '<string name="app_name" translatable="false">MuchoLauncher</string>')

    game = root / package / "GeometryDashActivity.kt"
    text = game.read_text()
    early_check = '''        // return back to main if Geometry Dash isn't found
        if (!GamePackageUtils.isGameInstalled(packageManager)) {
            returnToMain()
            return
        }
'''
    assert early_check in text
    text = text.replace(early_check, "")
    text = text.replace("getPackageInfo(Constants.PACKAGE_NAME,", "getPackageInfo(GamePackageUtils.gamePackageName(),")
    old_path = '''        val nativeDir = getNativeLibraryDirectory(packageInfo.applicationInfo!!)
        val libraryPath = if (nativeDir.endsWith('/')) "${nativeDir}lib$libraryName.so" else "$nativeDir/lib$libraryName.so"'''
    assert old_path in text
    text = text.replace(old_path, '''        val libraryPath = com.geode.launcher.mucho.MuchoGame.libraryPath(
            packageInfo, libraryName, LaunchUtils.applicationArchitecture)
            ?: throw UnsatisfiedLinkError("Missing lib$libraryName.so for ${LaunchUtils.applicationArchitecture}")''')
    text = text.replace('if (ule.message?.contains("not accessible for the namespace") != true) {',
        'if (!libraryPath.contains("!/") && ule.message?.contains("not accessible for the namespace") != true) {')
    start = text.index('    @SuppressLint("UnsafeDynamicallyLoadedCode")\n    private fun loadLibraryCopy(')
    end = text.index('    @SuppressLint("UnsafeDynamicallyLoadedCode")\n    private fun loadGeodeLibrary()', start)
    text = text[:start] + '''    @SuppressLint("UnsafeDynamicallyLoadedCode")
    private fun loadLibraryCopy(libraryName: String, libraryPath: String) {
        val copy = File(cacheDir, "lib$libraryName.so")
        com.geode.launcher.mucho.GameCompatibility.copyLibrary(libraryPath, copy)
        System.load(copy.path)
    }

''' + text[end:]
    text = text.replace("createVersionFile()\n            tryLoadGame()", "com.geode.launcher.mucho.MuchoIntegrity.prepare(this, false)\n            com.geode.launcher.mucho.MuchoIntegrity.requireGame(this)\n            createVersionFile()\n            tryLoadGame()")
    text = text.replace("        loadInternalMods()", "        // Pinned client and resources were prepared before launch.")
    text = text.replace("        startActivity(launchIntent)", "        startActivity(launchIntent)\n        android.os.Process.killProcess(android.os.Process.myPid()) // Only the isolated game process.")
    start = text.index('    @SuppressLint("UnsafeDynamicallyLoadedCode")\n    private fun loadGeodeLibrary()')
    end = text.index("\n    private fun", start + 110)
    # The next method is the safe boundary; preserve annotation if any by checking pinned source.
    text = text[:start] + '    private fun loadGeodeLibrary() {\n        System.loadLibrary("geode")\n    }\n' + text[end:]
    start = text.index("    private fun handleGeodeException(e: Throwable)")
    end = text.index("    private fun setupRedirection", start)
    text = text[:start] + '    private fun handleGeodeException(e: Throwable) { throw e }\n\n' + text[end:]
    game.write_text(text)

    # Loader settings are part of launch preparation; the client must remain enabled.
    # Runtime liveness is checked independently through its exported health function.
    change(root, package + "LauncherFix.kt", "    external fun performPatches()", "    external fun muchoClientReady(): Boolean\n\n    external fun performPatches()")
    native = root / "app/src/main/cpp/launcher-fix.cpp"
    native.write_text(native.read_text() + r'''

// Resolve only inside the actual loaded MuchoClient library, not a global symbol.
#include <dlfcn.h>
#include <link.h>
extern "C" JNIEXPORT jboolean JNICALL Java_com_geode_launcher_LauncherFix_muchoClientReady(JNIEnv*, jobject) {
    bool ready = false;
    dl_iterate_phdr([](dl_phdr_info* info, size_t, void* arg) {
        std::string path = info->dlpi_name;
        auto suffix = std::string("izkgmd.muchoclient.");
        if (path.find(suffix) == std::string::npos) return 0;
        void* handle = dlopen(path.c_str(), RTLD_NOW | RTLD_NOLOAD);
        if (!handle) return 0;
        auto health = reinterpret_cast<int(*)()>(dlsym(handle, "MuchoClientHealth_v1"));
        if (health && health() == 1) *static_cast<bool*>(arg) = true;
        dlclose(handle);
        return *static_cast<bool*>(arg) ? 1 : 0;
    }, &ready);
    return ready ? JNI_TRUE : JNI_FALSE;
}
''')
    change(root, "app/src/main/cpp/CMakeLists.txt", "PRIVATE log)", "PRIVATE log dl)")
    # CMake 3.22's IPO probe selects host ld.gold rather than Android's LLD.
    change(root, "app/src/main/cpp/CMakeLists.txt", "INTERPROCEDURAL_OPTIMIZATION ON", "INTERPROCEDURAL_OPTIMIZATION OFF")
    renderer = root / "app/src/main/java/org/cocos2dx/lib/Cocos2dxRenderer.kt"
    text = renderer.read_text().replace("    override fun onDrawFrame(gl: GL10?) {", """    private var muchoReady = false
    private val muchoStart = System.nanoTime()
    override fun onDrawFrame(gl: GL10?) {
        if (!muchoReady) {
            muchoReady = com.geode.launcher.LauncherFix.muchoClientReady()
            if (!muchoReady && System.nanoTime() - muchoStart > 60_000_000_000L) {
                handler.post {
                    val context = handler.context
                    val intent = android.content.Intent(context, com.geode.launcher.MainActivity::class.java)
                    intent.flags = android.content.Intent.FLAG_ACTIVITY_NEW_TASK or android.content.Intent.FLAG_ACTIVITY_CLEAR_TASK
                    intent.putExtra(com.geode.launcher.utils.LaunchUtils.LAUNCHER_KEY_RETURN_MESSAGE,
                        "MuchoClient не загрузился. Нажми Восстановить и проверь логи загрузчика.")
                    context.startActivity(intent)
                    android.os.Process.killProcess(android.os.Process.myPid())
                }
                return
            }
        }""")
    for name in ["handleActionDown", "handleActionUp", "handleActionCancel", "handleActionMove", "handleKeyDown", "handleInsertText", "handleDeleteBackward"]:
        start = text.index(f"    fun {name}(")
        end = text.index("{", start)
        text = text[:end + 1] + "\n        if (!muchoReady) return" + text[end + 1:]
    renderer.write_text(text)

    # MuchoCore icon: repo-native vector, no image-generation dependency.
    icon = '''<vector xmlns:android="http://schemas.android.com/apk/res/android" android:width="108dp" android:height="108dp" android:viewportWidth="108" android:viewportHeight="108">
<path android:fillColor="#090A10" android:pathData="M0,0h108v108h-108z"/>
<path android:fillColor="#B4A0FF" android:pathData="M24,76V32h11l19,23l19,-23h11v44h-12v-25l-18,21l-18,-21v25z"/>
</vector>'''
    for name in ["ic_launcher_foreground", "ic_launcher_monochrome", "ic_launcher_monochrome_shadow"]:
        (root / f"app/src/main/res/drawable-v24/{name}.xml").write_text(icon)
    (root / "app/src/main/res/drawable/mucho_icon.xml").write_text(icon)
    manifest_file = root / "app/src/main/AndroidManifest.xml"
    manifest_file.write_text(manifest_file.read_text().replace('android:icon="@mipmap/ic_launcher"', 'android:icon="@drawable/mucho_icon"'))

    resources = json.loads(download(f"https://api.geode-sdk.org/v1/loader/versions/{GEODE_VERSION}"))["payload"]["downloads"]
    manifest = {"client_version": "1.1.0", "geode_version": GEODE_VERSION, "files": [], "loaders": {}}
    assets = root / "app/src/main/assets"
    def add(path, asset, data):
        file = assets / asset
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_bytes(data)
        manifest["files"].append({"path": path, "asset": asset, "sha256": sha(data)})
    add("game/geode/mods/izkgmd.muchoclient.geode", "mucho/client.geode", data)
    for platform, abi in [("android32", "armeabi-v7a"), ("android64", "arm64-v8a"), ("resources", None)]:
        entry = resources[platform]
        archive = download(entry["url"])
        if not entry.get("hash") or sha(archive) != entry["hash"]:
            raise RuntimeError(f"Geode {platform} archive hash mismatch")
        import io
        with zipfile.ZipFile(io.BytesIO(archive)) as zip:
            if abi:
                binary = zip.read(f"Geode.{platform}.so")
                file = root / "app/src/main/jniLibs" / abi / "libgeode.so"
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(binary)
                manifest["loaders"][abi] = sha(binary)
            else:
                for name in zip.namelist():
                    if name.endswith("/"):
                        continue
                    assert not name.startswith("/") and ".." not in name.split("/") and "\\" not in name
                    add(f"game/geode/resources/geode.loader/{name}", f"mucho/resources/{name}", zip.read(name))
    (assets / "mucho/payload.json").write_text(json.dumps(manifest, indent=2))
    (assets / "mucho/upstream-license.txt").write_text((root / "LICENSE.txt").read_text())
    print(f"Prepared {len(manifest['files'])} pinned files and both native loaders.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--upstream", type=Path, required=True)
    parser.add_argument("--client", type=Path, required=True)
    args = parser.parse_args()
    prepare(args.upstream, args.client)
