#!/usr/bin/env python3
"""Verify player cookies and warm GD credentials are revoked in PHP-FPM."""
import hashlib
import importlib.util
import os
from pathlib import Path
import re
import secrets
import time

spec = importlib.util.spec_from_file_location(
    "first_run", Path(__file__).with_name("php8-first-run-http.py"))
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)

FIXTURE = r"""
require 'vendor/autoload.php';
$db = (new \MuchoCore\Database\Database())->connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    throw new RuntimeException('Only testgdps is allowed');
}
$name = getenv('MC_PLAYER_SESSION_USER');
if (!preg_match('/^sesstest[a-f0-9]{8}$/D', $name)) {
    throw new RuntimeException('Invalid fixture identity');
}
$action = getenv('MC_PLAYER_SESSION_ACTION');
$rateDirectory = sys_get_temp_dir().'/mc-player-session-http-'.$name;
if ($action === 'create') {
    $db->prepare('INSERT INTO accounts(username,email,password_hash,gjp2_hash)
        VALUES (?,?,?,?)')->execute([$name,$name.'@example.invalid',
        password_hash(getenv('MC_PLAYER_SESSION_PASSWORD'),PASSWORD_DEFAULT),
        password_hash(sha1(getenv('MC_PLAYER_SESSION_PASSWORD').\MuchoCore\Account\AccountService::GJP2_SALT),PASSWORD_DEFAULT)]);
    $db->prepare('INSERT INTO profiles(account_id) VALUES (?)')->execute([(int)$db->lastInsertId()]);
} elseif ($action === 'ban' || $action === 'unban') {
    $db->prepare('UPDATE accounts SET is_banned=? WHERE username=?')->execute([$action === 'ban' ? 1 : 0,$name]);
} elseif ($action === 'password') {
    // Use a private host-side rate store: the app container owns its own store.
    $ok = (new \MuchoCore\Account\AccountManagementService($db,
        new \MuchoCore\Security\RateLimiter($rateDirectory)))->changePassword(
        $name,getenv('MC_PLAYER_SESSION_PASSWORD'),getenv('MC_PLAYER_SESSION_NEXT'),
        getenv('MC_PLAYER_SESSION_NEXT'),'127.0.0.1');
    if (!$ok) throw new RuntimeException('Fixture password change failed');
} elseif ($action === 'delete') {
    $db->prepare('DELETE FROM profiles WHERE account_id IN (SELECT account_id FROM accounts WHERE username=?)')->execute([$name]);
    $db->prepare('DELETE FROM accounts WHERE username=?')->execute([$name]);
    if (is_dir($rateDirectory)) {
        foreach (glob($rateDirectory.'/*.json') ?: [] as $file) unlink($file);
        rmdir($rateDirectory);
    }
} else {
    throw new RuntimeException('Unknown fixture action');
}
echo 'OK';
"""


def check(ok, label):
    if not ok:
        raise AssertionError("Player session HTTP: " + label)
    print("PASS " + label, flush=True)


