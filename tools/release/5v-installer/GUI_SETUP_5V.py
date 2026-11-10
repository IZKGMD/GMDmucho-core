"""MuchoCore 5v.pl Windows one-file GUI installer.

The PyInstaller build embeds the reviewed PHP7 payload and Python standard-library
installer modules. Credentials stay in memory and are never written to disk.
"""
from __future__ import annotations

import contextlib
import os
from pathlib import Path
import queue
import re
import sys
import threading
import traceback
import webbrowser

import tkinter as tk
from tkinter import filedialog, messagebox, ttk

BUNDLE_DIR = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent))
APP_DIR = (Path(sys.executable).resolve().parent
           if getattr(sys, "frozen", False)
           else Path(__file__).resolve().parent)
if str(BUNDLE_DIR) not in sys.path:
    sys.path.insert(0, str(BUNDLE_DIR))

import INSTALL_5V
import RECOVER_ADMIN_5V
from mucho5v import FtpSite, HttpsSite, InstallError, private_config, site_origin, valid_ftp_host
from PUBLISH_CLIENTS_5V import login_owner
from muchoclients5v import (
    patch_windows, patch_android, publish_windows, publish_android,
    publish_index, read_source, save_links, cleanup_published_job,
)

RULE_FILE = BUNDLE_DIR / "5V_ROOT_REWRITE_RULES.txt"
CORE_DIR = BUNDLE_DIR / "mc"
OUTDIR = APP_DIR / "patched_clients"


def _self_test() -> int:
    """Offline packaging smoke test. It must not open a GUI or use the network."""
    required = (
        CORE_DIR / "admin.php",
        CORE_DIR / "public" / "php7.php",
        CORE_DIR / "compat" / "php7" / "runtime.php",
        RULE_FILE,
        BUNDLE_DIR / "RECOVERY_HANDLER.php.tpl",
    )
    if not all(item.is_file() for item in required):
        return 2
    if (not callable(INSTALL_5V.perform_install)
            or not callable(RECOVER_ADMIN_5V.recover)
            or not callable(patch_windows)):
        return 3
    try:
        import gd_windows_patcher
        if not callable(gd_windows_patcher.patch_client):
            return 4
    except Exception:
        return 5
    return 0


class QueueWriter:
    def __init__(self, events):
        self.events = events
        self.buffer = ""

    def write(self, value):
        if not value:
            return
        self.buffer += value
        while "\n" in self.buffer:
            line, self.buffer = self.buffer.split("\n", 1)
            if line.strip():
                self.events.put(("log", line))

    def flush(self):
        if self.buffer.strip():
            self.events.put(("log", self.buffer))
        self.buffer = ""


def detect_client(extension: str, chosen: str = "") -> Path | None:
    if chosen:
        return read_source(chosen, extension)
    folders = [APP_DIR, APP_DIR / "original_clients"]
    if extension == ".apk":
        folders += [Path.home() / "Downloads", APP_DIR / "Downloads"]
    else:
        for env_key in ("PROGRAMFILES(X86)", "PROGRAMFILES"):
            base = os.environ.get(env_key)
            if base:
                folders.append(Path(base) / "Steam" / "steamapps" /
                               "common" / "Geometry Dash")
    matches = []
    for folder in folders:
        if folder.is_dir():
            matches.extend(
                p.resolve() for p in folder.glob("GeometryDash*")
                if p.is_file() and p.suffix.lower() == extension
                and "MuchoCore" not in p.name
            )
    matches = sorted(set(matches))
    if len(matches) == 1:
        return read_source(matches[0], extension)
    if len(matches) > 1:
        # Ambiguous optional client files must never block the core installation.
        return None
    return None


