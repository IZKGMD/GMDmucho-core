"""One-command native MuchoCore installer for 5v.pl, shipped inside release ZIP."""
from __future__ import annotations

from datetime import datetime, timezone
import getpass
import hashlib
import json
from pathlib import Path
import re
import secrets
import sys
import time

from mucho5v import (FtpSite, HttpsSite, FormFields, InstallError, merge_rules,
                     private_config, site_origin, valid_ftp_host, verify_private_folder)

# PyInstaller one-file builds extract read-only payload files to _MEIPASS.
# Reports and patched clients belong beside the executable, never in that temp dir.
BUNDLE_DIR = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent))
RELEASE_DIR = (Path(sys.executable).resolve().parent
               if getattr(sys, "frozen", False)
               else Path(__file__).resolve().parent)
RULE_FILE = BUNDLE_DIR / "5V_ROOT_REWRITE_RULES.txt"
CORE_DIR = BUNDLE_DIR / "mc"


STATE_FILE = ".muchocore-install-state.php"
STATE_PREFIX = b"<?php http_response_code(404); exit; ?>\n"


def payload_digest() -> str:
    digest = hashlib.sha256()
    for path in sorted(CORE_DIR.rglob("*")):
        if path.is_symlink():
            raise InstallError("Архив не должен содержать символьные ссылки.")
        if path.is_file():
            digest.update(path.relative_to(CORE_DIR).as_posix().encode("utf-8") + b"\0")
            digest.update(hashlib.sha256(path.read_bytes()).digest())
    digest.update(RULE_FILE.read_bytes())
    return digest.hexdigest()


def install_identity(cfg) -> dict:
    # Bind a checkpoint to the site, payload and database; never store passwords.
    return {"site": cfg["site"], "payload": payload_digest(),
            "database": [cfg["mysqlhost"], int(cfg["mysqlport"]), cfg["dbname"], cfg["dbuser"]],
            "owner": cfg["admin"]}


def read_checkpoint(ftp, identity):
    raw = ftp.get(ftp.root, STATE_FILE, optional=True)
    try:
        if raw is None or not raw.startswith(STATE_PREFIX):
            raise ValueError("missing checkpoint")
        state = json.loads(raw[len(STATE_PREFIX):])
        if state.get("identity") != identity or state.get("stage") not in ("upload", "configured", "owner", "complete"):
            raise ValueError("checkpoint mismatch")
        return state
    except (ValueError, AttributeError, TypeError):
        raise InstallError(
            "This site already has an mc/ folder from another installation. "
            "Use the updater to upgrade, or choose Recover admin access to reset the owner password. "
            "Your files and database were preserved.") from None


def write_checkpoint(ftp, identity, stage):
    data = STATE_PREFIX + json.dumps({"identity": identity, "stage": stage}, ensure_ascii=False).encode("utf-8")
    temporary = STATE_FILE[:-4] + ".tmp-" + secrets.token_hex(6) + ".php"
    ftp.put(ftp.root, temporary, data)
    ftp.rename(ftp.root, temporary, STATE_FILE)


def check_empty_database(ftp, site, cfg):
    """Check credentials and emptiness before uploading the core, without SQL writes."""
    template = BUNDLE_DIR / "DATABASE_CHECK.php.tpl"
    if not template.is_file():
        raise InstallError("The database check is missing. Download a fresh copy of Setup or extract the full archive.")
    token = secrets.token_hex(32)
    host = cfg["site"].split("//", 1)[1]
    content = (template.read_text(encoding="utf-8")
               .replace("__DOMAIN__", host)
               .replace("__EXPIRES__", str(int(time.time()) + 300))
               .replace("__HASH__", hashlib.sha256(token.encode("ascii")).hexdigest()))
    name = "muchocore-db-check-" + secrets.token_hex(12) + ".php"
    try:
        ftp.put(ftp.root, name, content.encode("utf-8"))
        code, body = site.request("/" + name, {
            "token": token, "host": cfg["mysqlhost"], "port": cfg["mysqlport"],
            "database": cfg["dbname"], "user": cfg["dbuser"], "password": cfg["dbpassword"],
        })
        try:
            result = json.loads(body)
        except (ValueError, UnicodeError):
            result = {}
        if code != 200 or not isinstance(result, dict) or result.get("ok") is not True:
            reason = result.get("error") if isinstance(result, dict) else None
            if reason == "database_not_empty":
                raise InstallError("The database already contains tables. Create a separate empty database for this installation.")
            raise InstallError("Could not connect to the empty MySQL database. Check its name, username, and password in the 5v.pl dashboard.")
    finally:
        ftp.delete(ftp.root, name)


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
    print("PHP 7 native; продолжение своей установки сохраняет настройки и владельца.")
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
        raise InstallError("The site did not return the expected form: " + expected_action)
    parser = FormFields()
    parser.feed(html)
    csrf = parser.fields.get("csrf", "")
    if not re.fullmatch(r"[a-f0-9]{48,64}", csrf):
        raise InstallError("The form security token is missing. Check that PHP sessions work.")
    return csrf