def main():
    if not (os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("MUCHO_TEST_ALLOW_WRITES") == "1"
            and os.environ.get("DB_NAME") == "testgdps"
            and os.environ.get("DB_HOST") == "127.0.0.1"
            and os.environ.get("DB_PORT") == "13307"
            and os.environ.get("MUCHO_TEST_BASE_URL") == http.BASE):
        raise RuntimeError("Refusing player writes outside isolated CI")

    identity = "sesstest" + secrets.token_hex(4)
    password = secrets.token_hex(16)
    env = {"MC_PLAYER_SESSION_USER": identity,
           "MC_PLAYER_SESSION_PASSWORD": password,
           "MC_PLAYER_SESSION_NEXT": secrets.token_hex(16)}

    def fixture(action):
        result = http.safe_run(["php", "-r", FIXTURE],
                               {**env, "MC_PLAYER_SESSION_ACTION": action})
        check(result == "OK", "isolated fixture " + action)

    def login():
        browser = http.Browser()
        code, page, _ = browser.get("/dashboard/")
        check(code == 200, "dashboard login page loads")
        token = re.search(r'name="antibot_token" value="([a-f0-9]+)"', page)
        check(token is not None, "login has an anti-bot token")
        time.sleep(1.1)
        code, _, _ = browser.post({"action": "login", "username": identity,
                                  "password": password, "csrf": http.csrf_from(page),
                                  "antibot_token": token.group(1), "website": ""},
                                 "/dashboard/")
        check(code == 302, "dashboard accepts login submission")
        code, page, _ = browser.get("/dashboard/")
        check(code == 200 and 'name="action" value="logout"' in page,
              "real login issues a working player cookie")
        return browser

    def signed_out(browser, path):
        code, page, _ = browser.get(path)
        if path == "/dashboard/":
            return code == 200 and 'name="action" value="login"' in page
        return code == 200 and 'name="clanName"' not in page

    def game_post(path, **fields):
        code, body, _ = http.Browser().post(
            {"gameVersion": 22, "binaryVersion": 51, **fields}, "/database/" + path
        )
        check(code == 200, "GD HTTP transport")
        return body.strip()

    def gjp2(secret):
        return hashlib.sha1((secret + "mI29fmAnxgTs").encode()).hexdigest()

    def game_login(**credentials):
        return game_post("accounts/loginGJAccount.php", userName=identity, **credentials)

    def game_settings(credential):
        return game_post("updateGJAccSettings20.php", accountID=account_id, gjp2=credential)

    try:
        fixture("create")
        old_gjp2 = gjp2(password)
        game_identity = game_login(gjp2=old_gjp2)
        check(re.fullmatch(r"[1-9][0-9]*,[1-9][0-9]*", game_identity), "GD login returns both IDs")
        account_id = int(game_identity.split(",")[0])
        dashboard, clans = login(), login()
        code, page, _ = clans.get("/dashboard/clans.php")
        check(code == 200 and 'name="clanName"' in page, "current cookie permits clan management")
        check(game_login(gjp2=old_gjp2) == game_identity, "repeated GJP2 login works")
        check(game_settings(old_gjp2) == "1", "protected endpoint accepts current GJP2")
        check(game_settings(gjp2(secrets.token_hex(16))) == "-1", "warm cache rejects a different credential")
        fixture("ban")
        check(signed_out(dashboard, "/dashboard/"), "banned player loses dashboard access")
        check(signed_out(clans, "/dashboard/clans.php"), "banned player loses clan management")
        check(game_login(gjp2=old_gjp2) == "-12", "ban still blocks warm GJP2 login")
        check(game_settings(old_gjp2) == "-1", "ban still blocks warm protected requests")
        fixture("unban")
        dashboard, clans = login(), login()
        check(game_login(gjp2=old_gjp2) == game_identity, "unbanned player can log in again")
        check(game_login(password=password) == game_identity, "GD password login works")
        fixture("password")
        check(signed_out(dashboard, "/dashboard/"), "password change revokes dashboard cookie")
        check(signed_out(clans, "/dashboard/clans.php"), "password change revokes clan cookie")
        check(game_login(gjp2=old_gjp2) == "-1", "password change revokes warm GJP2 login")
        check(game_login(password=password) == "-1", "password change revokes warm password login")
        check(game_settings(old_gjp2) == "-1", "password change revokes warm protected requests")
        password = env["MC_PLAYER_SESSION_NEXT"]
        check(game_settings(gjp2(password)) == "1", "protected endpoint accepts replacement GJP2")
        check(game_login(password=password) == game_identity, "replacement password preserves GD identity")
        login()
        print("PLAYER_SESSION_HTTP_OK", flush=True)
    finally:
        fixture("delete")


if __name__ == "__main__":
    main()