def prepare_clients(cfg: dict, exe_source: Path | None,
                    apk_source: Path | None, redistribution_rights: bool,
                    log=print) -> dict:
    """Patch user-provided clients; publish only after explicit rights confirmation."""
    links = {}
    ftp_needed = bool(redistribution_rights and (exe_source or apk_source))
    site = HttpsSite(cfg["site"])
    ftp_context = None

    try:
        if ftp_needed:
            ftp_context = FtpSite(
                cfg["host"], cfg["user"], cfg["password"],
                secure=cfg["secure"],
            )
            ftp = ftp_context.__enter__()
            ftp.root = cfg.get("_resolved_root")
            if not ftp.root:
                raise InstallError("Корневая FTP-папка не сохранена после установки.")
        else:
            ftp = None

        if exe_source:
            try:
                output, report = patch_windows(exe_source, cfg["site"], OUTDIR)
                log("Windows EXE пропатчен и проверен локально: " + output.name)
                if redistribution_rights:
                    url = publish_windows(ftp, site, output, report)
                    links["exe"] = {
                        "url": url, "sha256": report["sha256"],
                        "size": report["size"],
                    }
                    log("Ссылка Windows: " + url)
                else:
                    log("Публичная загрузка EXE отключена: нет подтверждения прав.")
            except (InstallError, OSError, ValueError) as exc:
                log("EXE: " + str(exc))

        if apk_source:
            if not redistribution_rights:
                log("APK пропущен: публичная публикация требует подтверждения прав.")
                log("Для личного использования доступен Client Patcher в админке.")
            else:
                try:
                    log("Вход владельца для защищённой обработки APK...")
                    login_owner(site, cfg["admin"], cfg["adminpassword"])
                    report = patch_android(
                        site, apk_source, cfg["site"],
                        alongside=apk_source.stat().st_size == 174_568_364,
                        progress=log,
                    )
                    url = publish_android(ftp, site, report, OUTDIR)
                    links["apk"] = {
                        "url": url, "sha256": report["output_sha256"],
                        "size": report["output_size"],
                        "certificate_sha256": report.get("certificate_sha256"),
                    }
                    log("Ссылка Android: " + url)
                    try:
                        if cleanup_published_job(site, report["job_id"]):
                            log("Временные файлы APK очищены; ключ подписи сохранён.")
                    except InstallError as exc:
                        log("Предупреждение: временные файлы APK остались: " + str(exc))
                    log("Сохрани закрытый mc/config/php7-apk-signer.json в резервной копии.")
                except (InstallError, OSError, ValueError) as exc:
                    log("APK: " + str(exc))

        if links:
            try:
                page = publish_index(ftp, site, links)
                log("Страница клиентов: " + page)
            except (InstallError, OSError) as exc:
                log("Прямые ссылки готовы, но индекс не обновлён: " + str(exc))
            try:
                saved = save_links(OUTDIR, cfg["site"], links)
                log("Ссылки и SHA-256 сохранены локально: " + str(saved))
            except OSError as exc:
                log("Не удалось сохранить локальный список ссылок: " + str(exc))
    finally:
        if ftp_context is not None:
            ftp_context.__exit__(None, None, None)
    return links