def bootstrap_owner(site: HttpsSite, cfg):
    code, html = site.request("/mc/admin.php")
    if code != 200:
        raise InstallError("The admin panel is unavailable over HTTPS (HTTP %d)." % code)
    if (b'First owner' not in html and b'Create your administrator' not in html) or b'name="db_password"' not in html:
        # A previous POST can commit successfully even if its response was lost.
        # Authenticate the same owner rather than trying to recreate the account.
        from PUBLISH_CLIENTS_5V import login_owner
        login_owner(site, cfg["admin"], cfg["adminpassword"])
        print("Existing owner verified. Resuming installation.")
        return
    csrf = csrf_from(html, "bootstrap")
    code, page = site.request("/mc/admin.php", {
        "csrf": csrf, "action": "bootstrap", "db_password": cfg["dbpassword"],
        "userName": cfg["admin"], "email": cfg["email"],
        "password": cfg["adminpassword"], "password_confirm": cfg["adminpassword"], "site_url": cfg["site"],
        "enable_features": "1",
    })
    if code not in (200, 303) or b'First owner' in page or b'class="admin-login' in page:
        raise InstallError("Could not create the owner. Check MySQL, the owner password, and the PHP error log.")
    print("Owner created. Setup preserves this account if you resume installation.")


def initiate_owner_email_recovery(site: HttpsSite, cfg: dict) -> str:
    """Queue verification via authenticated native PHP mail(), not client SMTP.

    A queued message is NOT a delivered email. The owner must confirm it.
    Mail failure never rolls back a successfully installed GDPS.
    """
    try:
        code, page = site.request("/mc/admin.php?page=admins")
        if code != 200 or b'value="admin-mail-verify-send"' not in page:
            return "unavailable"
        if b"<strong>Verified:</strong>" in page:
            return "verified"
        if b"<strong>Awaiting confirmation:</strong>" in page:
            return "pending"
        csrf = csrf_from(page, "admin-mail-verify-send")
        code, response = site.request("/mc/admin.php?page=admins", {
            "csrf": csrf, "action": "admin-mail-verify-send",
            "email": cfg["email"], "current_password": cfg["adminpassword"],
        })
        if code == 200 and b"Verification email submitted to the mail relay." in response:
            return "verification_queued"
    except (InstallError, OSError, ValueError, KeyError):
        pass
    return "unavailable"


def perform_migration(site: HttpsSite, ftp: FtpSite):
    """Use the site's original authenticated, resumable owner-only migrations."""
    print("Preparing the core and MySQL migrations…")
    for attempt in range(35):
        if ftp.get(ftp.root, "mc/config/php7-complete.on", optional=True) is not None:
            print("Migration verified using a private marker.")
            return
        code, html = site.request("/mc/admin.php?page=system")
        if code != 200:
            raise InstallError("The admin panel stopped responding during migration.")
        csrf = csrf_from(html, "core-upgrade")
        code, result = site.request("/mc/admin.php?page=system", {
            "csrf": csrf, "action": "core-upgrade",
        })
        if code not in (200, 303):
            raise InstallError("The database migration failed.")
        # The native migration deliberately waits 35 seconds for live API traffic
        # to drain before starting backups/import. Do not bypass its lock.
        time.sleep(36 if attempt == 0 else 2)
    raise InstallError("Setup could not finish migration. Your data was saved. Continue at /mc/admin.php?page=system.")


