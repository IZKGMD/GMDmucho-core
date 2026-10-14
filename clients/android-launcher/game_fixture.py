"""Build a tiny fake app to exercise Android package visibility and game selection.

It contains synthetic ELF headers, not Geometry Dash. Never used to test gameplay.
"""
from pathlib import Path
import os
import subprocess
import zipfile

out = Path("android-ui")
out.mkdir(exist_ok=True)
sdk = Path(os.environ["ANDROID_HOME"])
tools = sdk / "build-tools/36.0.0"
manifest = out / "game-fixture.xml"
manifest.write_text('''<manifest xmlns:android="http://schemas.android.com/apk/res/android"
 package="space.muchocore.fixture.gd" android:versionCode="900" android:versionName="2.2081">
 <uses-sdk android:minSdkVersion="23" android:targetSdkVersion="35"/>
 <application android:label="GD Fixture" android:extractNativeLibs="true">
  <activity android:name=".MainActivity" android:exported="true">
   <intent-filter><action android:name="android.intent.action.MAIN"/>
    <category android:name="android.intent.category.LAUNCHER"/></intent-filter>
  </activity>
 </application></manifest>''')
unsigned = out / "game-fixture-unsigned.apk"
subprocess.run([str(tools / "aapt2"), "link", "--manifest", str(manifest), "-I",
 str(sdk / "platforms/android-36/android.jar"), "-o", str(unsigned)], check=True)
activity = out / "MainActivity.java"
activity.write_text('''package space.muchocore.fixture.gd;
public class MainActivity extends android.app.Activity {}''')
classes = out / "fixture-classes"
classes.mkdir(exist_ok=True)
dex = out / "fixture-dex"
dex.mkdir(exist_ok=True)
platform = sdk / "platforms/android-36/android.jar"
subprocess.run(["javac", "-source", "8", "-target", "8", "-classpath", str(platform),
 "-d", str(classes), str(activity)], check=True)
subprocess.run([str(tools / "d8"), "--lib", str(platform), "--min-api", "23", "--output", str(dex),
 str(classes / "space/muchocore/fixture/gd/MainActivity.class")], check=True)
source = out / "empty.c"
source.write_text("int fixture(void) { return 0; }\n")
native = out / "empty.so"
subprocess.run(["cc", "-shared", "-fPIC", str(source), "-o", str(native)], check=True)
header = bytearray(20)
header[:6] = b"\x7fELF\x02\x01"
header[18] = 183
with zipfile.ZipFile(unsigned, "a", compression=zipfile.ZIP_DEFLATED) as zip:
 zip.write(dex / "classes.dex", "classes.dex")
 for name in ["libfmod.so", "libcocos2dcpp.so"]:
  zip.writestr("lib/arm64-v8a/" + name, header)
  zip.writestr("lib/x86_64/" + name, native.read_bytes())
aligned = out / "game-fixture-aligned.apk"
subprocess.run([str(tools / "zipalign"), "-f", "4", str(unsigned), str(aligned)], check=True)
key = out / "fixture-only.p12"
subprocess.run(["keytool", "-genkeypair", "-keystore", str(key), "-storetype", "PKCS12",
 "-storepass", "android", "-keypass", "android", "-alias", "fixture", "-keyalg", "RSA",
 "-keysize", "2048", "-validity", "7", "-dname", "CN=CI fixture only"], check=True)
subprocess.run([str(tools / "apksigner"), "sign", "--ks", str(key),
 "--ks-pass", "pass:android", "--key-pass", "pass:android", "--out", str(out / "game-fixture.apk"), str(aligned)], check=True)