def main() -> int:
    if "--self-test" in sys.argv:
        return _self_test()

    class InstallerWindow:
        def __init__(self, root):
            self.root = root
            self.events = queue.Queue()
            self.worker = None
            self.recovery_active = False
            self.advanced = False
            self.root.title("MuchoCore Setup · Только 5v.pl")
            self.root.geometry("790x850")
            self.root.minsize(720, 720)
            self.root.configure(bg="#111018")

            style = ttk.Style()
            try:
                style.theme_use("clam")
            except tk.TclError:
                pass
            style.configure("TFrame", background="#17151f")
            style.configure("TLabel", background="#17151f", foreground="#eeeaf5",
                            font=("Segoe UI", 9))
            style.configure("Title.TLabel", font=("Segoe UI", 19, "bold"),
                            foreground="#f4efff")
            style.configure("Sub.TLabel", foreground="#aaa3b8")
            style.configure("TButton", font=("Segoe UI", 9), padding=7)
            style.configure("Accent.TButton", font=("Segoe UI", 10, "bold"),
                            foreground="#ffffff", background="#7652ad")
            style.map("Accent.TButton", background=[("active", "#8c68c2")])
            style.configure("TEntry", padding=6)
            style.configure("TLabelframe", background="#17151f",
                            foreground="#eeeaf5")
            style.configure("TLabelframe.Label", background="#17151f",
                            foreground="#cbb6ec", font=("Segoe UI", 9, "bold"))

            outer = ttk.Frame(root, padding=18)
            outer.pack(fill="both", expand=True)
            ttk.Label(outer, text="MuchoCore", style="Title.TLabel").pack(anchor="w")
            ttk.Label(
                outer,
                text="Установка GDPS на shared-хостинг 5v.pl · Windows",
                style="Sub.TLabel",
            ).pack(anchor="w", pady=(2, 12))

            top = ttk.Frame(outer)
            top.pack(fill="x", pady=(0, 10))
            ttk.Button(top, text="Открыть 5v.pl",
                       command=lambda: webbrowser.open("https://5v.pl/")).pack(side="left")
            ttk.Button(top, text="Где найти FTP-данные?",
                       command=lambda: webbrowser.open(
                           "https://5v.pl/pomoc,pytanie,serwer-ftp-zapomnialem-nazwy-hosta-lub-moj-unikalny-login.html"
                       )).pack(side="left", padx=7)
            ttk.Button(top, text="Восстановить админку",
                       command=self.open_recovery).pack(side="right")

            self.vars = {}
            self.entries = {}
            form = ttk.Frame(outer)
            form.pack(fill="x")
            form.columnconfigure(1, weight=1)

            def field(row, key, label, placeholder="", secret=False):
                ttk.Label(form, text=label).grid(row=row, column=0, sticky="w",
                                                  padx=(0, 12), pady=4)
                var = tk.StringVar(value=placeholder)
                entry = ttk.Entry(form, textvariable=var, show="*" if secret else "")
                entry.grid(row=row, column=1, sticky="ew", pady=4)
                self.vars[key] = var
                self.entries[key] = entry
                return entry

            row = 0
            ttk.Label(form, text="САЙТ И FTP", foreground="#cbb6ec",
                      font=("Segoe UI", 9, "bold")).grid(
                          row=row, column=0, columnspan=2, sticky="w", pady=(0, 3))
            row += 1
            field(row, "site", "Адрес GDPS", "https://mygdps.5v.pl"); row += 1
            field(row, "ftp_host", "FTP-сервер 5v.pl"); row += 1
            field(row, "ftp_user", "FTP-логин"); row += 1
            field(row, "ftp_password", "FTP-пароль", secret=True); row += 1

            ttk.Label(form, text="MYSQL И ПЕРВЫЙ ВЛАДЕЛЕЦ", foreground="#cbb6ec",
                      font=("Segoe UI", 9, "bold")).grid(
                          row=row, column=0, columnspan=2, sticky="w", pady=(10, 3))
            row += 1
            field(row, "db_name", "Имя пустой MySQL-базы"); row += 1
            field(row, "db_user", "Логин MySQL"); row += 1
            field(row, "db_password", "Пароль MySQL", secret=True); row += 1
            field(row, "admin", "Логин владельца", "admin"); row += 1
            field(row, "email", "E-mail владельца"); row += 1
            field(row, "admin_password", "Пароль владельца (10–72 символа)", secret=True); row += 1
            field(row, "admin_confirm", "Повтори пароль владельца", secret=True); row += 1

            self.empty_db = tk.BooleanVar(value=False)
            ttk.Checkbutton(
                form, text="Я создал сайт и действительно пустую MySQL-базу",
                variable=self.empty_db,
            ).grid(row=row, column=0, columnspan=2, sticky="w", pady=(7, 2))
            row += 1

            self.advanced_button = ttk.Button(
                form, text="Дополнительные настройки ▾",
                command=self.toggle_advanced,
            )
            self.advanced_button.grid(row=row, column=0, columnspan=2,
                                     sticky="w", pady=(8, 2))
            row += 1
            self.advanced_frame = ttk.Frame(form)
            self.advanced_frame.grid(row=row, column=0, columnspan=2, sticky="ew")
            self.advanced_frame.columnconfigure(1, weight=1)
            self.advanced_vars = {
                "mysqlhost": tk.StringVar(value="localhost"),
                "mysqlport": tk.StringVar(value="3306"),
                "root": tk.StringVar(value=""),
                "ftp_mode": tk.StringVar(value="FTPS"),
            }
            for n, (key, label) in enumerate((
                ("mysqlhost", "MySQL host"),
                ("mysqlport", "MySQL port"),
                ("root", "FTP-папка (пусто = авто)"),
            )):
                ttk.Label(self.advanced_frame, text=label).grid(
                    row=n, column=0, sticky="w", padx=(0, 12), pady=3)
                ttk.Entry(self.advanced_frame,
                          textvariable=self.advanced_vars[key]).grid(
                              row=n, column=1, sticky="ew", pady=3)
            ttk.Label(self.advanced_frame, text="FTP-защита").grid(
                row=3, column=0, sticky="w", padx=(0, 12), pady=3)
            self.ftp_mode = ttk.Combobox(
                self.advanced_frame, state="readonly",
                values=("FTPS", "FTP"), textvariable=self.advanced_vars["ftp_mode"])
            self.ftp_mode.grid(row=3, column=1, sticky="ew", pady=3)
            self.ftp_warning = tk.BooleanVar(value=False)
            ttk.Checkbutton(
                self.advanced_frame,
                text="Понимаю: обычный FTP передаёт пароль и файлы без шифрования",
                variable=self.ftp_warning,
            ).grid(row=4, column=0, columnspan=2, sticky="w", pady=3)
            self.advanced_frame.grid_remove()
            row += 1

            clients = ttk.LabelFrame(outer, text="Клиенты Geometry Dash 2.209 (необязательно)",
                                     padding=9)
            clients.pack(fill="x", pady=(10, 8))
            self.exe_var = tk.StringVar()
            self.apk_var = tk.StringVar()
            self.file_field(clients, 0, "Windows EXE", self.exe_var, ".exe")
            self.file_field(clients, 1, "Android APK", self.apk_var, ".apk")
            self.rights = tk.BooleanVar(value=False)
            ttk.Checkbutton(
                clients,
                text="У меня есть разрешение на публичное распространение этих игровых файлов",
                variable=self.rights,
            ).grid(row=2, column=0, columnspan=3, sticky="w", pady=(7, 0))

            self.status = tk.StringVar(value="Готов к установке")
            ttk.Label(outer, textvariable=self.status, style="Sub.TLabel").pack(
                anchor="w", pady=(5, 4))
            self.log = tk.Text(
                outer, height=8, wrap="word", bg="#0d0c12", fg="#e8e2f0",
                insertbackground="#ffffff", relief="flat", font=("Consolas", 9),
                padx=9, pady=8,
            )
            self.log.pack(fill="both", expand=True)
            bottom = ttk.Frame(outer)
            bottom.pack(fill="x", pady=(10, 0))
            self.start_button = ttk.Button(
                bottom, text="УСТАНОВИТЬ MUCHOCORE", style="Accent.TButton",
                command=self.start,
            )
            self.start_button.pack(side="left")
            ttk.Button(bottom, text="Закрыть", command=self.on_close).pack(side="right")
            self.root.protocol("WM_DELETE_WINDOW", self.on_close)
            self.root.after(120, self.pump)
            self.write_log("Перед началом создай сайт и пустую базу в кабинете 5v.pl.")
            self.write_log("Пароли не сохраняются и не отправляются на muchogdps.space.")

        def open_recovery(self):
            window = tk.Toplevel(self.root)
            window.title("MuchoCore · Восстановление админки")
            window.geometry("590x590")
            window.transient(self.root)
            window.grab_set()
            frame = ttk.Frame(window, padding=16)
            frame.pack(fill="both", expand=True)
            ttk.Label(frame, text="Восстановление существующего владельца",
                      style="Title.TLabel").pack(anchor="w")
            ttk.Label(
                frame,
                text="База, игроки и уровни не удаляются. Нужен FTP-доступ к своему сайту.",
                style="Sub.TLabel", wraplength=540,
            ).pack(anchor="w", pady=(4, 12))
            values = {
                "site": self.vars["site"].get(),
                "host": self.vars["ftp_host"].get(),
                "user": self.vars["ftp_user"].get(),
                "ftp_password": self.vars["ftp_password"].get(),
                "owner": "",
                "new_password": "",
                "confirm": "",
            }
            variables = {key: tk.StringVar(value=value) for key, value in values.items()}
            labels = (
                ("site", "Адрес сайта"),
                ("host", "FTP-сервер"),
                ("user", "FTP-логин"),
                ("ftp_password", "FTP-пароль"),
                ("owner", "Логин владельца (пусто = авто, если владелец один)"),
                ("new_password", "Новый пароль (10–72 символа)"),
                ("confirm", "Повтори новый пароль"),
            )
            entries = {}
            for key, label in labels:
                line = ttk.Frame(frame)
                line.pack(fill="x", pady=4)
                ttk.Label(line, text=label, width=42).pack(side="left")
                entry = ttk.Entry(
                    line, textvariable=variables[key],
                    show="*" if "password" in key or key == "confirm" else "",
                )
                entry.pack(side="left", fill="x", expand=True)
                entries[key] = entry
            ttk.Label(
                frame,
                text="Используется FTPS. Временный обработчик автоматически удаляется после операции.",
                style="Sub.TLabel", wraplength=540,
            ).pack(anchor="w", pady=(6, 8))
            status = tk.StringVar(value="Готово")
            ttk.Label(frame, textvariable=status, style="Sub.TLabel").pack(anchor="w")
            output = tk.Text(
                frame, height=8, wrap="word", bg="#0d0c12", fg="#e8e2f0",
                relief="flat", font=("Consolas", 9), padx=8, pady=7,
            )
            output.pack(fill="both", expand=True, pady=(6, 8))
            events = queue.Queue()
            run_button = ttk.Button(frame, text="ВОССТАНОВИТЬ ДОСТУП")
            run_button.pack(anchor="w")

            def log(message):
                output.insert("end", str(message).rstrip() + "\n")
                output.see("end")

            def start_recovery():
                if run_button["state"] == "disabled":
                    return
                try:
                    origin = site_origin(variables["site"].get())
                    host = valid_ftp_host(variables["host"].get())
                    ftp_user = variables["user"].get().strip()
                    ftp_password = variables["ftp_password"].get()
                    owner = variables["owner"].get().strip()
                    new_password = variables["new_password"].get()
                    confirmation = variables["confirm"].get()
                    if not ftp_user or not ftp_password:
                        raise InstallError("Заполни FTP-логин и пароль.")
                    if owner and not re.fullmatch(r"[A-Za-z0-9_-]{1,20}", owner):
                        raise InstallError("Проверь логин существующего владельца.")
                    if not 10 <= len(new_password.encode("utf-8")) <= 72:
                        raise InstallError("Новый пароль должен быть длиной 10–72 байта.")
                    if new_password != confirmation:
                        raise InstallError("Пароли не совпадают.")
                except (InstallError, ValueError) as exc:
                    messagebox.showerror("Проверь данные", str(exc), parent=window)
                    return
                if not messagebox.askyesno(
                    "Подтвердить восстановление",
                    "Изменить пароль существующего владельца на " + origin +
                    "?\n\nВсе старые сессии, 2FA и passkeys будут отозваны. "
                    "Игровые данные сохраняются.",
                    parent=window,
                ):
                    return
                run_button.configure(state="disabled")
                self.recovery_active = True
                status.set("Восстановление выполняется…")
                log("Проверка FTP-доступа и временного обработчика…")

                def worker():
                    try:
                        with contextlib.redirect_stdout(QueueWriter(events)):
                            RECOVER_ADMIN_5V.recover(
                                origin, host, ftp_user, ftp_password,
                                owner, new_password, ftps=True, root_hint="",
                            )
                        events.put(("done", origin))
                    except Exception as exc:
                        events.put(("error", str(exc)))

                threading.Thread(target=worker, daemon=True).start()

            run_button.configure(command=start_recovery)

            def pump_recovery():
                try:
                    while True:
                        kind, value = events.get_nowait()
                        if kind == "log":
                            log(value)
                        elif kind == "error":
                            self.recovery_active = False
                            status.set("Восстановление остановлено")
                            log("ОШИБКА: " + str(value))
                            run_button.configure(state="normal")
                            messagebox.showerror("Не удалось восстановить", str(value), parent=window)
                        elif kind == "done":
                            self.recovery_active = False
                            status.set("Доступ восстановлен")
                            run_button.configure(state="normal")
                            for key in ("ftp_password", "new_password", "confirm"):
                                variables[key].set("")
                            messagebox.showinfo(
                                "Готово",
                                "Пароль существующего владельца изменён. "
                                "Войди в " + str(value) + "/mc/admin.php и включи 2FA заново.",
                                parent=window,
                            )
                except queue.Empty:
                    pass
                if window.winfo_exists():
                    window.after(120, pump_recovery)

            window.protocol(
                "WM_DELETE_WINDOW",
                lambda: messagebox.showwarning(
                    "Восстановление выполняется",
                    "Не закрывай это окно до завершения операции.",
                    parent=window,
                ) if run_button["state"] == "disabled" else window.destroy(),
            )
            window.after(120, pump_recovery)

        def file_field(self, parent, row, label, variable, extension):
            ttk.Label(parent, text=label).grid(row=row, column=0, sticky="w", padx=(0, 8), pady=3)
            ttk.Entry(parent, textvariable=variable).grid(row=row, column=1, sticky="ew", pady=3)
            ttk.Button(
                parent, text="Выбрать…",
                command=lambda: variable.set(filedialog.askopenfilename(
                    title="Выбери оригинальный " + extension,
                    filetypes=[(extension.upper(), "*" + extension), ("Все файлы", "*.*")],
                )),
            ).grid(row=row, column=2, padx=(7, 0), pady=3)
            parent.columnconfigure(1, weight=1)

        def toggle_advanced(self):
            self.advanced = not self.advanced
            if self.advanced:
                self.advanced_frame.grid()
                self.advanced_button.configure(text="Дополнительные настройки ▴")
            else:
                self.advanced_frame.grid_remove()
                self.advanced_button.configure(text="Дополнительные настройки ▾")

        def write_log(self, message):
            self.log.insert("end", message.rstrip() + "\n")
            self.log.see("end")

        def on_close(self):
            if self.recovery_active:
                messagebox.showwarning(
                    "Восстановление выполняется",
                    "Не закрывай мастер до завершения восстановления доступа.",
                    parent=self.root,
                )
                return
            if self.worker and self.worker.is_alive():
                messagebox.showwarning(
                    "Установка выполняется",
                    "Не закрывай мастер во время FTP-загрузки или миграции. "
                    "Дождись завершения, чтобы не прервать установку.",
                    parent=self.root,
                )
                return
            self.root.destroy()

        def build_config(self):
            site = site_origin(self.vars["site"].get())
            ftp_host = valid_ftp_host(self.vars["ftp_host"].get())
            ftp_user = self.vars["ftp_user"].get().strip()
            ftp_password = self.vars["ftp_password"].get()
            if not ftp_user or len(ftp_user) > 128 or not ftp_password:
                raise InstallError("Заполни FTP-логин и пароль из кабинета 5v.pl.")
            db_name = self.vars["db_name"].get().strip()
            db_user = self.vars["db_user"].get().strip()
            db_password = self.vars["db_password"].get()
            admin = self.vars["admin"].get().strip()
            email = self.vars["email"].get().strip()
            admin_password = self.vars["admin_password"].get()
            admin_confirm = self.vars["admin_confirm"].get()
            if not self.empty_db.get():
                raise InstallError("Подтверди, что база данных действительно пустая.")
            if not re.fullmatch(r"[A-Za-z0-9_-]{1,20}", admin):
                raise InstallError("Логин владельца: 1–20 латинских букв, цифр, _ или -.")
            if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
                raise InstallError("Укажи действующий e-mail владельца.")
            if not 10 <= len(admin_password.encode("utf-8")) <= 72:
                raise InstallError("Пароль владельца должен быть длиной 10–72 байта.")
            if admin_password != admin_confirm:
                raise InstallError("Пароли владельца не совпадают.")
            if not 8 <= len(db_password) <= 256:
                raise InstallError("Пароль MySQL должен быть длиной 8–256 символов.")
            mysqlhost = self.advanced_vars["mysqlhost"].get().strip() or "localhost"
            mysqlport = int(self.advanced_vars["mysqlport"].get())
            root = self.advanced_vars["root"].get().strip()
            mode = self.advanced_vars["ftp_mode"].get().upper()
            if mode not in ("FTPS", "FTP"):
                raise InstallError("Выбери FTPS или FTP.")
            if mode == "FTP" and not self.ftp_warning.get():
                raise InstallError("Подтверди предупреждение о незашифрованном FTP.")
            private_config(site, mysqlhost, mysqlport, db_name, db_user, db_password)
            return {
                "site": site, "host": ftp_host, "user": ftp_user,
                "password": ftp_password, "secure": mode == "FTPS",
                "root": root, "mysqlhost": mysqlhost, "mysqlport": mysqlport,
                "dbname": db_name, "dbuser": db_user, "dbpassword": db_password,
                "admin": admin, "email": email, "adminpassword": admin_password,
            }

        def start(self):
            if self.worker and self.worker.is_alive():
                return
            try:
                cfg = self.build_config()
            except (InstallError, OSError, ValueError) as exc:
                messagebox.showerror("Проверь данные", str(exc), parent=self.root)
                return
            try:
                exe = detect_client(".exe", self.exe_var.get().strip())
            except (InstallError, OSError, ValueError) as exc:
                exe = None
                self.write_log("EXE пропущен: " + str(exc))
            try:
                apk = detect_client(".apk", self.apk_var.get().strip())
            except (InstallError, OSError, ValueError) as exc:
                apk = None
                self.write_log("APK пропущен: " + str(exc))
            summary = (
                "Будет установлен новый MuchoCore на:\n" + cfg["site"] +
                "\n\nСуществующая папка mc/ не будет перезаписана."
                "\nПароли останутся только в памяти этого приложения."
            )
            if not messagebox.askyesno("Подтверждение установки", summary,
                                       parent=self.root):
                return
            cfg["_exe_source"] = str(exe) if exe else ""
            cfg["_apk_source"] = str(apk) if apk else ""
            cfg["_redistribution_rights"] = bool(self.rights.get())
            self.start_button.configure(state="disabled")
            self.status.set("Установка выполняется…")
            self.write_log("Проверка настроек и подключение к 5v.pl…")
            self.worker = threading.Thread(
                target=self.run_worker, args=(cfg,), daemon=True)
            self.worker.start()

        def run_worker(self, cfg):
            writer = QueueWriter(self.events)
            try:
                with contextlib.redirect_stdout(writer):
                    self.events.put(("status", "Загрузка и настройка MuchoCore…"))
                    INSTALL_5V.perform_install(cfg, prepare_clients=False)
                    self.events.put(("log", "Ядро и админка установлены."))
                    exe = Path(cfg["_exe_source"]) if cfg.get("_exe_source") else None
                    apk = Path(cfg["_apk_source"]) if cfg.get("_apk_source") else None
                    if not exe and not apk:
                        self.events.put(("log", "Исходники игры не найдены — этап клиентов пропущен."))
                    else:
                        self.events.put(("status", "Подготовка игровых клиентов…"))
                        try:
                            links = prepare_clients(
                                cfg, exe, apk, cfg["_redistribution_rights"],
                                log=lambda value: self.events.put(("log", str(value))),
                            )
                            if links:
                                self.events.put(("log", "Публичные ссылки созданы."))
                            elif not cfg["_redistribution_rights"]:
                                self.events.put(("log", "Клиенты не опубликованы: нет подтверждения прав."))
                        except Exception as client_error:
                            self.events.put(("log", "Ядро и админка уже установлены. "
                                                      "Проблема только на этапе клиентов: "
                                                      + str(client_error)))
                            self.events.put(("log", "Клиенты можно подготовить позже через админку "
                                                      "или отдельный запуск мастера."))
                    self.events.put(("done", cfg["site"]))
            except Exception as exc:
                self.events.put(("error", str(exc)))
                self.events.put(("log", traceback.format_exc(limit=4)))
            finally:
                # Drop references to credentials after the worker is complete.
                for key in ("password", "dbpassword", "adminpassword"):
                    cfg[key] = ""

        def pump(self):
            try:
                while True:
                    kind, value = self.events.get_nowait()
                    if kind == "log":
                        self.write_log(str(value))
                    elif kind == "status":
                        self.status.set(str(value))
                    elif kind == "error":
                        self.status.set("Установка завершилась с ошибкой")
                        self.write_log("ОШИБКА: " + str(value))
                        self.start_button.configure(state="normal")
                        messagebox.showerror(
                            "Установка остановлена",
                            str(value) + "\n\nНе удаляй папку mc/ и базу. "
                            "Отчёт и состояние сайта нужно проверить перед повторным запуском.",
                            parent=self.root,
                        )
                    elif kind == "done":
                        self.status.set("Готово · " + str(value))
                        self.start_button.configure(state="normal")
                        self.write_log("Установка завершена. Админка: " + str(value) + "/mc/admin.php")
                        messagebox.showinfo(
                            "MuchoCore установлен",
                            "Ядро установлено.\nАдминка: " + str(value) +
                            "/mc/admin.php\n\nПроверь клиент в реальной Geometry Dash 2.209.",
                            parent=self.root,
                        )
            except queue.Empty:
                pass
            self.root.after(120, self.pump)

    root = tk.Tk()
    InstallerWindow(root)
    root.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
