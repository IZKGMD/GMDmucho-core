#!/usr/bin/env python3
"""Isolated end-to-end PHP 8 administrator recovery acceptance.

Uses a local STARTTLS + AUTH PLAIN SMTP fixture, disposable testgdps MariaDB,
and the existing PHP-FPM/Caddy HTTP test tenant. Never contacts a real mailbox.
"""
import base64
import email
from email import policy
import hmac
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socketserver
import ssl
import struct
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import hashlib

ROOT = Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:18080"
DRIVER = "tests/integration/admin-email-recovery-ci.php"


def check(value, description):
    if not value:
        raise AssertionError("Admin email recovery E2E: " + description)
    print("PASS " + description, flush=True)


class Mailbox(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self, certificate, key, username, password):
        super().__init__(("127.0.0.1", 0), SMTPHandler)
        self.context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        self.context.load_cert_chain(str(certificate), str(key))
        self.auth = b"\0" + username.encode() + b"\0" + password.encode()
        self.messages = []
        self.lock = threading.Lock()

    def snapshot(self):
        with self.lock:
            return list(self.messages)


class SMTPHandler(socketserver.BaseRequestHandler):
    def handle(self):
        sock = self.request
        sock.settimeout(10)
        reader = sock.makefile("rb")
        secure = False
        authenticated = False
        recipient = ""
        try:
            sock.sendall(b"220 localhost MuchoCore isolated test SMTP\r\n")
            while True:
                raw = reader.readline(16384)
                if not raw or len(raw) >= 16384:
                    return
                command = raw.decode("ascii", "replace").strip()
                verb = command.split(" ", 1)[0].upper()
                if verb == "EHLO":
                    sock.sendall(
                        b"250-localhost\r\n"
                        + (b"250-STARTTLS\r\n" if not secure else b"")
                        + b"250 AUTH PLAIN\r\n"
                    )
                elif verb == "STARTTLS" and not secure:
                    sock.sendall(b"220 Begin TLS\r\n")
                    reader.close()
                    sock = self.server.context.wrap_socket(sock, server_side=True)
                    reader = sock.makefile("rb")
                    secure = True
                    authenticated = False
                elif verb == "AUTH":
                    if not secure:
                        sock.sendall(b"530 Encryption required\r\n")
                        continue
                    try:
                        encoded = command.split(" ", 2)[2]
                        offered = base64.b64decode(encoded, validate=True)
                    except (IndexError, ValueError):
                        sock.sendall(b"535 Invalid credentials\r\n")
                        continue
                    authenticated = hmac.compare_digest(offered, self.server.auth)
                    sock.sendall(b"235 Authenticated\r\n" if authenticated
                                 else b"535 Invalid credentials\r\n")
                elif verb == "MAIL":
                    sock.sendall(b"250 OK\r\n" if authenticated else b"530 Auth required\r\n")
                elif verb == "RCPT":
                    if not authenticated:
                        sock.sendall(b"530 Auth required\r\n")
                        continue
                    recipient = command[8:].strip()
                    sock.sendall(b"250 OK\r\n")
                elif verb == "DATA":
                    if not authenticated or not recipient:
                        sock.sendall(b"530 Auth required\r\n")
                        continue
                    sock.sendall(b"354 End with dot\r\n")
                    lines = []
                    total = 0
                    while True:
                        line = reader.readline(65536)
                        if not line or len(line) >= 65536:
                            return
                        if line in (b".\r\n", b".\n"):
                            break
                        if line.startswith(b".."):
                            line = line[1:]
                        lines.append(line)
                        total += len(line)
                        if total > 131072:
                            return
                    raw_message = b"".join(lines)
                    with self.server.lock:
                        self.server.messages.append(
                            {"raw": raw_message, "recipient": recipient, "tls": secure}
                        )
                    sock.sendall(b"250 Queued\r\n")
                    recipient = ""
                elif verb == "QUIT":
                    sock.sendall(b"221 Bye\r\n")
                    return
                else:
                    sock.sendall(b"502 Unknown command\r\n")
        except (OSError, ssl.SSLError):
            pass
        finally:
            reader.close()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class Browser:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
            NoRedirect(),
        )

    def request(self, path="/admin/", fields=None):
        if not path.startswith("/") or path.startswith("//"):
            raise RuntimeError("Only local relative HTTP paths allowed")
        payload = (urllib.parse.urlencode(fields).encode("utf-8")
                   if fields is not None else None)
        request = urllib.request.Request(
            BASE + path, data=payload,
            headers={"Host": "localhost"},
            method="POST" if payload is not None else "GET",
        )
        try:
            result = self.opener.open(request, timeout=25)
        except urllib.error.HTTPError as error:
            result = error
        with result:
            return result.status, result.read().decode("utf-8", "replace"), dict(result.headers)


