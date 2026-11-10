#!/usr/bin/env python3
"""Real first-run/admin smoke test through the disposable PHP 8 HTTP stack.

Requires the dedicated testgdps tenant and a local-only Caddy listener. Never
runs against a user-owned/production database or a remote server.
"""

import http.cookiejar
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:18080"
COMPOSE = [
    "docker", "compose",
    "-f", "docker-compose.test.yml",
    "-f", "docker-compose.test-acceptance.yml",
]


def check(condition, label):
    if not condition:
        raise AssertionError("PHP 8 first-run acceptance failed: " + label)
    print("PASS " + label, flush=True)


def safe_run(args, extra_env=None, timeout=90):
    env = os.environ.copy()
    if extra_env:
        env.update(extra_env)
    result = subprocess.run(
        args, cwd=ROOT, env=env, text=True, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, timeout=timeout, check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(
            "First-run check command failed (exit %s): %s" %
            (result.returncode, " ".join(args[:5]))
        )
    return result.stdout.strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class Browser:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
            NoRedirect(),
        )

    def get(self, path="/admin/"):
        return self.request(path)

    def post(self, fields, path="/admin/"):
        return self.request(path, fields)

    def request(self, path, fields=None):
        if not path.startswith("/") or path.startswith("//"):
            raise ValueError("Only relative local paths are allowed")
        data = None
        if fields is not None:
            data = urllib.parse.urlencode(fields).encode("utf-8")
        req = urllib.request.Request(
            BASE + path, data=data,
            headers={"Host": "localhost"},
            method="POST" if data is not None else "GET",
        )
        try:
            response = self.opener.open(req, timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return (
                response.status,
                response.read().decode("utf-8", errors="replace"),
                dict(response.headers.items()),
            )


class CsrfInput(HTMLParser):
    def __init__(self):
        super().__init__()
        self.token = None

    def handle_starttag(self, tag, attrs):
        if tag != "input":
            return
        data = dict(attrs)
        if data.get("name") == "csrf":
            self.token = data.get("value")


def csrf_from(html):
    parser = CsrfInput()
    parser.feed(html)
    if not parser.token or len(parser.token) < 40:
        raise AssertionError("Missing first-run CSRF token")
    return parser.token


DB_STATE_PHP = r"""
require 'vendor/autoload.php';
$db = (new \MuchoCore\Database\Database())->connection();
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    throw new RuntimeException('Refusing non-test database');
}
$users = $db->query('SELECT username,role,password_hash FROM admin_users ORDER BY id LIMIT 2')
            ->fetchAll(PDO::FETCH_ASSOC);
$sealed = (int)$db->query('SELECT COUNT(*) FROM admin_initialization WHERE id=1')
                 ->fetchColumn();
$row = $users[0] ?? null;
echo json_encode([
    'count' => (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn(),
    'username' => is_array($row) ? $row['username'] : null,
    'role' => is_array($row) ? $row['role'] : null,
    'sealed' => $sealed,
    'password_ok' => is_array($row) && password_verify(
        (string)getenv('MUCHO_TEST_OWNER_PASSWORD'), (string)$row['password_hash']
    ),
    'password_fingerprint' => is_array($row) ? hash('sha256', $row['password_hash']) : null,
], JSON_THROW_ON_ERROR);
"""


def db_state(password):
    output = safe_run(
        ["php", "-r", DB_STATE_PHP],
        {"MUCHO_TEST_OWNER_PASSWORD": password},
        timeout=20,
    )
    return json.loads(output)


def cloud_key_fingerprint():
    output = safe_run(
        COMPOSE + ["exec", "-T", "testgdps-app", "sha256sum",
                   "/var/lib/muchocore/cloudsave.key"],
        timeout=25,
    )
    value = output.split()[0] if output else ""
    if len(value) != 64 or any(c not in "0123456789abcdef" for c in value):
        raise AssertionError("Cloud-save key missing")
    return value


def wait_health():
    browser = Browser()
    for _ in range(75):
        try:
            code, text, _ = browser.get("/health")
            if code == 200 and text.strip() == "1":
                return
        except (OSError, TimeoutError):
            pass
        time.sleep(2)
    raise AssertionError("PHP-FPM failed to recover health after app restart")


def main():
    if (
        os.environ.get("GITHUB_ACTIONS") != "true"
        or os.environ.get("MUCHO_TEST_ALLOW_WRITES") != "1"
        or os.environ.get("DB_NAME") != "testgdps"
        or os.environ.get("DB_HOST") != "127.0.0.1"
        or os.environ.get("DB_PORT") != "13307"
        or os.environ.get("MUCHO_TEST_BASE_URL") != BASE
    ):
        raise RuntimeError("Refusing first-run test outside isolated GitHub Actions testgdps")
    bootstrap_file = ROOT / ".secrets" / "testgdps_admin_password"
    secret = bootstrap_file.read_text(encoding="utf-8").strip()
    if len(secret) < 10:
        raise RuntimeError("Missing isolated test-only setup secret")

    owner = "fresh_owner_ci"
    password = secrets.token_urlsafe(25) + "!8a"
    before_key = cloud_key_fingerprint()
    browser = Browser()

    code, page, headers = browser.get()
    check(code == 200 and "open the private activation link" in page
          and '<html lang="en">' in page,
          "anonymous first visitor must activate ownership before registration")
    check("Create first administrator" not in page,
          "anonymous first visitor does not see a usable registration form")
    check('data-lang="en"' in page and 'data-lang="ru"' in page,
          "first-owner page provides EN / RU language switcher")
    check("no-store" in headers.get("Cache-Control", ""),
          "bootstrap page is not cacheable")
    check(secret not in page and 'name="setup_key"' not in page,
          "activation credential never appears in a visible input or HTML")
    csrf = csrf_from(page)
    check(db_state(password)["count"] == 0,
          "startup did not silently create an administrator")

    form = {
        "csrf": csrf,
        "username": owner,
        "password": password,
        "password_confirm": password,
    }
    code, _, _ = browser.post(form)
    check(code == 403 and db_state(password)["count"] == 0,
          "anonymous first visitor cannot register an owner")

    code, page, _ = browser.post({
        "action": "activate",
        "csrf": csrf,
        "setup_key": "incorrect-fixture-setup-key",
    })
    check(code == 200 and "activation link is invalid" in page
          and db_state(password)["count"] == 0,
          "invalid activation credential cannot unlock registration")

    code, _, headers = browser.post({
        "action": "activate",
        "csrf": "not-a-valid-csrf",
        "setup_key": secret,
    })
    check(code == 403 and db_state(password)["count"] == 0,
          "invalid activation CSRF cannot unlock registration")

    code, _, headers = browser.post({
        "action": "activate",
        "csrf": csrf,
        "setup_key": secret,
    })
    check(code == 303 and headers.get("Location") == "/admin/?lang=en",
          "private activation verifies the credential and binds the browser session")

    code, page, _ = browser.get()
    check(code == 200 and "Create first administrator" in page
          and '<html lang="en">' in page
          and 'name="setup_key"' not in page,
          "activated owner sees English registration without setup-secret field")
    csrf = csrf_from(page)
    form["csrf"] = csrf

    code, _, _ = browser.post(dict(form, csrf="not-a-valid-csrf"))
    check(code == 403 and db_state(password)["count"] == 0,
          "invalid registration CSRF cannot create an administrator")

    code, page, _ = browser.post(dict(form, password_confirm="mismatch"))
    check(code == 200 and "Passwords do not match." in page
          and db_state(password)["count"] == 0,
          "mismatched passwords cannot create an administrator")

    code, page, _ = browser.get("/admin/?lang=ru")
    check(code == 200 and "Создать первого администратора" in page
          and '<html lang="ru">' in page,
          "Russian first-owner translation is available")
    code, page, _ = browser.get()
    check(code == 200 and "Создать первого администратора" in page,
          "Russian selection persists across requests")
    code, page, _ = browser.post(dict(form, password_confirm="mismatch"))
    check(code == 200 and "Пароли не совпадают." in page
          and db_state(password)["count"] == 0,
          "Russian first-owner validation errors are localized")
    code, page, _ = browser.get("/admin/?lang=en")
    check(code == 200 and "Create first administrator" in page
          and '<html lang="en">' in page,
          "English can be selected after Russian")
    code, page, _ = browser.get()
    check(code == 200 and "Create first administrator" in page,
          "English selection persists across requests")

    code, _, headers = browser.post(form)
    check(code == 303 and headers.get("Location") == "/admin/",
          "owner registration establishes a session and redirects")

    state = db_state(password)
    check(state["count"] == 1 and state["username"] == owner
          and state["role"] == "owner" and state["password_ok"]
          and state["sealed"] == 1,
          "exactly one owner is registered, hashed and permanently sealed")

    code, page, _ = browser.get()
    check(code == 200 and "Administrator sign in" not in page
          and "Create first administrator" not in page
          and "Создать первого администратора" not in page,
          "new owner is signed into the administrator dashboard")

    anonymous = Browser()
    code, page, _ = anonymous.get()
    check(code == 200 and "Administrator sign in" in page
          and "Create first administrator" not in page
          and "Создать первого администратора" not in page,
          "later visitors get login, not a second-owner registration")

    safe_run(COMPOSE + ["restart", "testgdps-app"], timeout=180)
    wait_health()
    after = db_state(password)
    check(after == state, "app restart preserves owner, password hash and setup seal")
    check(cloud_key_fingerprint() == before_key,
          "app restart preserves the cloud-save encryption key")

    sync_result = safe_run(
        COMPOSE + ["exec", "-T", "testgdps-app",
                   "php", "bin/mucho-sync-admin.php"], timeout=35,
    )
    check("credentials preserved" in sync_result.lower()
          and db_state(password) == state,
          "routine admin sync cannot overwrite registered credentials")

    anonymous = Browser()
    code, page, _ = anonymous.get()
    check(code == 200 and "Administrator sign in" in page,
          "owner credentials are required after restart")
    code, page, _ = anonymous.post({
        "username": owner, "password": "incorrect", "login": "1",
    })
    check(code == 200 and "Administrator sign in" in page,
          "wrong administrator password is rejected")
    code, _, headers = anonymous.post({
        "username": owner, "password": password, "login": "1",
    })
    check(code in (302, 303) and headers.get("Location", "").startswith("/admin/"),
          "correct owner password signs in after restart")
    code, page, _ = anonymous.get()
    check(code == 200 and "Administrator sign in" not in page,
          "authenticated dashboard is available after restart")
    check(db_state(password) == state, "repeated login never reopens first-owner setup")
    print("MUCHOCORE_PHP8_FRESH_INSTALL_HTTP_OK", flush=True)


if __name__ == "__main__":
    main()
