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
        raise InstallError("Укажи существующее имя владельца, не нового администратора.")
    if not 10 <= len(new_password.encode("utf-8")) <= 72:
        raise InstallError("Новый пароль владельца: 10–72 байта.")
    if not TEMPLATE.is_file():
        raise InstallError("Распакуй архив полностью: отсутствует RECOVERY_HANDLER.php.tpl.")
    body = TEMPLATE.read_text(encoding="utf-8")
    if not all(item in body for item in ("__HASH__", "__EXPIRES__", "__DOMAIN__")):
        raise InstallError("Файл восстановления повреждён.")
    https = HttpsSite(origin)
    with FtpSite(ftp_host, ftp_username, ftp_password, secure=ftps) as ftp:
        root = ftp.find_root(origin, root_hint)
        if not ftp.exists_dir(root, "mc"):
            raise InstallError("Папка mc/ не найдена. Сначала установи ядро.")
        token = secrets.token_hex(32)
        name = "mc/public/mucho-rescue-" + secrets.token_hex(16) + ".php"
        domain = urllib.parse.urlsplit(origin).hostname
        php = (body.replace("__HASH__", hashlib.sha256(token.encode("ascii")).hexdigest())
                   .replace("__EXPIRES__", str(int(time.time()) + 600))
                   .replace("__DOMAIN__", domain))
        if "__" + "HASH__" in php or "__EXPIRES__" in php:
            raise InstallError("Шаблон восстановления не заполнен.")
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
                raise InstallError("5v.pl не запустил временный обработчик восстановления.")
            code, payload = response
            try:
                result = json.loads(payload.decode("utf-8", "replace"))
            except (UnicodeDecodeError, ValueError):
                raise InstallError("5v.pl вернул неожиданный ответ при восстановлении.")
            if code != 200 or result.get("ok") is not True:
                raise InstallError("Восстановление не подтверждено. Проверь имя владельца и БД.")
        finally:
            ftp.delete(root, name)

    print("\nДОСТУП ВОССТАНОВЛЕН:", origin + "/mc/admin.php")
    print("Пароль существующего владельца изменён. База, уровни, роль и аккаунты сохранены.")
    print("Прежние 2FA, recovery-коды, passkeys и сессии отозваны.")
    print("После входа включи 2FA заново и обязательно СОХРАНИ новые резервные коды.")


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
