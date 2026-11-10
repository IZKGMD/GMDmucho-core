"""Automatic GD 2.209 client preparation after install or on an existing 5v.pl site.

The operator supplies their own EXE/APK. The archive never contains game binaries.
Public uploads require explicit confirmation of redistribution permission.
"""
from __future__ import annotations

import getpass
from pathlib import Path
import re
import secrets
import sys

from mucho5v import FtpSite, HttpsSite, InstallError, site_origin, valid_ftp_host
from muchoclients5v import (
    patch_windows, patch_android, publish_windows, publish_android,
    publish_index, read_source, save_links, cleanup_published_job,
)
from INSTALL_5V import csrf_from

HERE = Path(__file__).resolve().parent
OUTDIR = HERE / "patched_clients"


def ask(message: str, default: str = "") -> str:
    value = input(message + (" [" + default + "]" if default else "") + ": ").strip()
    return value or default


def find_original(extension: str, *, ask_for_missing: bool = False) -> Path | None:
    """Use a provided local game file without asking for a path in quick mode."""
    allowed = [HERE, HERE / "original_clients"]
    if extension == ".exe" and sys.platform == "win32":
        # Standard Steam installations only. Do not scan the user's disk.
        import os
        for env_key in ("PROGRAMFILES(X86)", "PROGRAMFILES"):
            base = os.environ.get(env_key)
            if base:
                allowed.append(Path(base) / "Steam" / "steamapps" /
                               "common" / "Geometry Dash")
    matches = []
    for folder in allowed:
        if folder.is_dir():
            matches.extend(file for file in folder.glob("GeometryDash*")
                           if file.is_file() and file.suffix.lower() == extension
                           and "MuchoCore" not in file.name)
    matches = sorted(set(matches))
    if len(matches) == 1:
        print("Исходный", extension, "найден:", matches[0])
        return matches[0]
    if len(matches) > 1:
        print("Найдено несколько исходников", extension)
        for candidate in matches:
            print("  ", candidate)
    if not ask_for_missing and not matches:
        print("Исходный", extension, "не найден — пропускаю без вопросов.")
        return None
    path = ask("Путь к оригинальному " + extension + " (Enter = пропустить)")
    if not path:
        return None
    return read_source(path.strip('"'), extension)


def ask_clients(*, ask_for_missing: bool = False) -> tuple[Path | None, Path | None, bool]:
    print("\nКлиенты GD 2.209: поиск исходников на компьютере...")
    exe = find_original(".exe", ask_for_missing=ask_for_missing)
    apk = find_original(".apk", ask_for_missing=ask_for_missing)
    if not exe and not apk:
        print("Клиентов нет; ядро и админка готовы. Позже открой START_CLIENTS_WINDOWS.cmd.")
        return None, None, False
    print("\nДля размещения полных EXE/APK на публичном сайте необходимо")
    print("разрешение правообладателя; личная покупка игры его не даёт.")
    permission = ask("Есть право на публичное распространение? [да/Нет]", "Нет")
    return exe, apk, permission.casefold() in ("да", "y", "yes")



