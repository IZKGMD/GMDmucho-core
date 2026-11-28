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


def safe_run(args, extra_env=None, timeout=90, input_text=None, expected_code=0):
    env = os.environ.copy()
    if extra_env:
        env.update(extra_env)
    result = subprocess.run(
        args, cwd=ROOT, env=env, text=True, input=input_text, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, timeout=timeout, check=False,
    )
    if result.returncode != expected_code:
        raise RuntimeError(
            "First-run check command failed (exit %s): %s" %
            (result.returncode, " ".join(args[:5]))
            + "; command output: " + (result.stderr.strip() or result.stdout.strip())[:900]
        )
    return (result.stderr if expected_code else result.stdout).strip()


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


def verify_console_recovery(command, owner, password, browser):
    new_password = secrets.token_urlsafe(24) + "!8a"
    check("already completed owner setup" in safe_run(
        command + ["create", "second_owner", "keep-2fa"],
        input_text=new_password, expected_code=1,
    ), "console creation refuses an already sealed installation")
    code, page, _ = browser.get("/admin/?emergency_recovery=1")
    check(code == 200 and "sudo mucho admin recover" in page,
          "the emergency page points at the working terminal command")
    fixture_sql = r"""
require 'vendor/autoload.php';
$db = (new \MuchoCore\Database\Database())->connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    throw new RuntimeException('Refusing non-test database');
}
$select = $db->prepare("SELECT id,totp_secret FROM admin_users WHERE username=? AND role='owner'");
$select->execute([$argv[2]]);
$owner = $select->fetch(PDO::FETCH_ASSOC);
if (!$owner) throw new RuntimeException('Missing test owner');
$id = (int)$owner['id'];
\MuchoCore\Admin\AdminEmailRecovery::ensureSchema($db);
if ($argv[1] === 'seed') {
    $db->prepare("UPDATE admin_users SET totp_secret='JBSWY3DPEHPK3PXP' WHERE id=?")->execute([$id]);
    $db->prepare('INSERT INTO admin_recovery_codes (admin_user_id,code_hash) VALUES (?,?)')
        ->execute([$id, 'fixture-backup-code-hash']);
    $db->prepare("INSERT INTO admin_passkeys
        (admin_user_id,credential_id,user_handle,credential_public_key)
        VALUES (?,'console-recovery-test','fixture','fixture-key')")->execute([$id]);
    $db->prepare('INSERT INTO mucho_admin_client_tokens (admin_user_id,token_hash,expires_at)
        VALUES (?,?,UTC_TIMESTAMP()+INTERVAL 1 DAY)')->execute([$id, str_repeat('f', 64)]);
    $db->prepare("INSERT INTO admin_email_recovery (admin_user_id,verified_email,
        verified_at, pending_email,verify_token_hash,reset_token_hash)
        VALUES (?,'owner-test@example.invalid',UTC_TIMESTAMP(),'pending@example.invalid',?,?)")
        ->execute([$id, str_repeat('a', 64), str_repeat('b', 64)]);
    exit;
}
$email = $db->query('SELECT verified_email,verify_token_hash,reset_token_hash
    FROM admin_email_recovery WHERE admin_user_id=' . $id)->fetch(PDO::FETCH_ASSOC);
echo json_encode([
    'totp' => $owner['totp_secret'] !== null,
    'passkeys' => (int)$db->query('SELECT COUNT(*) FROM admin_passkeys
        WHERE is_active=1 AND admin_user_id=' . $id)->fetchColumn(),
    'backup_codes' => (int)$db->query('SELECT COUNT(*) FROM admin_recovery_codes
        WHERE admin_user_id=' . $id)->fetchColumn(),
    'api_tokens' => (int)$db->query('SELECT COUNT(*) FROM mucho_admin_client_tokens
        WHERE revoked_at IS NULL AND admin_user_id=' . $id)->fetchColumn(),
    'verified_email' => $email['verified_email'],
    'pending_links' => $email['verify_token_hash'] !== null || $email['reset_token_hash'] !== null,
], JSON_THROW_ON_ERROR);
"""
    safe_run(["php", "-r", fixture_sql, "--", "seed", owner])
    check("No matching active owner" in safe_run(
        command + ["recover", "absent_owner", "keep-2fa"],
        input_text=new_password, expected_code=1,
    ) and db_state(password)["password_ok"], "unknown owner does not change credentials")
    check("Password updated" in safe_run(
        command + ["recover", "", "keep-2fa"], input_text=new_password,
    ), "console recovery selects the single active owner")
    code, page, _ = browser.get()
    check(code == 200 and "Administrator sign in" in page,
          "owner recovery invalidates the existing browser session")
    state = json.loads(safe_run(["php", "-r", fixture_sql, "--", "state", owner]))
    check(state == {
        "totp": True, "passkeys": 1, "backup_codes": 1, "api_tokens": 0,
        "verified_email": "owner-test@example.invalid", "pending_links": False,
    }, "normal recovery preserves 2FA and verified email while revoking tokens and links")
    check("Password updated" in safe_run(
        command + ["recover", owner, "reset-2fa"], input_text=new_password,
    ), "explicit lost-2FA recovery works")
    state = json.loads(safe_run(["php", "-r", fixture_sql, "--", "state", owner]))
    check(not state["totp"] and state["passkeys"] == 0 and state["backup_codes"] == 0,
          "lost-2FA recovery invalidates authenticator, passkeys and backup codes")
    check(not db_state(password)["password_ok"], "old password is invalid after recovery")
    code, _, headers = browser.post({"username": owner, "password": new_password, "login": "1"})
    check(code in (302, 303) and headers.get("Location", "").startswith("/admin/"),
          "owner signs in with the recovered password")