def perform_install(cfg, *, prepare_clients: bool = True):
    if not CORE_DIR.is_dir() or not RULE_FILE.is_file():
        raise InstallError("Extract the full release archive. Setup could not find mc/ or the 5v.pl routing rules.")
    private_config(cfg["site"], cfg["mysqlhost"], cfg["mysqlport"], cfg["dbname"], cfg["dbuser"], cfg["dbpassword"])
    site = HttpsSite(cfg["site"])
    report = {"site": cfg["site"], "date": datetime.now(timezone.utc).isoformat(),
              "status": "started", "owner_username": cfg["admin"]}
    try:
        with FtpSite(cfg["host"], cfg["user"], cfg["password"],
                     secure=cfg["secure"]) as ftp:
            print("Verifying site ownership over FTP and HTTPS…")
            root = ftp.find_root(cfg["site"], cfg["root"])
            cfg["_resolved_root"] = root
            print("Verified website folder:", root)
            identity = install_identity(cfg)
            existing = ftp.exists_dir(root, "mc")
            state = read_checkpoint(ftp, identity) if existing else {"stage": "upload"}
            if existing:
                print("Resuming this installation and checking its progress.")
            configured = ftp.get(root, "mc/config/php7-local.php", optional=True) if existing else None
            if state["stage"] == "upload" and configured is None:
                print("[1/5] Checking MySQL before upload…")
                check_empty_database(ftp, site, cfg)
                # Create the checkpoint before upload so an interrupted transfer is resumable.
                write_checkpoint(ftp, identity, "upload")
                print("[2/5] Uploading files…")
                ftp.upload_tree(CORE_DIR)
                print("Verifying protection of private files…")
                verify_private_folder(ftp, site)
                cfg_data = private_config(cfg["site"], cfg["mysqlhost"], cfg["mysqlport"],
                                          cfg["dbname"], cfg["dbuser"], cfg["dbpassword"])
                tmp = "mc/config/php7-local.php.setup-" + secrets.token_hex(6)
                ftp.put(root, tmp, cfg_data)
                ftp.rename(root, tmp, "mc/config/php7-local.php")
            elif configured is None:
                raise InstallError("The unfinished installation's private settings are missing. Check your backup before continuing.")
            else:
                # Never regenerate auth_pepper or overwrite live DB credentials on retry.
                verify_private_folder(ftp, site)
            if state["stage"] == "complete":
                from PUBLISH_CLIENTS_5V import login_owner
                login_owner(site, cfg["admin"], cfg["adminpassword"])
                print("MuchoCore is already installed and the owner login works.")
            else:
                write_checkpoint(ftp, identity, "configured")
                print("[3/5] Configuring routes…")
                ftp.apply_routes(RULE_FILE.read_bytes(), resume=existing)
            if state["stage"] != "complete":
                print("[4/5] Creating the administrator…")
                bootstrap_owner(site, cfg)
                write_checkpoint(ftp, identity, "owner")
                print("[5/5] Finishing database setup…")
                perform_migration(site, ftp)
            code, health = site.request("/mc/health")
            if code != 200 or health.strip() != b"1":
                raise InstallError("The core failed the /mc/health check. Your settings were saved. Run Setup again to resume.")
            code, page = site.request("/mc/admin.php?page=overview")
            if code != 200 or b'name="action" value="login"' in page:
                raise InstallError("The admin panel failed its final check.")
            # Do not make a mailbox or SMTP password another installation step.
            # Only confirmation in the recipient's inbox activates recovery.
            mail_status = initiate_owner_email_recovery(site, cfg)
            report["owner_recovery_email"] = mail_status
            if mail_status == "verification_queued":
                print("5v.pl queued the confirmation email.")
                print("Open the confirmation link. Check your inbox and spam folder.")
            elif mail_status == "pending":
                print("Email confirmation is pending. Check your inbox.")
            elif mail_status == "verified":
                print("The owner's email address is already confirmed.")
            else:
                print("Email confirmation is pending. You can still recover your password through FTP.")
                print("To resend the email, open Administrators → Password recovery email in the admin panel.")
            write_checkpoint(ftp, identity, "complete")
            report["status"] = "admin_and_migration_ok"
            print("\nCore installed. Admin panel:", cfg["site"] + "/mc/admin.php")
            if prepare_clients:
                print("\nYou can now prepare your EXE and APK:")
                try:
                    from PUBLISH_CLIENTS_5V import publish_interactive
                    report["client_links"] = publish_interactive(site, ftp)
                    report["clients_status"] = "published" if report["client_links"] else "skipped"
                except (InstallError, OSError, ValueError) as client_error:
                    report["clients_status"] = "needs_attention"
                    print("Client preparation requires a separate check:", client_error)
                    print("The core and admin panel are already installed.")
                    print("To retry client preparation, open START_CLIENTS_WINDOWS.cmd.")
            else:
                report["clients_status"] = "deferred"
                print("Setup will prepare game clients separately.")
            print("Store your FTP credentials somewhere safe.")
            print("To recover the password or two-factor access, run START_RECOVERY_WINDOWS.cmd.")
            print("Full Geometry Dash 2.209 compatibility is checked separately.")
    except Exception:
        report["status"] = "incomplete"
        print("Your existing accounts and database are preserved if setup fails.")
        raise
    finally:
        try:
            (RELEASE_DIR / "muchocore-install-report.json").write_text(
                json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
        except OSError:
            pass
    return report


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
