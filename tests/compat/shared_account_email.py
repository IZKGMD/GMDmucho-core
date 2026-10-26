"""Registration-to-recovery HTTP checks; mail stays in the disposable fixture."""
import hashlib
import json
import re
import urllib.error
import urllib.parse
import urllib.request


def check_email_recovery(post, fixture, root, port, require):
    username, email = "P0MailUser", "recover-fixture@example.invalid"
    original, replacement = "MailFixture-original!", "MailFixture-recovered!"
    registration = dict(userName=username, password=original, email=email, gameVersion=22, binaryVersion=51)
    count = fixture("inspect")["accounts"]
    for bad_email in (None, "", "not-an-email", "x" * 255 + "@example.invalid"):
        invalid = dict(registration)
        if bad_email is None:
            invalid.pop("email")
            invalid["email[]"] = "invalid"
        else:
            invalid["email"] = bad_email
        require(post("/a/accounts/registerGJAccount22.php", invalid) == "-1", "Registration accepted invalid recovery email")
    require(fixture("inspect")["accounts"] == count, "Invalid recovery email created an account")
    require(post("/a/accounts/registerGJAccount22.php", dict(registration, email=" " + email + " ")) == "1",
            "Registration with a recovery email failed")
    require(fixture("email-recovery-inspect")["email"] == email, "Registration did not persist the recovery email")
    require(post("/a/accounts/registerGJAccount22.php", dict(registration, userName="P0MailDuplicate", email=email.upper())) == "-3",
            "Duplicate recovery email was accepted")
    require(fixture("inspect")["accounts"] == count + 1, "Duplicate email created an account")
    login = dict(userName=username, password=original, udid="mail-fixture-device", gameVersion=22, binaryVersion=51)
    account_pair = post("/a/accounts/loginGJAccount22.php", login)
    require(re.fullmatch(r"[1-9][0-9]*,[1-9][0-9]*", account_pair) is not None, "Registered player cannot sign in")

    cookies = ""
    recovery_path = "/mc/accounts/lostpassword.php"

    def browser(data=None, path=recovery_path, allowed_status=(200,)):
        nonlocal cookies
        request = urllib.request.Request("http://127.0.0.1:%d%s" % (port, path),
            data=None if data is None else urllib.parse.urlencode(data).encode(),
            headers={"Cookie": cookies, "Host": "untrusted-host.invalid"})
        try:
            response = urllib.request.urlopen(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            page = response.read().decode()
            require(response.status in allowed_status, "Recovery HTTP request failed")
            for cookie in response.headers.get_all("Set-Cookie", []):
                if cookie.startswith("MUCHO_RECOVERY="):
                    require("HttpOnly" in cookie and "secure" in cookie.lower(), "Recovery session cookie is not secure")
                    cookies = cookie.split(";", 1)[0]
            if page != "-1":
                require(page.startswith("<!doctype html>") and '<html lang="en">' in page,
                        "Recovery returned a non-English or contaminated page")
                require(response.headers.get_content_type() == "text/html", "Recovery page MIME type")
                require("no-store" in response.headers.get("Cache-Control", ""), "Recovery page cached")
                require(not any(part in page for part in ("SQLSTATE", "Warning:", "Fatal error", original, replacement)),
                        "Recovery exposed diagnostics or passwords")
            return page

    def csrf(page):
        match = re.search(r'name="csrf" value="([a-f0-9]{64})"', page)
        require(match is not None, "Recovery form CSRF missing")
        return match.group(1)

    def outbox():
        path = root / "config/mail-outbox.jsonl"
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def link_and_token(mail):
        require(mail["to"] == email, "Recovery mail sent to an address other than the registered email")
        match = re.search(r'https://[^\s<"]+\?token=([a-f0-9]{64})', mail["body"])
        require(match is not None, "Recovery email has no reset link")
        url, token = urllib.parse.urlsplit(match.group(0)), match.group(1)
        require(url.scheme == "https" and url.netloc == "fixture.invalid"
                and url.path == "/mc/api/api/accounts/lostusername.php", "Recovery link lost its configured origin/mount")
        require("untrusted-host.invalid" not in mail["body"], "Recovery link trusted the HTTP Host header")
        return url.path + "?" + url.query, token

    page = browser()
    request = dict(action="request", csrf=csrf(page), identity=email)
    require("page has expired" in browser(dict(request, csrf="wrong")), "Recovery accepted invalid CSRF")
    require(outbox() == [], "Invalid recovery CSRF sent mail")
    browser(dict(request, **{"csrf[]": "bad"}, csrf=""))
    require("Check your email" in browser(request), "Email-based recovery request failed")
    require(len(outbox()) == 1, "Recovery email was not passed to the mail transport")
    first_path, first_token = link_and_token(outbox()[0])
    state = fixture("email-recovery-inspect")
    require(state["token_hashes"] == [hashlib.sha256(first_token.encode()).hexdigest()]
            and 0 < state["ttl_seconds"] <= 1800, "Recovery token storage or expiration is invalid")
    require("Check your email" in browser(dict(request, identity="absent-fixture@example.invalid")), "Unknown email revealed account existence")
    require("Check your email" in browser(dict(request, identity="P0Banned")), "Banned account revealed recovery state")
    require(len(outbox()) == 1, "Unknown or banned account received a recovery email")
    require("Check your email" in browser(dict(request, identity=username)), "Username-based recovery failed")
    require(len(outbox()) == 2, "Username recovery did not use the registered email")
    second_path, second_token = link_and_token(outbox()[1])
    require("Invalid link" in browser(path=first_path), "A superseded recovery link remained valid")

    page = browser(path=second_path)
    reset = dict(action="reset", csrf=csrf(page), token=second_token,
                 password=replacement, password_confirmation=replacement)
    for invalid in (dict(reset, password_confirmation="different"),
                    dict(reset, password="short", password_confirmation="short"),
                    dict(reset, password="x" * 73, password_confirmation="x" * 73),
                    dict(reset, password="null\0password", password_confirmation="null\0password")):
        page = browser(invalid, second_path)
        require('name="password_confirmation"' in page and 'name="token"' in page and "Invalid link" not in page,
                "Invalid new password discarded a valid recovery form")
    fixture("read-only")
    require(browser(reset, second_path, allowed_status=(503,)) == "-1", "Read-only server allowed password recovery")
    fixture("writable")
    require("Password changed" in browser(reset, second_path), "Email recovery did not change the password")
    require(fixture("email-recovery-inspect")["sessions"] == 0, "Recovery did not revoke legacy upload sessions")
    require(post("/a/accounts/loginGJAccount22.php", login) == "-1", "Old password still signs in after recovery")
    require(post("/a/accounts/loginGJAccount22.php", dict(login, password=replacement)) == account_pair,
            "Recovered password cannot sign in")
    old_gjp2 = hashlib.sha1((original + "mI29fmAnxgTs").encode()).hexdigest()
    new_gjp2 = hashlib.sha1((replacement + "mI29fmAnxgTs").encode()).hexdigest()
    require(post("/a/accounts/loginGJAccount22.php", dict(login, password="", gjp2=old_gjp2)) == "-1", "Old GJP2 still signs in after recovery")
    require(post("/a/accounts/loginGJAccount22.php", dict(login, password="", gjp2=new_gjp2)) == account_pair,
            "Recovered GJP2 cannot sign in")
    require("Invalid link" in browser(reset, second_path), "A used recovery token was replayed")
    require("Check your email" in browser(request), "New recovery request failed")
    require(len(outbox()) == 3, "New recovery request did not send mail")
    expired_path, unused = link_and_token(outbox()[2])
    fixture("email-recovery-expire")
    require("Invalid link" in browser(path=expired_path), "Expired recovery link accepted")
    require('name="identity"' in browser(path=recovery_path + "?token%5B%5D=invalid"), "Array-valued recovery token caused an error")
    return "registration-email/persist/duplicate/mail-capture/csrf/username/host/mount/token-hash/expiry/retry/read-only/replay/password-gjp2/session-revocation"