def publish_interactive(site: HttpsSite, ftp: FtpSite, *, advanced: bool = False) -> dict:
    exe, apk, permission = ask_clients(ask_for_missing=advanced)
    if not exe and not apk:
        return {}
    links = {}
    if not permission:
        print("Нет подтверждения прав: публичная загрузка отключена.")
        if exe:
            try:
                local, report = patch_windows(exe, site.origin, OUTDIR)
                print("EXE подготовлен локально:", local)
            except (InstallError, OSError) as exc:
                print("Не удалось подготовить EXE:", exc)
        if apk:
            print("Для личного использования APK можно патчить в защищённой админке:")
            print(site.origin + "/mc/admin.php?page=clientpatcher")
        return {}

    if exe:
        try:
            output, report = patch_windows(exe, site.origin, OUTDIR)
            print("EXE успешно пропатчен, SHA-256:", report["sha256"])
            url = publish_windows(ftp, site, output, report)
            print("Windows клиент:", url)
            links["exe"] = {"url": url, "sha256": report["sha256"],
                            "size": report["size"]}
        except (InstallError, OSError) as exc:
            print("EXE: не опубликован:", str(exc))
            print("Локальная админка/база при этом не затрагиваются.")

    if apk:
        try:
            apk = read_source(apk, ".apk")
            is_expected_2209 = apk.stat().st_size == 174_568_364
            default = "ДА" if is_expected_2209 else "НЕТ"
            alongside = (ask("Установить APK рядом с оригиналом? (ДА/НЕТ)", default).upper()
                         if advanced else default)
            if alongside not in ("ДА", "НЕТ"):
                raise InstallError("Нужно выбрать ДА или НЕТ.")
            if alongside == "НЕТ":
                print("Внимание: такой APK может конфликтовать с установленной оригинальной игрой.")
            report = patch_android(site, apk, site.origin,
                                   alongside=alongside == "ДА")
            print("APK подписан v1/v2, сертификат:", report.get("certificate_sha256"))
            url = publish_android(ftp, site, report, OUTDIR)
            print("Android клиент:", url)
            links["apk"] = {"url": url, "sha256": report["output_sha256"],
                            "size": report["output_size"],
                            "certificate_sha256": report.get("certificate_sha256")}
            try:
                if cleanup_published_job(site, report["job_id"]):
                    print("Временные файлы APK удалены, ключ подписи сохранён.")
            except InstallError:
                print("Временная задача патчера осталась. Позже очисти её в админке.")
            print("СОХРАНИ mc/config/php7-apk-signer.json в безопасной резервной копии!")
        except (InstallError, OSError) as exc:
            print("APK: не опубликован:", str(exc))
            print("Открой Client Patcher в админке, чтобы увидеть состояние задания.")

    if links:
        try:
            page = publish_index(ftp, site, links)
            print("Страница скачивания:", page)
        except (InstallError, OSError) as exc:
            print("Страница со ссылками не опубликована:", str(exc))
        report_file = save_links(OUTDIR, site.origin, links)
        print("Ссылки сохранены локально:", report_file)
    else:
        print("Ни один клиент не опубликован. Ядро и админка остаются установленными.")
    return links


def login_owner(site: HttpsSite, username: str, password: str, otp: str = ""):
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,32}", username):
        raise InstallError("Неверное имя владельца.")
    code, page = site.request("/mc/admin.php")
    if code != 200:
        raise InstallError("Админка не отвечает по HTTPS.")
    csrf = csrf_from(page, "login")
    code, page = site.request("/mc/admin.php", {
        "csrf": csrf, "action": "login", "userName": username,
        "password": password, "otp": otp,
    })
    if code != 200:
        raise InstallError("Не удалось войти в админку (HTTP %s)." % code)
    code, page = site.request("/mc/admin.php?page=clientpatcher")
    if code != 200 or b'id="sharedPatcher"' not in page:
        raise InstallError("Вход владельца не подтверждён. Проверь пароль, 2FA и права.")


def main() -> int:
    print("\n" + "=" * 60)
    print(" MUCHOCORE · КЛИЕНТЫ GD 2.209 · ТОЛЬКО 5V.PL")
    print("=" * 60)
    print("Для уже установленного GDPS. Админку, владельца и БД не изменяем.")
    site_url = site_origin(ask("Адрес своего GDPS (https://имя.5v.pl)"))
    ftp_host = valid_ftp_host(ask("FTP сервер"))
    ftp_user = ask("FTP логин")
    ftp_password = getpass.getpass("FTP пароль: ")
    mode = ask("FTPS или FTP [FTPS]", "FTPS").upper()
    if mode not in ("FTPS", "FTP"):
        raise InstallError("Выбери FTPS или FTP.")
    if mode == "FTP" and ask("FTP не шифруется. Напиши СОГЛАСЕН") != "СОГЛАСЕН":
        raise InstallError("Незащищённый FTP не подтверждён.")
    root_hint = ask("Корень сайта на FTP (Enter = авто)")
    user = ask("Логин администратора")
    password = getpass.getpass("Пароль администратора: ")
    otp = ask("Код 2FA или восстановления (Enter = не используется)")
    site = HttpsSite(site_url)
    with FtpSite(ftp_host, ftp_user, ftp_password,
                 secure=mode == "FTPS") as ftp:
        ftp.find_root(site_url, root_hint)
        if not ftp.exists_dir(ftp.root, "mc"):
            raise InstallError("Этот сайт пока не содержит MuchoCore.")
        print("Проверка входа владельца...")
        login_owner(site, user, password, otp)
        publish_interactive(site, ftp, advanced=True)
    return 0


if __name__ == "__main__":
    try:
        code = main()
    except (InstallError, OSError, ValueError) as exc:
        print("\nПодготовка клиентов остановлена:", exc)
        code = 1
    if sys.stdin.isatty():
        input("\nEnter для выхода...")
    sys.exit(code)
