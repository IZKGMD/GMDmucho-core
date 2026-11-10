"""One-command native MuchoCore installer for 5v.pl, shipped inside release ZIP."""
from __future__ import annotations

from datetime import datetime, timezone
import getpass
from pathlib import Path
import re
import secrets
import sys
import time

from mucho5v import (FtpSite, HttpsSite, FormFields, InstallError, merge_rules,
                     private_config, site_origin, valid_ftp_host, verify_private_folder)

RELEASE_DIR = Path(__file__).resolve().parent
RULE_FILE = RELEASE_DIR / "5V_ROOT_REWRITE_RULES.txt"
CORE_DIR = RELEASE_DIR / "mc"


def ask(label: str, default: str = "") -> str:
    suffix = " [" + default + "]" if default else ""
    result = input(label + suffix + ": ").strip()
    return result or default


def secret(label: str, minimum: int = 1, maximum: int = 256) -> str:
    value = getpass.getpass(label + ": ")
    if not minimum <= len(value) <= maximum:
        raise InstallError(label + ": длина должна быть от %s до %s символов." % (minimum, maximum))
    return value


def get_credentials(*, advanced: bool = False):
    print("\n" + "=" * 58)
    print(" MUCHOCORE · ПОЛНАЯ УСТАНОВКА · ТОЛЬКО 5V.PL")
    print("=" * 58)
    print("Сначала создай сайт и ПУСТУЮ MySQL-базу на https://5v.pl/")
    print("Никакие пароли не отправляются на muchogdps.space.")
    print("Повторная установка не удаляет пользователей или уровни.\n")
    site = site_origin(ask("Адрес сайта, например https://mygdps.5v.pl"))
    host = valid_ftp_host(ask("FTP сервер из кабинета 5v.pl"))
    user = ask("FTP логин")
    if not user or len(user) > 128:
        raise InstallError("Введи FTP-логин из кабинета 5v.pl.")
    password = secret("FTP пароль")
    # Safe quick mode: never downgrade FTPS automatically to plaintext FTP.
    mode = (ask("Соединение [FTPS/FTP]", "FTPS").upper()
            if advanced else "FTPS")
    if mode not in ("FTPS", "FTP"):
        raise InstallError("Выбери FTPS или FTP.")
    if mode == "FTP":
        ack = ask("FTP НЕ шифрует пароли и файлы! Напиши 'СОГЛАСЕН' для продолжения")
        if ack != "СОГЛАСЕН":
            raise InstallError("Незащищённый FTP не подтверждён.")
    # The common case needs no FTP root, MySQL hostname or port questions.
    # Operators with nonstandard settings can use --advanced.
    root = ask("Корневая папка сайта на FTP (Enter = авто)") if advanced else ""
    mysqlhost = (ask("MySQL сервер из кабинета 5v.pl", "localhost")
                 if advanced else "localhost")
    mysqlport = int(ask("Порт MySQL", "3306")) if advanced else 3306
    dbname = ask("Имя ПУСТОЙ базы MySQL")
    dbuser = ask("Логин базы MySQL")
    dbpassword = secret("Пароль базы MySQL", 8)
    username = ask("Логин владельца (по умолчанию admin)", "admin")
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,20}", username):
        raise InstallError("Имя администратора: 1-20 латинских букв, цифр, _ или -.")
    email = ask("E-mail владельца (для уведомлений и подтверждений)")
    if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
        raise InstallError("Укажи действующий e-mail владельца.")
    adminpassword = secret("Придумай пароль администратора (10-72 символа)", 10, 72)
    confirm = secret("Повтори пароль администратора", 10, 72)
    if not 10 <= len(adminpassword.encode("utf-8")) <= 72:
        raise InstallError("Пароль администратора должен иметь длину 10–72 байта в UTF-8.")
    if not secrets.compare_digest(adminpassword, confirm):
        raise InstallError("Пароли администратора не совпадают.")
    print("\nБудет установлен НОВЫЙ GDPS:", site)
    print("PHP 7 native; существующая папка mc/ НЕ перезаписывается.")
    if ask("Начать установку? [Д/н]", "Д").casefold() not in ("д", "да", "y", "yes"):
        raise InstallError("Установка отменена.")
    return dict(site=site, host=host, user=user, password=password,
                secure=mode=="FTPS", root=root, mysqlhost=mysqlhost,
                mysqlport=mysqlport, dbname=dbname, dbuser=dbuser,
                dbpassword=dbpassword, admin=username, email=email,
                adminpassword=adminpassword)


def csrf_from(page: bytes, expected_action: str) -> str:
    html = page.decode("utf-8", "replace")
    if ('value="' + expected_action + '"') not in html and \
            ("value='" + expected_action + "'") not in html:
        raise InstallError("Сайт не показал ожидаемую форму: " + expected_action)
    parser = FormFields()
    parser.feed(html)
    csrf = parser.fields.get("csrf", "")
    if not re.fullmatch(r"[a-f0-9]{48,64}", csrf):
        raise InstallError("Токен защиты формы не найден. Проверь работу PHP-сессий.")
    return csrf


