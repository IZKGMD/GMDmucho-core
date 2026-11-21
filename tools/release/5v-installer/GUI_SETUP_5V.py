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
from mucho5v import FtpSite, FtpTlsUnavailable, HttpsSite, InstallError, private_config, site_origin, valid_ftp_host
from PUBLISH_CLIENTS_5V import login_owner
from muchoclients5v import (
    patch_windows, patch_android, download_android_private,
    publish_windows, publish_android, publish_index, read_source,
    save_links, cleanup_published_job,
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
        BUNDLE_DIR / "DATABASE_CHECK.php.tpl",
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
                raise InstallError("Setup could not recover the verified FTP folder.")
        else:
            ftp = None

        if exe_source:
            try:
                output, report = patch_windows(exe_source, cfg["site"], OUTDIR)
                log("Windows EXE patched and verified locally: " + output.name)
                if redistribution_rights:
                    url = publish_windows(ftp, site, output, report)
                    links["exe"] = {
                        "url": url, "sha256": report["sha256"],
                        "size": report["size"],
                    }
                    log("Windows link: " + url)
                else:
                    log("The EXE was saved locally for personal use.")
            except (InstallError, OSError, ValueError) as exc:
                log("EXE: " + str(exc))

        if apk_source:
            try:
                log("Signing in as the owner to process the APK…")
                login_owner(site, cfg["admin"], cfg["adminpassword"])
                report = patch_android(
                    site, apk_source, cfg["site"],
                    alongside=apk_source.stat().st_size == 174_568_364,
                    progress=log,
                )
                if redistribution_rights:
                    url = publish_android(ftp, site, report, OUTDIR)
                    links["apk"] = {
                        "url": url, "sha256": report["output_sha256"],
                        "size": report["output_size"],
                        "certificate_sha256": report.get("certificate_sha256"),
                    }
                    log("Android link: " + url)
                else:
                    local_apk = download_android_private(site, report, OUTDIR)
                    log("Android APK patched, signed, and saved locally: "
                        + str(local_apk))
                    log("The APK was saved locally for personal use.")
                try:
                    if cleanup_published_job(site, report["job_id"]):
                        log("Temporary APK files removed. The signing key was kept.")
                except InstallError as exc:
                    log("Some temporary APK files could not be removed: " + str(exc))
                log("Back up the private mc/config/php7-apk-signer.json file.")
            except (InstallError, OSError, ValueError) as exc:
                log("APK: " + str(exc))

        if links:
            try:
                page = publish_index(ftp, site, links)
                log("Client downloads page: " + page)
            except (InstallError, OSError) as exc:
                log("The links are ready. The downloads page could not be updated: " + str(exc))
            try:
                saved = save_links(OUTDIR, cfg["site"], links)
                log("Links and SHA-256 hashes saved to: " + str(saved))
            except OSError as exc:
                log("Could not save the local download links: " + str(exc))
    finally:
        if ftp_context is not None:
            ftp_context.__exit__(None, None, None)
    return links


class InstallerWindow:
    def __init__(self, root):
        self.root = root
        self.events = queue.Queue()
        self.worker = None
        self.recovery_active = False
        self.advanced = False
        self.root.title("MuchoCore Setup for 5v.pl")
        self.root.geometry("760x690")
        self.root.minsize(680, 600)
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
        style.configure("TEntry", padding=6, fieldbackground="#25212e", foreground="#eeeaf5")
        style.configure("TCheckbutton", background="#17151f", foreground="#eeeaf5")
        style.map("TCheckbutton", background=[("active", "#25212e")])
        style.configure("TNotebook", background="#17151f", borderwidth=0)
        style.configure("TNotebook.Tab", padding=(14, 8), background="#25212e", foreground="#c6bfd2")
        style.map("TNotebook.Tab", background=[("selected", "#7652ad")], foreground=[("selected", "#ffffff")])
        style.configure("TLabelframe", background="#17151f",
                        foreground="#eeeaf5")
        style.configure("TLabelframe.Label", background="#17151f",
                        foreground="#cbb6ec", font=("Segoe UI", 9, "bold"))

        outer = ttk.Frame(root, padding=18)
        outer.pack(fill="both", expand=True)
        ttk.Label(outer, text="MuchoCore", style="Title.TLabel").pack(anchor="w")
        ttk.Label(
            outer,
            text="Set up a Geometry Dash server on 5v.pl",
            style="Sub.TLabel",
        ).pack(anchor="w", pady=(2, 12))

        top = ttk.Frame(outer)
        top.pack(fill="x", pady=(0, 10))
        ttk.Button(top, text="Open 5v.pl",
                   command=lambda: webbrowser.open("https://5v.pl/")).pack(side="left")
        ttk.Button(top, text="Find my FTP details",
                   command=lambda: webbrowser.open(
                       "https://5v.pl/pomoc,pytanie,serwer-ftp-zapomnialem-nazwy-hosta-lub-moj-unikalny-login.html"
                   )).pack(side="left", padx=7)
        ttk.Button(top, text="Recover admin access",
                   command=self.open_recovery).pack(side="right")

        self.vars = {}
        self.entries = {}
        self.steps = ttk.Notebook(outer)
        self.steps.pack(fill="both", expand=True, pady=(0, 8))
        self.step_frames = []
        self.step_canvases = []
        for title in ("1. Website", "2. Database", "3. Administrator"):
            page = ttk.Frame(self.steps)
            self.steps.add(page, text=title)
            canvas = tk.Canvas(page, background="#17151f", highlightthickness=0)
            scrollbar = ttk.Scrollbar(page, orient="vertical", command=canvas.yview)
            canvas.configure(yscrollcommand=scrollbar.set)
            scrollbar.pack(side="right", fill="y")
            canvas.pack(side="left", fill="both", expand=True)
            panel = ttk.Frame(canvas, padding=10)
            panel.columnconfigure(1, weight=1)
            window = canvas.create_window((0, 0), window=panel, anchor="nw")
            canvas.bind("<Configure>", lambda event, c=canvas, w=window: c.itemconfigure(w, width=event.width))
            panel.bind("<Configure>", lambda event, c=canvas: c.configure(scrollregion=c.bbox("all")))
            self.step_frames.append(panel)
            self.step_canvases.append(canvas)
        self.steps.bind("<<NotebookTabChanged>>", self.step_changed)

        def field(parent, row, key, label, placeholder="", secret=False):
            ttk.Label(parent, text=label).grid(row=row, column=0, sticky="w",
                                               padx=(0, 12), pady=4)
            var = tk.StringVar(value=placeholder)
            entry = ttk.Entry(parent, textvariable=var, show="*" if secret else "")
            entry.grid(row=row, column=1, sticky="ew", pady=4)
            self.vars[key] = var
            self.entries[key] = entry
            return entry

        form = self.step_frames[0]
        ttk.Label(form, text="Use the FTP server and unique FTP username shown next to your site in the 5v.pl dashboard.",
                  style="Sub.TLabel", wraplength=580).grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 8))
        field(form, 1, "site", "Website (name.5v.pl)")
        field(form, 2, "ftp_host", "FTP server")
        field(form, 3, "ftp_user", "FTP username")
        field(form, 4, "ftp_password", "FTP password", secret=True)
        row = 5
        self.advanced_button = ttk.Button(
            form, text="Advanced settings ▾",
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
            ("root", "FTP folder (blank for automatic)"),
        )):
            ttk.Label(self.advanced_frame, text=label).grid(
                row=n, column=0, sticky="w", padx=(0, 12), pady=3)
            ttk.Entry(self.advanced_frame,
                      textvariable=self.advanced_vars[key]).grid(
                          row=n, column=1, sticky="ew", pady=3)
        ttk.Label(self.advanced_frame, text="FTP encryption").grid(
            row=3, column=0, sticky="w", padx=(0, 12), pady=3)
        self.ftp_mode = ttk.Combobox(
            self.advanced_frame, state="readonly",
            values=("FTPS", "FTP"), textvariable=self.advanced_vars["ftp_mode"])
        self.ftp_mode.grid(row=3, column=1, sticky="ew", pady=3)
        self.ftp_warning = tk.BooleanVar(value=False)
        ttk.Checkbutton(
            self.advanced_frame,
            text="I understand that plain FTP sends my password and files without encryption",
            variable=self.ftp_warning,
        ).grid(row=4, column=0, columnspan=2, sticky="w", pady=3)
        self.advanced_frame.grid_remove()
        row += 1

        form = self.step_frames[1]
        ttk.Label(form, text="Create an empty MySQL database in the 5v.pl dashboard and enter its details here.",
                  style="Sub.TLabel", wraplength=600).grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 8))
        field(form, 1, "db_name", "Empty database name")
        field(form, 2, "db_user", "Database username")
        field(form, 3, "db_password", "Database password", secret=True)
        self.empty_db = tk.BooleanVar(value=False)
        ttk.Checkbutton(form, text="I have an empty database or am resuming this installation with the same database",
                        variable=self.empty_db).grid(row=4, column=0, columnspan=2, sticky="w", pady=8)
        ttk.Label(form, text="The default MySQL address is localhost:3306. Change it in Advanced settings on the Website tab.",
                  style="Sub.TLabel", wraplength=600).grid(row=5, column=0, columnspan=2, sticky="w")

        form = self.step_frames[2]
        ttk.Label(form, text="Choose credentials for the owner of your Geometry Dash server.",
                  style="Sub.TLabel").grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 8))
        field(form, 1, "admin", "Admin username", "admin")
        field(form, 2, "email", "E-mail")
        field(form, 3, "admin_password", "Password (10–72 bytes)", secret=True)
        field(form, 4, "admin_confirm", "Confirm password", secret=True)
        self.clients_button = ttk.Button(form, text="Prepare game clients (optional) ▾", command=self.toggle_clients)
        self.clients_button.grid(row=5, column=0, columnspan=2, sticky="w", pady=8)
        self.clients_frame = ttk.Frame(form)
        self.clients_frame.grid(row=6, column=0, columnspan=2, sticky="ew")
        self.exe_var = tk.StringVar()
        self.apk_var = tk.StringVar()
        self.file_field(self.clients_frame, 0, "Windows EXE", self.exe_var, ".exe")
        self.file_field(self.clients_frame, 1, "Android APK", self.apk_var, ".apk")
        self.rights = tk.BooleanVar(value=False)
        ttk.Checkbutton(self.clients_frame,
            text="I have permission to redistribute the game files publicly",
            variable=self.rights).grid(row=2, column=0, columnspan=3, sticky="w", pady=7)
        self.clients_frame.grid_remove()

        self.status = tk.StringVar(value="Ready to install")
        ttk.Label(outer, textvariable=self.status, style="Sub.TLabel").pack(
            anchor="w", pady=(5, 4), before=self.steps)
        self.details_button = ttk.Button(outer, text="Details ▾", command=self.toggle_log)
        self.details_button.pack(anchor="w", pady=(0, 4), before=self.steps)
        self.log = tk.Text(
            outer, height=6, wrap="word", bg="#0d0c12", fg="#e8e2f0",
            insertbackground="#ffffff", relief="flat", font=("Consolas", 9),
            padx=9, pady=8,
        )
        bottom = self.bottom = ttk.Frame(outer)
        bottom.pack(side="bottom", fill="x", pady=(10, 0), before=self.steps)
        self.back_button = ttk.Button(bottom, text="Back", command=self.previous_step)
        self.back_button.pack(side="left")
        self.next_button = ttk.Button(bottom, text="Check website and continue", style="Accent.TButton", command=self.next_step)
        self.next_button.pack(side="left", padx=8)
        self.start_button = ttk.Button(bottom, text="Install MuchoCore", style="Accent.TButton", command=self.start)
        self.open_admin_button = ttk.Button(bottom, text="Open admin panel", command=lambda: webbrowser.open(self.installed_site + "/mc/admin.php"))
        ttk.Button(bottom, text="Close", command=self.on_close).pack(side="right")
        self.step_changed()
        self.root.protocol("WM_DELETE_WINDOW", self.on_close)
        self.root.after(120, self.pump)
        self.write_log("Create your site and an empty database in the 5v.pl dashboard before starting.")
        self.write_log("Setup keeps your passwords in memory and connects directly to your hosting provider.")

    def toggle_log(self):
        if self.log.winfo_ismapped():
            self.log.pack_forget()
            self.details_button.configure(text="Details ▾")
        else:
            self.log.pack(fill="x", before=self.steps)
            self.details_button.configure(text="Details ▴")

    def toggle_clients(self):
        if self.clients_frame.winfo_ismapped():
            self.clients_frame.grid_remove()
            self.clients_button.configure(text="Prepare game clients (optional) ▾")
        else:
            self.clients_frame.grid()
            self.clients_button.configure(text="Prepare game clients (optional) ▴")

    def step_changed(self, _event=None):
        if not hasattr(self, "next_button"):
            return
        index = self.steps.index(self.steps.select())
        self.back_button.configure(state="disabled" if index == 0 else "normal")
        if index == 2:
            self.next_button.pack_forget()
            if not getattr(self, "installed_site", None):
                self.start_button.pack(side="left", padx=8)
        else:
            self.start_button.pack_forget()
            self.next_button.pack(side="left", padx=8)
            self.next_button.configure(text="Check website and continue" if index == 0 else "Next")

    def previous_step(self):
        if not (self.worker and self.worker.is_alive()):
            self.steps.select(max(0, self.steps.index(self.steps.select()) - 1))

    def connection_config(self):
        site = site_origin(self.vars["site"].get())
        host = valid_ftp_host(self.vars["ftp_host"].get())
        user = self.vars["ftp_user"].get().strip()
        password = self.vars["ftp_password"].get()
        if not user or len(user) > 128 or not password:
            raise InstallError("Enter the FTP username and password from your 5v.pl dashboard.")
        mode = self.advanced_vars["ftp_mode"].get().upper()
        if mode not in ("FTPS", "FTP"):
            raise InstallError("Choose FTPS or plain FTP.")
        if mode == "FTP" and not self.ftp_warning.get():
            raise InstallError("Confirm that you understand the risks of unencrypted FTP.")
        return dict(site=site, host=host, user=user, password=password,
                    secure=mode == "FTPS", root=self.advanced_vars["root"].get().strip())

    def validate_database(self):
        if not self.empty_db.get():
            raise InstallError("Confirm that the database is empty or belongs to this unfinished installation.")
        private_config(site_origin(self.vars["site"].get()),
                       self.advanced_vars["mysqlhost"].get().strip() or "localhost",
                       int(self.advanced_vars["mysqlport"].get()),
                       self.vars["db_name"].get().strip(), self.vars["db_user"].get().strip(),
                       self.vars["db_password"].get())

    def next_step(self):
        if self.worker and self.worker.is_alive():
            return
        index = self.steps.index(self.steps.select())
        try:
            if index == 0:
                cfg = self.connection_config()
                self.start_connection_check(cfg)
            else:
                self.validate_database()
                self.steps.select(2)
        except (InstallError, OSError, ValueError) as exc:
            messagebox.showerror("Check your details", str(exc), parent=self.root)

    def start_connection_check(self, cfg):
        self.next_button.configure(state="disabled")
        self.status.set("Checking FTP and website access…")
        self.worker = threading.Thread(target=self.check_connection, args=(cfg,), daemon=True)
        self.worker.start()

    def check_connection(self, cfg):
        try:
            with FtpSite(cfg["host"], cfg["user"], cfg["password"], secure=cfg["secure"]) as ftp:
                ftp.find_root(cfg["site"], cfg["root"])
            self.events.put(("connection_ok", cfg["site"]))
        except FtpTlsUnavailable as exc:
            self.events.put(("ftps_unavailable", (cfg["host"], str(exc))))
        except Exception as exc:
            self.events.put(("connection_error", str(exc)))
        finally:
            cfg["password"] = ""

    def retry_plain_ftp(self, host, error):
        self.next_button.configure(state="normal")
        self.status.set("The FTP server does not support explicit FTPS")
        if self.vars["ftp_host"].get().strip().lower() != host:
            self.status.set("FTP server changed. Check the website again.")
            return
        if not messagebox.askyesno(
            "Retry with plain FTP?",
            error + "\n\nPlain FTP sends your FTP username, password, and files without encryption. "
            "Use plain FTP for this installation on " + host + "?",
            parent=self.root,
        ):
            return
        self.advanced_vars["ftp_mode"].set("FTP")
        self.ftp_warning.set(True)
        try:
            self.start_connection_check(self.connection_config())
        except InstallError as exc:
            messagebox.showerror("Check your details", str(exc), parent=self.root)

    def open_recovery(self):
        if self.worker and self.worker.is_alive():
            messagebox.showinfo("Setup is busy", "Wait for the current check or installation to finish.", parent=self.root)
            return
        window = tk.Toplevel(self.root)
        window.title("MuchoCore Admin Recovery")
        window.geometry("590x590")
        window.transient(self.root)
        window.grab_set()
        frame = ttk.Frame(window, padding=16)
        frame.pack(fill="both", expand=True)
        ttk.Label(frame, text="Recover owner access",
                  style="Title.TLabel").pack(anchor="w")
        ttk.Label(
            frame,
            text="Recovery requires FTP access to your site and preserves your database, players, and levels.",
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
            ("site", "Website"),
            ("host", "FTP server"),
            ("user", "FTP username"),
            ("ftp_password", "FTP password"),
            ("owner", "Owner username (blank if there is only one)"),
            ("new_password", "New password (10–72 bytes)"),
            ("confirm", "Confirm new password"),
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
        plain_ftp = tk.BooleanVar(value=False)
        ttk.Checkbutton(
            frame,
            text="Use plain FTP (sends my FTP password and files without encryption)",
            variable=plain_ftp,
        ).pack(anchor="w", pady=(6, 8))
        status = tk.StringVar(value="Ready")
        ttk.Label(frame, textvariable=status, style="Sub.TLabel").pack(anchor="w")
        output = tk.Text(
            frame, height=8, wrap="word", bg="#0d0c12", fg="#e8e2f0",
            relief="flat", font=("Consolas", 9), padx=8, pady=7,
        )
        output.pack(fill="both", expand=True, pady=(6, 8))
        events = queue.Queue()
        run_button = ttk.Button(frame, text="Recover access")
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
                secure = not plain_ftp.get()
                if not ftp_user or not ftp_password:
                    raise InstallError("Enter your FTP username and password.")
                if owner and not re.fullmatch(r"[A-Za-z0-9_-]{1,20}", owner):
                    raise InstallError("Check the existing owner's username.")
                if not 10 <= len(new_password.encode("utf-8")) <= 72:
                    raise InstallError("The new password must be 10–72 bytes.")
                if new_password != confirmation:
                    raise InstallError("The passwords do not match.")
            except (InstallError, ValueError) as exc:
                messagebox.showerror("Check your details", str(exc), parent=window)
                return
            if not messagebox.askyesno(
                "Confirm recovery",
                "Reset the existing owner's password on " + origin +
                "?\n\nThis revokes existing sessions, two-factor credentials, and passkeys. "
                "Your game data is preserved.",
                parent=window,
            ):
                return
            run_button.configure(state="disabled")
            self.recovery_active = True
            status.set("Recovering access…")
            log("Verifying FTP access and the temporary recovery script…")

            def worker():
                try:
                    with contextlib.redirect_stdout(QueueWriter(events)):
                        RECOVER_ADMIN_5V.recover(
                            origin, host, ftp_user, ftp_password,
                            owner, new_password, ftps=secure, root_hint="",
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
                        status.set("Recovery stopped")
                        log("Error: " + str(value))
                        run_button.configure(state="normal")
                        messagebox.showerror("Recovery failed", str(value), parent=window)
                    elif kind == "done":
                        self.recovery_active = False
                        status.set("Access restored")
                        run_button.configure(state="normal")
                        for key in ("ftp_password", "new_password", "confirm"):
                            variables[key].set("")
                        messagebox.showinfo(
                            "Ready",
                            "The owner's password has been reset. "
                            "Sign in at " + str(value) + "/mc/admin.php and set up two-factor authentication again.",
                            parent=window,
                        )
            except queue.Empty:
                pass
            if window.winfo_exists():
                window.after(120, pump_recovery)

        window.protocol(
            "WM_DELETE_WINDOW",
            lambda: messagebox.showwarning(
                "Recovery in progress",
                "Keep this window open until recovery finishes.",
                parent=window,
            ) if run_button["state"] == "disabled" else window.destroy(),
        )
        window.after(120, pump_recovery)

    def file_field(self, parent, row, label, variable, extension):
        ttk.Label(parent, text=label).grid(row=row, column=0, sticky="w", padx=(0, 8), pady=3)
        ttk.Entry(parent, textvariable=variable).grid(row=row, column=1, sticky="ew", pady=3)
        ttk.Button(
            parent, text="Browse…",
            command=lambda: variable.set(filedialog.askopenfilename(
                title="Select the original " + extension,
                filetypes=[(extension.upper(), "*" + extension), ("All files", "*.*")],
            )),
        ).grid(row=row, column=2, padx=(7, 0), pady=3)
        parent.columnconfigure(1, weight=1)

    def toggle_advanced(self):
        self.advanced = not self.advanced
        if self.advanced:
            self.advanced_frame.grid()
            self.advanced_button.configure(text="Advanced settings ▴")
        else:
            self.advanced_frame.grid_remove()
            self.advanced_button.configure(text="Advanced settings ▾")

    def write_log(self, message):
        self.log.insert("end", message.rstrip() + "\n")
        self.log.see("end")

    def on_close(self):
        if self.recovery_active:
            messagebox.showwarning(
                "Recovery in progress",
                "Keep Setup open until it finishes recovering access.",
                parent=self.root,
            )
            return
        if self.worker and self.worker.is_alive():
            messagebox.showwarning(
                "Installation in progress",
                "Keep Setup open while it uploads files or migrates the database. "
                "Wait for the current step to finish.",
                parent=self.root,
            )
            return
        self.root.destroy()

    def build_config(self):
        cfg = self.connection_config()
        self.validate_database()
        db_name = self.vars["db_name"].get().strip()
        db_user = self.vars["db_user"].get().strip()
        db_password = self.vars["db_password"].get()
        admin = self.vars["admin"].get().strip()
        email = self.vars["email"].get().strip()
        admin_password = self.vars["admin_password"].get()
        admin_confirm = self.vars["admin_confirm"].get()
        if not re.fullmatch(r"[A-Za-z0-9_-]{1,20}", admin):
            raise InstallError("The owner username must contain 1–20 letters (A–Z), digits, underscores, or hyphens.")
        if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
            raise InstallError("Enter a valid email address for the owner.")
        if not 10 <= len(admin_password.encode("utf-8")) <= 72:
            raise InstallError("The owner's password must be 10–72 bytes.")
        if admin_password != admin_confirm:
            raise InstallError("The owner's passwords do not match.")
        mysqlhost = self.advanced_vars["mysqlhost"].get().strip() or "localhost"
        mysqlport = int(self.advanced_vars["mysqlport"].get())
        return {
            **cfg, "mysqlhost": mysqlhost, "mysqlport": mysqlport,
            "dbname": db_name, "dbuser": db_user, "dbpassword": db_password,
            "admin": admin, "email": email, "adminpassword": admin_password,
        }

    def start(self):
        if self.recovery_active:
            return
        if self.worker and self.worker.is_alive():
            return
        try:
            cfg = self.build_config()
        except (InstallError, OSError, ValueError) as exc:
            messagebox.showerror("Check your details", str(exc), parent=self.root)
            return
        try:
            exe = detect_client(".exe", self.exe_var.get().strip())
        except (InstallError, OSError, ValueError) as exc:
            exe = None
            self.write_log("Skipped EXE: " + str(exc))
        try:
            apk = detect_client(".apk", self.apk_var.get().strip())
        except (InstallError, OSError, ValueError) as exc:
            apk = None
            self.write_log("Skipped APK: " + str(exc))
        cfg["_exe_source"] = str(exe) if exe else ""
        cfg["_apk_source"] = str(apk) if apk else ""
        cfg["_redistribution_rights"] = bool(self.rights.get())
        self.start_button.configure(state="disabled")
        self.status.set("Installing MuchoCore…")
        self.write_log("Checking settings and connecting to 5v.pl…")
        self.worker = threading.Thread(
            target=self.run_worker, args=(cfg,), daemon=True)
        self.worker.start()

    def run_worker(self, cfg):
        writer = QueueWriter(self.events)
        try:
            with contextlib.redirect_stdout(writer):
                self.events.put(("status", "Uploading and configuring MuchoCore…"))
                INSTALL_5V.perform_install(cfg, prepare_clients=False)
                self.events.put(("log", "Core and admin panel installed."))
                exe = Path(cfg["_exe_source"]) if cfg.get("_exe_source") else None
                apk = Path(cfg["_apk_source"]) if cfg.get("_apk_source") else None
                if not exe and not apk:
                    self.events.put(("log", "Setup found no original game clients to patch."))
                else:
                    self.events.put(("status", "Preparing game clients…"))
                    try:
                        links = prepare_clients(
                            cfg, exe, apk, cfg["_redistribution_rights"],
                            log=lambda value: self.events.put(("log", str(value))),
                        )
                        if links:
                            self.events.put(("log", "Public download links are ready."))
                        elif not cfg["_redistribution_rights"]:
                            self.events.put(("log", "Patched clients are saved locally."))
                    except Exception as client_error:
                        self.events.put(("log", "The core and admin panel are installed. "
                                                  "Client preparation failed: "
                                                  + str(client_error)))
                        self.events.put(("log", "Prepare clients later in the admin panel "
                                                  "or by running Setup again."))
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
                if kind == "connection_ok":
                    self.next_button.configure(state="normal")
                    self.status.set("Website and FTP access verified")
                    self.steps.select(1)
                elif kind == "ftps_unavailable":
                    self.retry_plain_ftp(*value)
                elif kind == "connection_error":
                    self.next_button.configure(state="normal")
                    self.status.set("Connection check failed")
                    messagebox.showerror("Check FTP and website access", str(value), parent=self.root)
                elif kind == "log":
                    self.write_log(str(value))
                    if re.match(r"^\[[1-5]/5\]", str(value)):
                        self.status.set(str(value))
                elif kind == "status":
                    self.status.set(str(value))
                elif kind == "error":
                    self.status.set("Installation failed")
                    self.write_log("Error: " + str(value))
                    self.start_button.configure(state="normal")
                    messagebox.showerror(
                        "Installation stopped",
                        str(value) + "\n\nKeep the mc/ folder and database. "
                        "Fix the error, then click Install MuchoCore again to resume.",
                        parent=self.root,
                    )
                elif kind == "done":
                    self.status.set("Installed on " + str(value))
                    self.installed_site = str(value)
                    self.start_button.pack_forget()
                    self.next_button.pack_forget()
                    self.back_button.pack_forget()
                    self.open_admin_button.pack(side="left")
                    for key in ("ftp_password", "db_password", "admin_password", "admin_confirm"):
                        self.vars[key].set("")
                    self.write_log("Installation complete. Admin panel: " + str(value) + "/mc/admin.php")
                    messagebox.showinfo(
                        "MuchoCore installed",
                        "The core is ready.\nAdmin panel: " + str(value) +
                        "/mc/admin.php\n\nYou can now test your client in Geometry Dash 2.209.",
                        parent=self.root,
                    )
        except queue.Empty:
            pass
        self.root.after(120, self.pump)



def _ui_self_test() -> int:
    """Exercise the actual Windows wizard offline, including a small screen."""
    root = tk.Tk()
    try:
        window = InstallerWindow(root)
        root.geometry("680x600")
        root.update()
        expected_fields = [
            {"site", "ftp_host", "ftp_user", "ftp_password"},
            {"db_name", "db_user", "db_password"},
            {"admin", "email", "admin_password", "admin_confirm"},
        ]
        for index, expected in enumerate(expected_fields):
            window.steps.select(index)
            if index == 0:
                window.toggle_advanced()
            if index == 2:
                window.toggle_clients()
            root.update()
            visible = {key for key, entry in window.entries.items() if entry.winfo_viewable()}
            if visible != expected:
                return 6
            button = window.start_button if index == 2 else window.next_button
            if not button.winfo_viewable() or button.winfo_rooty() + button.winfo_height() > root.winfo_rooty() + root.winfo_height():
                return 7
        return 0
    finally:
        root.destroy()


def main() -> int:
    if "--self-test" in sys.argv:
        return _self_test()
    if "--ui-self-test" in sys.argv:
        return _ui_self_test()

    root = tk.Tk()
    InstallerWindow(root)
    root.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
