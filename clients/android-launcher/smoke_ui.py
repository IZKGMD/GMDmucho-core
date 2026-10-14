"""Exercise real launcher screens through Android's UI hierarchy in CI."""
from pathlib import Path
import re
import subprocess
import time
import xml.etree.ElementTree as ET

OUT = Path("android-ui")
OUT.mkdir(exist_ok=True)


def adb(*args):
    return subprocess.check_output(["adb", *args], text=True)


def tree():
    adb("shell", "uiautomator", "dump", "/sdcard/window.xml")
    return ET.fromstring(adb("shell", "cat", "/sdcard/window.xml"))


def find(text):
    for _ in range(12):
        root = tree()
        for node in root.iter("node"):
            value = node.get("text", "")
            if " ".join(value.split()) == " ".join(text.split()) or value.endswith(", " + text) or value.endswith("\n" + text):
                return node
        time.sleep(1)
    (OUT / "failure.xml").write_text(ET.tostring(root, encoding="unicode"))
    screenshot("failure")
    (OUT / "logcat.txt").write_text(adb("logcat", "-d", "-v", "brief"))
    print("Observed UI:", [(node.get("text"), node.get("content-desc")) for node in root.iter("node")])
    raise AssertionError(f"UI text not found: {text}")


def tap(text):
    node = find(text)
    x1, y1, x2, y2 = map(int, re.findall(r"\d+", node.get("bounds")))
    adb("shell", "input", "tap", str((x1 + x2) // 2), str((y1 + y2) // 2))


def screenshot(name):
    (OUT / f"{name}.png").write_bytes(subprocess.check_output(["adb", "exec-out", "screencap", "-p"]))
    adb("pull", "/sdcard/window.xml", str(OUT / f"{name}.xml"))


adb("logcat", "-c")
print(adb("shell", "am", "start", "-W", "-n", "space.muchocore.launcher/com.geode.launcher.MainActivity"))
find("Играй. Создавай.\nБудь Mucho.")
screenshot("home")
for tab, expected, filename in [
    ("Клиент", "Твоя библиотека", "client"),
    ("Mucho", "Создавай больше", "community"),
    ("Настройки", "Под тебя", "settings"),
]:
    tap(tab)
    find(expected)
    screenshot(filename)
tap("○  60 FPS")
find("●  60 FPS")
tap("Старт")
find("Играй. Создавай.\nБудь Mucho.")
tap("Выбрать игру")
find("Выбери установленную GD")
tap("GD Fixture 2.2081")
find("GD Fixture 2.2081 • space.muchocore.fixture.gd")
preferences = adb("shell", "run-as", "space.muchocore.launcher", "cat", "shared_prefs/mucho-game.xml")
assert 'name="selected-package"' in preferences and "space.muchocore.fixture.gd" in preferences
screenshot("selected-game")
adb("shell", "am", "force-stop", "space.muchocore.launcher")
adb("shell", "am", "start", "-W", "-n", "space.muchocore.launcher/com.geode.launcher.MainActivity")
find("GD Fixture 2.2081 • space.muchocore.fixture.gd")
screenshot("restored-selection")
adb("uninstall", "space.muchocore.fixture.gd")
adb("shell", "am", "force-stop", "space.muchocore.launcher")
adb("shell", "am", "start", "-W", "-n", "space.muchocore.launcher/com.geode.launcher.MainActivity")
find("Выбранная игра удалена: space.muchocore.fixture.gd. Нажми «Выбрать игру».")
tap("Выбрать игру")
find("Не нашёл GD с библиотеками игры. Установи GD 2.2081 и повтори поиск.")
tap("Закрыть")
logs = adb("logcat", "-d", "-v", "brief")
(OUT / "logcat.txt").write_text(logs)
assert "FATAL EXCEPTION" not in logs
print("UI smoke passed: four screens, FPS, renamed sideloaded game picker, persisted selection and uninstall recovery; no crash. Fixture does not contain GD.")