def admin_api(payload, token=None):
    """Exercise the real PHP8 admin Bearer endpoint on isolated localhost."""
    headers = {"Host": "localhost", "Content-Type": "application/json"}
    if token is not None:
        headers["Authorization"] = "Bearer " + token
    request = urllib.request.Request(
        BASE + "/api/v2/admin-client.php",
        data=json.dumps(payload).encode("utf-8"), headers=headers, method="POST",
    )
    try:
        response = urllib.request.urlopen(request, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, json.loads(response.read().decode("utf-8"))


class Csrf(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and attrs.get("name") == "csrf":
            self.value = attrs.get("value")


def csrf(html):
    parser = Csrf()
    parser.feed(html)
    if not parser.value or len(parser.value) < 40:
        raise AssertionError("No CSRF token in email page")
    return parser.value


def totp(secret):
    key = base64.b32decode(secret)
    counter = struct.pack(">Q", int(time.time()) // 30)
    mac = hmac.new(key, counter, hashlib.sha1).digest()
    offset = mac[-1] & 15
    return str((int.from_bytes(mac[offset:offset + 4], "big") & 0x7fffffff) % 1000000).zfill(6)


def received(mailbox, position, subject, key):
    items = mailbox.snapshot()
    check(len(items) > position, "mail was accepted by the local SMTP server")
    item = items[position]
    check(item["tls"], "mail was delivered only after STARTTLS")
    check(item["recipient"] == "<" + mailbox.expected_recipient + ">",
          "SMTP recipient matches the test mailbox")
    parsed = email.message_from_bytes(item["raw"], policy=policy.default)
    check(parsed["Subject"] == subject and parsed["Auto-Submitted"] == "auto-generated",
          "mail includes the expected subject and automated headers")
    body = parsed.get_content()
    urls = re.findall(r"https://gdps\.example\.test/admin/\?" + key + r"=([a-f0-9]{64})", body)
    check(len(urls) == 1, "mail contains one link at the trusted configured HTTPS origin")
    return urls[0]


def run(argv, env, timeout=35):
    proc = subprocess.run(argv, cwd=ROOT, env=env, capture_output=True,
                          text=True, timeout=timeout, check=False)
    if proc.returncode:
        raise RuntimeError("Isolated email recovery command failed (" + str(proc.returncode)
                           + "): " + " ".join(argv[:3]) + "\n" + proc.stderr[-1600:])
    return proc.stdout.strip()


def main():
    if not (os.environ.get("GITHUB_ACTIONS") == "true" and
            os.environ.get("MUCHO_TEST_ALLOW_WRITES") == "1" and
            os.environ.get("DB_HOST") == "127.0.0.1" and
            os.environ.get("DB_PORT") == "13307" and
            os.environ.get("DB_NAME") == "testgdps" and
            os.environ.get("MUCHO_TEST_BASE_URL") == BASE):
        raise RuntimeError("Refusing SMTP recovery test outside disposable GitHub Actions stack")

    env = os.environ.copy()
    env.update({
        "MC_RECOVERY_TEST_USERNAME": "mc_mail_ci_" + secrets.token_hex(6),
        "MC_RECOVERY_TEST_PASSWORD": secrets.token_urlsafe(22) + "!A",
        "MC_RECOVERY_TEST_NEW_PASSWORD": secrets.token_urlsafe(23) + "!B",
        "MC_RECOVERY_TEST_EMAIL": "recovery-" + secrets.token_hex(6) + "@example.test",
        "MUCHO_ADMIN_SMTP_HOST": "127.0.0.1",
        "MUCHO_ADMIN_SMTP_USER": "muchocore-ci-mail",
        "MUCHO_ADMIN_SMTP_PASSWORD": secrets.token_urlsafe(24),
        "MUCHO_ADMIN_MAIL_FROM": "muchocore-ci@example.test",
        "MUCHO_ADMIN_MAIL_ORIGIN": "https://gdps.example.test",
        "MUCHO_ADMIN_SMTP_SECURITY": "starttls",
    })
    # The self-signed certificate is a disposable trust anchor for this CI
    # process only. Neither the test key nor the test CA is ever deployed.
    with tempfile.TemporaryDirectory(prefix="muchocore-mail-ci-") as directory:
        certificate = Path(directory, "smtp-test.crt")
        private_key = Path(directory, "smtp-test.key")
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
            "-days", "1", "-keyout", str(private_key), "-out", str(certificate),
            "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=25)
        # PHP CLI accesses the Docker-generated vendor tree via sudo in
        # this disposable runner. The test driver restricts all DB writes to
        # its generated testgdps admin identity. Never expose the private key.
        os.chmod(directory, 0o755)
        os.chmod(certificate, 0o644)
        os.chmod(private_key, 0o600)

        with Mailbox(certificate, private_key, env["MUCHO_ADMIN_SMTP_USER"],
                     env["MUCHO_ADMIN_SMTP_PASSWORD"]) as mailbox:
            mailbox.expected_recipient = env["MC_RECOVERY_TEST_EMAIL"]
            env["MUCHO_ADMIN_SMTP_PORT"] = str(mailbox.server_address[1])
            server_thread = threading.Thread(target=mailbox.serve_forever, daemon=True)
            server_thread.start()

            def php(action):
                return run([
                    "sudo", "-E", "php", "-d",
                    "openssl.cafile=" + str(certificate), DRIVER, action,
                ], env)

            fixture_created = False
            try:
                check(php("create") == "CREATED", "disposable administrator created only in testgdps")
                fixture_created = True

                old = Browser()
                code, _, headers = old.request(fields={
                    "username": env["MC_RECOVERY_TEST_USERNAME"],
                    "password": env["MC_RECOVERY_TEST_PASSWORD"], "login": "1",
                })
                check(code in (302, 303) and headers.get("Location", "").startswith("/admin/"),
                      "existing administrator signs in before password recovery")
                code, page, _ = old.request()
                check(code == 200 and "Administrator sign in" not in page,
                      "pre-reset authenticated session is valid")

                # Public email recovery gives an indistinguishable result for
                # an address that has not been verified yet.
                public = Browser()
                code, page, _ = public.request("/admin/?email_recovery=1")
                check(code == 200 and "Forgot your password?" in page,
                      "public recovery form is accessible")
                token = csrf(page)
                code, page, _ = public.request(fields={
                    "csrf": token, "action": "admin-email-recover",
                    "email": env["MC_RECOVERY_TEST_EMAIL"],
                })
                check(code == 200 and "Check your inbox" in page
                      and len(mailbox.snapshot()) == 0,
                      "unverified email gives generic response without sending a link")

                check(php("bind") == "SENT", "verification message accepted over authenticated SMTP")
                verification1 = received(mailbox, 0, "MuchoCore admin email verification",
                                         "email_verify")
                state = json.loads(php("state"))
                check(state["verified_email"] is None and
                      state["verify_token_hash"] == hashlib.sha256(verification1.encode()).hexdigest()
                      and 0 < int(state["verify_ttl"]) <= 1800,
                      "verification link is SHA-256 hashed, expiring and not pre-verified")
                php("expire-verify")
                check(int(json.loads(php("state"))["verify_ttl"]) < 0,
                      "verification link has been expired in the disposable database")
                code, page, _ = public.request("/admin/?email_verify=" + verification1)
                check(code == 200 and "Confirm recovery email" in page,
                      "verification link requires an explicit CSRF-protected confirmation")
                code, page, _ = public.request(fields={
                    "csrf": csrf(page), "action": "admin-email-verify", "token": verification1,
                })
                check(code == 200 and "Link unavailable" in page,
                      "expired verification link cannot confirm an address")

                check(php("bind") == "SENT", "new verification request replaces expired token")
                verification2 = received(mailbox, 1, "MuchoCore admin email verification",
                                         "email_verify")
                check(verification2 != verification1, "new verification has a distinct token")
                code, page, _ = public.request("/admin/?email_verify=" + verification2)
                csrf_verify = csrf(page)
                code, page, _ = public.request(fields={
                    "csrf": csrf_verify, "action": "admin-email-verify", "token": verification2,
                })
                check(code == 200 and "Email confirmed" in page, "email confirmation succeeded")
                state = json.loads(php("state"))
                check(state["verified_email"] == env["MC_RECOVERY_TEST_EMAIL"] and
                      state["verify_token_hash"] is None and state["pending_email"] is None,
                      "verified email is stored without a reusable verification token")
                code, page, _ = public.request(fields={
                    "csrf": csrf_verify, "action": "admin-email-verify", "token": verification2,
                })
                check(code == 200 and "Link unavailable" in page,
                      "verification token cannot be replayed")

                php("request-reset")
                reset1 = received(mailbox, 2, "MuchoCore admin password reset", "email_reset")
                state = json.loads(php("state"))
                check(state["reset_token_hash"] == hashlib.sha256(reset1.encode()).hexdigest()
                      and 0 < int(state["reset_ttl"]) <= 1200,
                      "reset token stored hashed with a twenty-minute expiry")
                php("request-reset")
                check(len(mailbox.snapshot()) == 3,
                      "reset resend cooldown prevents immediate duplicate mail")

                php("set-totp")
                api_status, api_response = admin_api({
                    "action": "auth.login",
                    "username": env["MC_RECOVERY_TEST_USERNAME"],
                    "password": env["MC_RECOVERY_TEST_PASSWORD"],
                    "totp": totp("JBSWY3DPEHPK3PXP"),
                })
                check(api_status == 200 and api_response.get("ok") and
                      isinstance(api_response.get("token"), str),
                      "real admin API bearer session is issued before recovery")
                old_bearer = api_response["token"]
                api_status, api_response = admin_api({"action": "auth.me"}, old_bearer)
                check(api_status == 200 and api_response.get("ok"),
                      "pre-reset bearer token authenticates against the real API")

                code, page, _ = public.request("/admin/?email_reset=" + reset1)
                check(code == 200 and "Set a new password" in page,
                      "public reset link opens a CSRF-protected password form")
                csrf_reset = csrf(page)
                code, page, _ = public.request(fields={
                    "csrf": csrf_reset, "action": "admin-email-reset",
                    "token": reset1, "password": env["MC_RECOVERY_TEST_NEW_PASSWORD"],
                    "confirmation": "mismatched-confirmation",
                })
                check(code == 200 and "Could not reset password" in page and
                      json.loads(php("state"))["password_initial_ok"],
                      "mismatched confirmation cannot change the password")
                code, page, _ = public.request(fields={
                    "csrf": csrf_reset, "action": "admin-email-reset", "token": reset1,
                    "password": env["MC_RECOVERY_TEST_NEW_PASSWORD"],
                    "confirmation": env["MC_RECOVERY_TEST_NEW_PASSWORD"],
                })
                check(code == 200 and "Password changed" in page,
                      "verified one-time link changes the administrator password")
                state = json.loads(php("state"))
                check(state["password_new_ok"] and not state["password_initial_ok"] and
                      state["reset_token_hash"] is None and
                      state["totp_secret"] == "JBSWY3DPEHPK3PXP",
                      "password hash rotates, reset token clears and 2FA stays enabled")
                api_status, api_response = admin_api({"action": "auth.me"}, old_bearer)
                check(api_status == 401 and api_response.get("error") == "unauthorized",
                      "real admin API rejects all bearer sessions after password reset")

                code, page, _ = public.request(fields={
                    "csrf": csrf_reset, "action": "admin-email-reset", "token": reset1,
                    "password": env["MC_RECOVERY_TEST_PASSWORD"],
                    "confirmation": env["MC_RECOVERY_TEST_PASSWORD"],
                })
                check(code == 200 and "Could not reset password" in page,
                      "password reset token cannot be used twice")

                code, page, _ = old.request()
                check(code == 200 and "Administrator sign in" in page,
                      "pre-reset authenticated session is invalidated")
                logged = Browser()
                code, page, _ = logged.request(fields={
                    "username": env["MC_RECOVERY_TEST_USERNAME"],
                    "password": env["MC_RECOVERY_TEST_PASSWORD"], "login": "1",
                    "otp": totp("JBSWY3DPEHPK3PXP"),
                })
                check(code == 200 and "Administrator sign in" in page,
                      "old password cannot be used to sign in")
                code, _, headers = logged.request(fields={
                    "username": env["MC_RECOVERY_TEST_USERNAME"],
                    "password": env["MC_RECOVERY_TEST_NEW_PASSWORD"], "login": "1",
                    "otp": totp("JBSWY3DPEHPK3PXP"),
                })
                check(code in (302, 303) and headers.get("Location", "").startswith("/admin/"),
                      "new password still requires and accepts configured TOTP")

                php("request-reset")
                reset2 = received(mailbox, 3, "MuchoCore admin password reset", "email_reset")
                php("expire-reset")
                code, page, _ = public.request("/admin/?email_reset=" + reset2)
                code, page, _ = public.request(fields={
                    "csrf": csrf(page), "action": "admin-email-reset",
                    "token": reset2, "password": env["MC_RECOVERY_TEST_PASSWORD"],
                    "confirmation": env["MC_RECOVERY_TEST_PASSWORD"],
                })
                check(code == 200 and "Could not reset password" in page and
                      json.loads(php("state"))["password_new_ok"],
                      "expired reset link cannot restore the old password")

                print("MUCHOCORE_PHP8_ADMIN_EMAIL_RECOVERY_E2E_OK", flush=True)
            finally:
                if fixture_created:
                    check(php("cleanup") == "CLEANED",
                          "disposable administrator and recovery records cleaned up")
                mailbox.shutdown()
                server_thread.join(timeout=4)


if __name__ == "__main__":
    main()