def bootstrap_owner(site: HttpsSite, cfg):
    code, html = site.request("/mc/admin.php")
    if code != 200:
        raise InstallError("Админка недоступна по HTTPS (HTTP %d)." % code)
    if b'First owner' not in html or b'name="db_password"' not in html:
        raise InstallError("Админка не показала настройку первого владельца; существующие данные не меняем.")
    csrf = csrf_from(html, "bootstrap")
    code, page = site.request("/mc/admin.php", {
        "csrf": csrf, "action": "bootstrap", "db_password": cfg["dbpassword"],
        "userName": cfg["admin"], "email": cfg["email"],
        "password": cfg["adminpassword"], "site_url": cfg["site"],
        "enable_features": "1",
    })
    if code not in (200, 303) or b'First owner' in page or b'class="admin-login' in page:
        raise InstallError("Не удалось создать владельца. Проверь MySQL, пароль и журнал PHP.")
    print("Владелец создан; аккаунт не будет перезаписан повторным запуском.")


def perform_migration(site: HttpsSite, ftp: FtpSite):
    """Use the site's original authenticated, resumable owner-only migrations."""
    print("Подготавливаем оригинальное ядро и миграции MySQL...")
    for attempt in range(35):
        if ftp.get(ftp.root, "mc/config/php7-complete.on", optional=True) is not None:
            print("Миграция подтверждена приватным маркером на сайте.")
            return
        code, html = site.request("/mc/admin.php?page=system")
        if code != 200:
            raise InstallError("Админка перестала отвечать во время миграции.")
        csrf = csrf_from(html, "core-upgrade")
        code, result = site.request("/mc/admin.php?page=system", {
            "csrf": csrf, "action": "core-upgrade",
        })
        if code not in (200, 303):
            raise InstallError("Ошибка оригинального мастера миграции.")
        # The native migration deliberately waits 35 seconds for live API traffic
        # to drain before starting backups/import. Do not bypass its lock.
        time.sleep(36 if attempt == 0 else 2)
    raise InstallError("Миграция не завершилась автоматически. Данные сохранены. Продолжи в /mc/admin.php?page=system.")


def perform_install(cfg):
    if not CORE_DIR.is_dir() or not RULE_FILE.is_file():
        raise InstallError("Распакуй весь релизный ZIP. Папка mc/ и правила 5V отсутствуют.")
    site = HttpsSite(cfg["site"])
    report = {"site": cfg["site"], "date": datetime.now(timezone.utc).isoformat(),
              "status": "started", "owner_username": cfg["admin"]}
    try:
        with FtpSite(cfg["host"], cfg["user"], cfg["password"],
                     secure=cfg["secure"]) as ftp:
            print("Проверка владения сайтом через FTP + HTTPS...")
            root = ftp.find_root(cfg["site"], cfg["root"])
            print("Подтверждена папка сайта:", root)
            if ftp.exists_dir(root, "mc"):
                raise InstallError(
                    "Папка mc/ уже существует. Чтобы не потерять админку и данные, "
                    "новая установка ОСТАНОВЛЕНА. Для сброса пароля используй RECOVER_ADMIN_5V.py.")
            ftp.upload_tree(CORE_DIR)
            print("Защита приватных файлов...")
            verify_private_folder(ftp, site)
            cfg_data = private_config(cfg["site"], cfg["mysqlhost"], cfg["mysqlport"],
                                      cfg["dbname"], cfg["dbuser"], cfg["dbpassword"])
            tmp = "mc/config/php7-local.php.setup-" + secrets.token_hex(6)
            ftp.put(root, tmp, cfg_data)
            ftp.rename(root, tmp, "mc/config/php7-local.php")
            print("Настройка маршрутов с резервной копией .htaccess...")
            ftp.apply_routes(RULE_FILE.read_bytes())
            print("Создание первого владельца через HTTPS...")
            bootstrap_owner(site, cfg)
            print("Автоматическая миграция базы...")
            perform_migration(site, ftp)

            code, _ = site.request("/mc/admin.php?page=overview")
            if code != 200:
                raise InstallError("Веб-админка не прошла заключительную проверку.")
            report["status"] = "admin_and_migration_ok"
            print("\nУстановка ядра завершена. Админка:", cfg["site"] + "/mc/admin.php")
            print("\nТеперь можно автоматически подготовить EXE и APK:")
            try:
                from PUBLISH_CLIENTS_5V import publish_interactive
                report["client_links"] = publish_interactive(site, ftp)
                report["clients_status"] = "published" if report["client_links"] else "skipped"
            except (InstallError, OSError, ValueError) as client_error:
                report["clients_status"] = "needs_attention"
                print("Клиентам нужна отдельная проверка:", client_error)
                print("Само ядро и админка УЖЕ установлены и не затрагиваются.")
                print("Можно повторить только публикацию: START_CLIENTS_WINDOWS.cmd")
            print("Сохрани FTP-учётные данные в надёжном месте!")
            print("Если забудешь пароль/2FA: START_RECOVERY_WINDOWS.cmd")
            print("Совместимость ВСЕХ операций Geometry Dash 2.209 проверяется отдельно.")
    except Exception:
        report["status"] = "incomplete"
        print("Если произошла ошибка, аккаунты и существующая база не удаляются.")
        raise
    finally:
        try:
            import json
            (RELEASE_DIR / "muchocore-install-report.json").write_text(
                json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
        except OSError:
            pass


def main():
    try:
        cfg = get_credentials(advanced="--advanced" in sys.argv[1:])
        perform_install(cfg)
    except (InstallError, OSError, ValueError) as exc:
        print("\nУстановка остановлена:", str(exc))
        print("Исправь проблему и проверь состояние сайта перед повторным запуском.")
        return 1
    return 0


if __name__ == "__main__":
    result = main()
    if sys.stdin.isatty():
        input("\nНажми Enter для выхода...")
    sys.exit(result)