CONSOLE_OWNER_FIXTURE_RESET_PHP = r"""
require 'vendor/autoload.php';
$db = (new \MuchoCore\Database\Database())->connection();
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    throw new RuntimeException('Refusing non-test database');
}
$row = $db->query('SELECT username,role FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
if (count($row) !== 1 || $row[0]['username'] !== 'testowner'
    || $row[0]['role'] !== 'owner'
    || (int)$db->query('SELECT COUNT(*) FROM admin_initialization WHERE id=1')->fetchColumn() !== 1) {
    throw new RuntimeException('Unexpected owner fixture; refusing fixture reset');
}
$db->beginTransaction();
try {
    $db->exec("DELETE FROM admin_users WHERE username='testowner' AND role='owner'");
    $db->exec('DELETE FROM admin_initialization WHERE id=1');
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
echo 'TEST_ONLY_OWNER_FIXTURE_RESET_OK';
"""


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

    # The CLI initializer must create a real owner on the isolated testgdps DB.
    # Reset only the precisely verified disposable fixture so the rest of this
    # test can still exercise the browser activation flow independently.
    console_result = safe_run(
        COMPOSE + ["exec", "-T", "testgdps-app", "php",
                   "bin/mucho-initialize-owner.php", "--initialize"],
        timeout=30,
    )
    check("First administrator created: testowner." in console_result,
          "console installer creates the first owner")
    console_password = bootstrap_file.read_text(encoding="utf-8").strip()
    console_state = db_state(console_password)
    check(console_state["count"] == 1 and console_state["sealed"] == 1
          and console_state["username"] == "testowner"
          and console_state["role"] == "owner"
          and console_state["password_ok"],
          "console owner is password-hashed and setup is sealed")

    code, page, _ = browser.get()
    check(code == 200 and "Administrator sign in" in page
          and "Create first administrator" not in page,
          "after console install, browser goes straight to sign-in")

    reset_result = safe_run(["php", "-r", CONSOLE_OWNER_FIXTURE_RESET_PHP], timeout=20)
    check(reset_result == "TEST_ONLY_OWNER_FIXTURE_RESET_OK"
          and db_state(password)["count"] == 0,
          "isolated console fixture reset preserves browser onboarding test")

    owner_command = COMPOSE + ["exec", "-T", "testgdps-app", "php", "bin/mucho-owner-account.php"]
    check("Owner created: testowner." in safe_run(
        owner_command + ["create", "testowner", "keep-2fa"], input_text=console_password,
    ) and db_state(console_password)["sealed"] == 1,
        "interactive owner creation uses the same one-time setup seal")
    safe_run(["php", "-r", CONSOLE_OWNER_FIXTURE_RESET_PHP], timeout=20)

    code, page, headers = browser.get()
    check(code == 200 and "Open the private setup link" in page
          and '<html lang="en">' in page,
          "anonymous first visitor must activate ownership before registration")
    check("Create first administrator" not in page,
          "anonymous first visitor does not see a usable registration form")
    check('data-lang="ru"' not in page and 'class="language-switcher"' not in page,
          "first-owner page shows no language switcher")
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
    check(code == 200 and "Create first administrator" in page
          and '<html lang="en">' in page
          and "Создать первого администратора" not in page,
          "old Russian URL cannot change the first-run language")
    code, page, _ = browser.get()
    check(code == 200 and "Create first administrator" in page
          and '<html lang="en">' in page,
          "English remains stable on subsequent first-run requests")
    code, page, _ = browser.post(dict(form, password_confirm="mismatch"))
    check(code == 200 and "Passwords do not match." in page
          and db_state(password)["count"] == 0,
          "validation errors stay English after legacy Russian query")

    code, _, headers = browser.post(form)
    check(code == 303 and headers.get("Location") == "/admin/",
          "owner registration establishes a session and redirects")

    state = db_state(password)
    check(state["count"] == 1 and state["username"] == owner
          and state["role"] == "owner" and state["password_ok"]
          and state["sealed"] == 1,
          "exactly one owner is registered, hashed and permanently sealed")
    console_result = safe_run(
        COMPOSE + ["exec", "-T", "testgdps-app", "php",
                   "bin/mucho-initialize-owner.php", "--initialize"],
        timeout=30,
    )
    check("existing administrator credentials preserved" in console_result.lower()
          and db_state(password) == state,
          "VPS console installer cannot overwrite an existing owner")

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
    verify_console_recovery(owner_command, owner, password, anonymous)
    print("MUCHOCORE_PHP8_FRESH_INSTALL_HTTP_OK", flush=True)


if __name__ == "__main__":
    main()
