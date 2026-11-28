"""Recover an EXISTING MuchoCore 5v.pl owner via proven FTP site control.

This tool does not create admins, wipe tables, remove accounts or change roles.
A random, expiring, one-use PHP handler is uploaded and removed immediately.
"""
from __future__ import annotations
import getpass
import hashlib
import json
from pathlib import Path
import re
import secrets
import sys
import time
import urllib.parse

from mucho5v import (FtpSite, HttpsSite, InstallError, site_origin,
                     valid_ftp_host)

BASE = Path(__file__).resolve().parent
TEMPLATE = BASE / "RECOVERY_HANDLER.php.tpl"


def prompt(label, default=""):
    value = input(label + (" [" + default + "]" if default else "") + ": ").strip()
    return value or default


def recover(site_url: str, ftp_host: str, ftp_username: str, ftp_password: str,
            owner: str, new_password: str, *, ftps=True, root_hint=""):
    origin = site_origin(site_url)
    ftp_host = valid_ftp_host(ftp_host)
    if owner and not re.fullmatch(r"[A-Za-z0-9_-]{1,20}", owner):
        raise InstallError("Enter the username of an existing owner.")
    if not 10 <= len(new_password.encode("utf-8")) <= 72:
        raise InstallError("Choose a new owner password of 10–72 bytes.")
    if not TEMPLATE.is_file():
        raise InstallError("Extract the full archive to obtain RECOVERY_HANDLER.php.tpl.")
    body = TEMPLATE.read_text(encoding="utf-8")
    if not all(item in body for item in ("__HASH__", "__EXPIRES__", "__DOMAIN__")):
        raise InstallError("The recovery file is invalid.")
    https = HttpsSite(origin)
    with FtpSite(ftp_host, ftp_username, ftp_password, secure=ftps) as ftp:
        root = ftp.find_root(origin, root_hint)
        if not ftp.exists_dir(root, "mc"):
            raise InstallError("The mc/ folder is missing. Install MuchoCore first.")
        token = secrets.token_hex(32)
        name = "mc/public/mucho-rescue-" + secrets.token_hex(16) + ".php"
        domain = urllib.parse.urlsplit(origin).hostname
        php = (body.replace("__HASH__", hashlib.sha256(token.encode("ascii")).hexdigest())
                   .replace("__EXPIRES__", str(int(time.time()) + 600))
                   .replace("__DOMAIN__", domain))
        if "__" + "HASH__" in php or "__EXPIRES__" in php:
            raise InstallError("The recovery template is incomplete.")
        ftp.put(root, name, php.encode("utf-8"))
        try:
            response = None
            for attempt in range(4):
                code, payload = https.request("/" + name, {
                    "token": token, "username": owner, "password": new_password
                })
                if code == 404 and attempt < 3:
                    time.sleep(2)
                    continue
                response = (code, payload)
                break
            if response is None:
                raise InstallError("5v.pl could not run the temporary recovery script.")
            code, payload = response
            try:
                result = json.loads(payload.decode("utf-8", "replace"))
            except (UnicodeDecodeError, ValueError):
                raise InstallError("5v.pl returned an unexpected recovery response.")
            if code != 200 or result.get("ok") is not True:
                raise InstallError("Recovery could not be verified. Check the owner username and database.")
        finally:
            ftp.delete(root, name)

    print("\nAccess restored:", origin + "/mc/admin.php")
    print("The owner's password was reset. Your database, levels, roles, and accounts were preserved.")
    print("Existing two-factor credentials, recovery codes, passkeys, and sessions were revoked.")
    print("Sign in, set up two-factor authentication again, and save the new recovery codes.")


def main():
    print("\n" + "=" * 58)
    print(" MUCHOCORE · ВОССТАНОВИТЬ СВОЮ АДМИНКУ · 5V.PL")
    print("=" * 58)
    print("Потребуется FTP-доступ к СВОЕМУ сайту. MySQL не очищается.")
    print("Сбросит пароль существующего владельца и потерянную 2FA.\n")
    site = site_origin(prompt("Адрес своего сайта https://name.5v.pl"))
    host = valid_ftp_host(prompt("FTP сервер"))
    user = prompt("FTP логин")
    password = getpass.getpass("FTP пароль: ")
    mode = prompt("Соединение FTPS или FTP [FTPS]", "FTPS").upper()
    if mode not in ("FTPS", "FTP"):
        raise InstallError("Выбери FTPS или FTP.")
    if mode == "FTP" and prompt("FTP не шифруется. Напиши СОГЛАСЕН") != "СОГЛАСЕН":
        raise InstallError("Незащищённый FTP не подтверждён.")
    root = prompt("Папка сайта на FTP (Enter = авто)")
    owner = prompt("Логин владельца (Enter = найти единственного владельца автоматически)")
    new = getpass.getpass("Новый пароль админки (10-72 байта): ")
    confirmation = getpass.getpass("Повтори новый пароль: ")
    if not secrets.compare_digest(new, confirmation):
        raise InstallError("Пароли не совпадают.")
    if prompt("Напиши ВОССТАНОВИТЬ для подтверждения") != "ВОССТАНОВИТЬ":
        raise InstallError("Операция отменена.")
    recover(site, host, user, password, owner, new,
            ftps=(mode == "FTPS"), root_hint=root)
    return 0


if __name__ == "__main__":
    try:
        exit_code = main()
    except (InstallError, OSError, ValueError) as exc:
        print("\nВосстановление остановлено:", str(exc))
        print("Никакие таблицы и аккаунты при ошибке не удаляются.")
        exit_code = 1
    if sys.stdin.isatty():
        input("\nНажми Enter для выхода...")
    sys.exit(exit_code)
